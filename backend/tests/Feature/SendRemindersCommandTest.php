<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\SendReservationReminderJob;
use App\Models\Customer;
use App\Models\LineSetting;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use App\Repositories\ReservationRepository;
use App\Services\Line\LineClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_reminder_and_records_sent_at(): void
    {
        Http::fake();
        $reservation = $this->createReminderTarget();

        $this->artisan('reservations:send-reminders')->assertSuccessful();

        $this->assertNotNull($reservation->fresh()->reminder_sent_at);

        Http::assertSent(function ($request) use ($reservation) {
            return str_contains($request->url(), '/v2/bot/message/push')
                && $request->hasHeader('Authorization', 'Bearer '.$reservation->salon->lineSetting->channel_access_token)
                && $request['to'] === $reservation->customer->line_user_id
                && str_contains($request['messages'][0]['text'], '明日のご予約');
        });
    }

    public function test_sends_each_reminder_with_its_own_salon_token(): void
    {
        Http::fake();
        $first = $this->createReminderTarget();
        $second = $this->createReminderTarget();

        $this->artisan('reservations:send-reminders')->assertSuccessful();

        Http::assertSentCount(2);

        // 各 push は自サロンのトークン・自顧客宛てで送られる（テナント間の混線なし）
        foreach ([$first, $second] as $reservation) {
            Http::assertSent(function ($request) use ($reservation) {
                return str_contains($request->url(), '/v2/bot/message/push')
                    && $request->hasHeader('Authorization', 'Bearer '.$reservation->salon->lineSetting->channel_access_token)
                    && $request['to'] === $reservation->customer->line_user_id;
            });
        }
    }

    public function test_dispatches_jobs_only_for_target_reservations(): void
    {
        Queue::fake();

        $target = $this->createReminderTarget();

        // 対象外: 翌日でない（明後日）
        $this->createReminderTarget(startAt: Carbon::tomorrow(config('app.salon_timezone'))->addDay()->setTime(10, 0));

        // 対象外: キャンセル済み
        $cancelled = $this->createReminderTarget();
        $cancelled->update(['status' => ReservationStatus::Cancelled]);

        // 対象外: 顧客がLINE未連携
        $unlinked = $this->createReminderTarget();
        $unlinked->customer->update(['line_user_id' => null, 'line_linked_at' => null]);

        // 対象外: サロンのLINE連携が無効
        $inactive = $this->createReminderTarget();
        $inactive->salon->lineSetting->update(['is_active' => false]);

        // 対象外: 連携解除済み（line_settings なし）のサロン
        $disconnected = $this->createReminderTarget();
        $disconnected->salon->lineSetting->delete();

        // 対象外: 送信済み
        $sent = $this->createReminderTarget();
        $sent->update(['reminder_sent_at' => now()]);

        $this->artisan('reservations:send-reminders')->assertSuccessful();

        Queue::assertPushed(SendReservationReminderJob::class, 1);
        Queue::assertPushed(
            SendReservationReminderJob::class,
            fn (SendReservationReminderJob $job) => $job->reservationId === $target->id,
        );
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        Http::fake();
        $this->createReminderTarget();

        $this->artisan('reservations:send-reminders')->assertSuccessful();
        $this->artisan('reservations:send-reminders')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_unique_job_is_dispatched_once_when_command_reruns_before_processing(): void
    {
        Queue::fake();
        $this->createReminderTarget();

        // ジョブ処理前の再実行では ShouldBeUnique の lock により二重投入されない
        $this->artisan('reservations:send-reminders')->assertSuccessful();
        $this->artisan('reservations:send-reminders')->assertSuccessful();

        Queue::assertPushed(SendReservationReminderJob::class, 1);
    }

    public function test_job_skips_when_reservation_cancelled_before_sending(): void
    {
        Http::fake();
        $reservation = $this->createReminderTarget();
        $reservation->update(['status' => ReservationStatus::Cancelled]);

        $this->runJob($reservation);

        Http::assertNothingSent();
        $this->assertNull($reservation->fresh()->reminder_sent_at);
    }

    public function test_job_skips_when_customer_unlinked_before_sending(): void
    {
        Http::fake();
        $reservation = $this->createReminderTarget();
        $reservation->customer->update(['line_user_id' => null, 'line_linked_at' => null]);

        $this->runJob($reservation);

        Http::assertNothingSent();
    }

    public function test_429_aborts_without_recording_sent_at(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/message/push' => Http::response(['message' => 'limit'], 429),
        ]);
        $reservation = $this->createReminderTarget();

        $this->artisan('reservations:send-reminders')->assertSuccessful();

        // 429 は恒久エラーとしてリトライせず、送信済みも記録しない
        Http::assertSentCount(1);
        $this->assertNull($reservation->fresh()->reminder_sent_at);
    }

    private function createReminderTarget(?Carbon $startAt = null): Reservation
    {
        $salon = Salon::factory()->create();
        LineSetting::factory()->for($salon)->create();

        $customer = Customer::factory()->for($salon)->create([
            'line_user_id' => 'U-'.fake()->unique()->md5(),
            'line_linked_at' => now(),
        ]);
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);
        $staff = User::factory()->for($salon)->create();

        $start = ($startAt ?? Carbon::tomorrow(config('app.salon_timezone'))->setTime(10, 0))->utc();

        return Reservation::factory()->for($salon)->create([
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $staff->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(60),
            'status' => ReservationStatus::Reserved,
        ]);
    }

    private function runJob(Reservation $reservation): void
    {
        (new SendReservationReminderJob($reservation->id))->handle(
            app(ReservationRepository::class),
            app(LineClient::class),
        );
    }
}
