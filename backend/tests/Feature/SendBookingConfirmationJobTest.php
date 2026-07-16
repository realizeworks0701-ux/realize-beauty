<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\SendBookingConfirmationJob;
use App\Models\Customer;
use App\Models\LineSetting;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use App\Repositories\ReservationRepository;
use App\Services\Line\LineClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendBookingConfirmationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_confirmation_push_with_own_salon_token(): void
    {
        Http::fake();
        $reservation = $this->createConfirmationTarget();

        $this->runJob($reservation);

        Http::assertSent(function ($request) use ($reservation) {
            return str_contains($request->url(), '/v2/bot/message/push')
                && $request->hasHeader('Authorization', 'Bearer '.$reservation->salon->lineSetting->channel_access_token)
                && $request['to'] === $reservation->customer->line_user_id
                && str_contains($request['messages'][0]['text'], 'ご予約を承りました');
        });
    }

    public function test_skips_when_reservation_cancelled_before_sending(): void
    {
        Http::fake();
        $reservation = $this->createConfirmationTarget();
        $reservation->update(['status' => ReservationStatus::Cancelled]);

        $this->runJob($reservation);

        Http::assertNothingSent();
    }

    public function test_skips_when_line_setting_is_inactive(): void
    {
        Http::fake();
        $reservation = $this->createConfirmationTarget();
        $reservation->salon->lineSetting->update(['is_active' => false]);

        $this->runJob($reservation);

        Http::assertNothingSent();
    }

    public function test_skips_when_line_setting_is_deleted(): void
    {
        Http::fake();
        $reservation = $this->createConfirmationTarget();
        $reservation->salon->lineSetting->delete();

        $this->runJob($reservation);

        Http::assertNothingSent();
    }

    public function test_429_fails_job_without_retry(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/message/push' => Http::response(['message' => 'limit'], 429),
        ]);
        $reservation = $this->createConfirmationTarget();

        $job = (new SendBookingConfirmationJob($reservation->id))->withFakeQueueInteractions();
        $job->handle(app(ReservationRepository::class), app(LineClient::class));

        // 429 は恒久エラーとしてリトライせず fail で打ち切る
        $job->assertFailed();
        $job->assertNotReleased();
        Http::assertSentCount(1);
    }

    private function createConfirmationTarget(): Reservation
    {
        $salon = Salon::factory()->create();
        LineSetting::factory()->for($salon)->create();

        $customer = Customer::factory()->for($salon)->create([
            'line_user_id' => 'U-'.fake()->unique()->md5(),
            'line_linked_at' => now(),
        ]);
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);
        $staff = User::factory()->for($salon)->create();

        $start = now()->addDay()->startOfHour();

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
        (new SendBookingConfirmationJob($reservation->id))->handle(
            app(ReservationRepository::class),
            app(LineClient::class),
        );
    }
}
