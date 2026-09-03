<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarMode;
use App\Enums\ReservationStatus;
use App\Enums\SubscriptionPlan;
use App\Jobs\ProcessLineEventJob;
use App\Jobs\SendBookingConfirmationJob;
use App\Jobs\SendLineReplyJob;
use App\Jobs\SendReservationReminderJob;
use App\Jobs\SyncGoogleCalendarJob;
use App\Jobs\SyncReservationToGoogleJob;
use App\Models\Customer;
use App\Models\GoogleCalendarConnection;
use App\Models\LineSetting;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use App\Repositories\GoogleCalendarConnectionRepository;
use App\Repositories\LineSettingRepository;
use App\Repositories\ReservationRepository;
use App\Services\Billing\EntitlementService;
use App\Services\Google\GoogleCalendarSyncService;
use App\Services\Google\GoogleEventSyncService;
use App\Services\Line\LineClient;
use App\Services\LineEventService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPublicBookingSalon;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

/**
 * 認証ユーザーが居ない経路のプラン制御（ADR-029）。
 *
 * middleware が効かない公開Web予約・キュージョブ・定期実行・外部Webhookを対象にする。
 */
class PlanGatedIntegrationTest extends TestCase
{
    use CreatesPublicBookingSalon, CreatesSalonUsers, RefreshDatabase;

    // ---- 公開Web予約 ----------------------------------------

    /**
     * 403 ではなく 404。スラッグが実在することを外部に知らせないため。
     */
    public function test_public_booking_page_is_not_found_for_a_salon_without_the_reservation_feature(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();

        $this->getJson("/api/public/v1/salons/{$salon->booking_slug}")->assertNotFound();
    }

    public function test_public_availability_is_not_found_without_the_reservation_feature(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $menu = Menu::factory()->for($salon)->create();

        $this->getJson("/api/public/v1/salons/{$salon->booking_slug}/availability?menu_id={$menu->id}&date=2026-09-10")
            ->assertNotFound();
    }

    public function test_public_reservation_cannot_be_created_without_the_reservation_feature(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $this->createBusinessHours($salon);
        $menu = Menu::factory()->for($salon)->create();

        $this->postJson("/api/public/v1/salons/{$salon->booking_slug}/reservations", [
            'menu_id' => $menu->id,
            'start_at' => Carbon::now(config('app.salon_timezone'))->addWeek()->setTime(10, 0)->format('Y-m-d\TH:i:sP'),
            'name' => '佐藤 花子',
            'kana' => 'サトウ ハナコ',
            'phone' => '090-1234-5678',
            'is_first_visit' => true,
        ])->assertNotFound();

        $this->assertDatabaseCount('reservations', 0);
    }

    /**
     * プラン制限による 404 と、存在しないスラッグの 404 が本文まで一致すること。
     * 差があるとスラッグの実在を総当たりで判別できてしまう。
     */
    public function test_gated_404_is_indistinguishable_from_an_unknown_slug(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();

        $gated = $this->getJson("/api/public/v1/salons/{$salon->booking_slug}");
        $unknown = $this->getJson('/api/public/v1/salons/zzzzzzzzzzzzzzzz');

        $gated->assertNotFound();
        $unknown->assertNotFound();
        $this->assertSame($unknown->json('message'), $gated->json('message'));
    }

    public function test_public_booking_page_stays_available_on_standard(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();

        $this->getJson("/api/public/v1/salons/{$salon->booking_slug}")->assertOk();
    }

    /**
     * ダウングレード前に受けた予約は、顧客が最後まで確認・キャンセルできる。
     */
    public function test_existing_bookings_remain_viewable_and_cancellable_after_a_downgrade(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();
        $reservation = $this->bookingFor($salon);

        $salon->subscription()->update(['plan' => SubscriptionPlan::Lite]);

        $this->getJson("/api/public/v1/bookings/{$reservation->booking_token}")->assertOk();
        $this->postJson("/api/public/v1/bookings/{$reservation->booking_token}/cancel")->assertOk();
        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
    }

    // ---- キュージョブ ---------------------------------------

    public function test_line_jobs_do_nothing_for_a_salon_without_the_line_feature(): void
    {
        Http::fake();
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        LineSetting::factory()->for($salon)->create(['is_active' => true]);
        $reservation = $this->bookingFor($salon, linkLine: true);

        (new SendBookingConfirmationJob($reservation->id))->handle(
            app(ReservationRepository::class),
            app(LineClient::class),
            app(EntitlementService::class),
        );
        (new SendReservationReminderJob($reservation->id))->handle(
            app(ReservationRepository::class),
            app(LineClient::class),
            app(EntitlementService::class),
        );

        Http::assertNothingSent();
    }

    public function test_process_line_event_job_stops_for_a_salon_without_the_line_feature(): void
    {
        Http::fake();
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $setting = LineSetting::factory()->for($salon)->create(['is_active' => true]);

        (new ProcessLineEventJob($setting->id, ['type' => 'follow', 'source' => ['userId' => 'U1']]))
            ->handle(
                app(LineSettingRepository::class),
                app(LineEventService::class),
                app(EntitlementService::class),
            );

        Http::assertNothingSent();
    }

    public function test_google_sync_jobs_stop_for_a_salon_without_the_google_calendar_feature(): void
    {
        Http::fake();
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $connection = GoogleCalendarConnection::factory()->for($salon)->create();
        $reservation = $this->bookingFor($salon);

        (new SyncGoogleCalendarJob($connection->id))->handle(
            app(GoogleCalendarConnectionRepository::class),
            app(GoogleCalendarSyncService::class),
            app(EntitlementService::class),
        );
        (new SyncReservationToGoogleJob($reservation->id))->handle(
            app(ReservationRepository::class),
            app(GoogleEventSyncService::class),
            app(EntitlementService::class),
        );

        Http::assertNothingSent();
    }

    /**
     * 投入時点でもプランを見る（キューに積まない）。
     */
    public function test_reservation_update_does_not_enqueue_google_sync_without_the_feature(): void
    {
        Queue::fake();
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create([
            'google_calendar_mode' => GoogleCalendarMode::Shared,
        ]);
        $salon->subscription()->update(['plan' => SubscriptionPlan::Lite]);
        $reservation = $this->bookingFor($salon);

        app(ReservationService::class)->update($salon->id, $reservation->id, [
            'status' => ReservationStatus::Visited->value,
        ]);

        Queue::assertNotPushed(SyncReservationToGoogleJob::class);
    }

    // ---- 定期実行の横断クエリ -------------------------------

    public function test_reminder_query_skips_salons_without_the_line_feature(): void
    {
        $tomorrow = Carbon::now()->utc()->addDay();

        $gated = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        LineSetting::factory()->for($gated)->create(['is_active' => true]);
        $this->bookingFor($gated, linkLine: true, startAt: $tomorrow);

        $allowed = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();
        LineSetting::factory()->for($allowed)->create(['is_active' => true]);
        $target = $this->bookingFor($allowed, linkLine: true, startAt: $tomorrow);

        $found = app(ReservationRepository::class)
            ->listForReminder($tomorrow->copy()->subHour(), $tomorrow->copy()->addHour());

        $this->assertSame([$target->id], $found->pluck('id')->all());
    }

    public function test_active_google_connections_exclude_salons_without_the_feature(): void
    {
        $gated = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        GoogleCalendarConnection::factory()->for($gated)->create();

        $allowed = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();
        $connection = GoogleCalendarConnection::factory()->for($allowed)->create();

        $found = app(GoogleCalendarConnectionRepository::class)->listActive();

        $this->assertSame([$connection->id], $found->pluck('id')->all());
    }

    public function test_expiring_google_channels_exclude_salons_without_the_feature(): void
    {
        $gated = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        GoogleCalendarConnection::factory()->for($gated)->create(['channel_id' => null]);

        $allowed = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();
        $connection = GoogleCalendarConnection::factory()->for($allowed)->create(['channel_id' => null]);

        $found = app(GoogleCalendarConnectionRepository::class)
            ->listExpiringChannels(Carbon::now()->utc()->addDay());

        $this->assertSame([$connection->id], $found->pluck('id')->all());
    }

    public function test_line_reply_job_stops_for_a_salon_without_the_line_feature(): void
    {
        Http::fake();
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $setting = LineSetting::factory()->for($salon)->create(['is_active' => true]);

        (new SendLineReplyJob($setting->id, 'reply-token', [['type' => 'text', 'text' => 'hi']]))
            ->handle(
                app(LineSettingRepository::class),
                app(LineClient::class),
                app(EntitlementService::class),
            );

        Http::assertNothingSent();
    }

    // ---- 外部 Webhook ---------------------------------------

    /**
     * 非 2xx を返すと LINE が再送を繰り返しエンドポイントを無効化するため、無視しても 200。
     */
    public function test_line_webhook_is_acknowledged_but_ignored_without_the_feature(): void
    {
        Queue::fake();
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $setting = LineSetting::factory()->for($salon)->create([
            'is_active' => true,
            'bot_user_id' => 'Ubot1',
            'channel_secret' => 'secret',
        ]);

        $body = json_encode([
            'destination' => 'Ubot1',
            'events' => [['type' => 'follow', 'source' => ['userId' => 'U1']]],
        ]);
        $signature = base64_encode(hash_hmac('sha256', $body, 'secret', true));

        $this->call('POST', '/api/line/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LINE_SIGNATURE' => $signature,
        ], $body)->assertNoContent(200);

        Queue::assertNotPushed(ProcessLineEventJob::class);
        $this->assertNull($setting->fresh()->last_webhook_at);
    }

    public function test_google_webhook_is_acknowledged_but_ignored_without_the_feature(): void
    {
        Queue::fake();
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $connection = GoogleCalendarConnection::factory()->for($salon)->create([
            'channel_id' => 'chan-1',
            'channel_token' => 'token-1',
            'channel_resource_id' => 'res-1',
        ]);

        $this->call('POST', '/api/google/calendar/webhook', [], [], [], [
            'HTTP_X_GOOG_CHANNEL_ID' => 'chan-1',
            'HTTP_X_GOOG_CHANNEL_TOKEN' => 'token-1',
            'HTTP_X_GOOG_RESOURCE_ID' => 'res-1',
            'HTTP_X_GOOG_RESOURCE_STATE' => 'exists',
        ])->assertNoContent(200);

        Queue::assertNotPushed(SyncGoogleCalendarJob::class);
        $this->assertNotNull($connection->fresh());
    }

    private function bookingFor(Salon $salon, bool $linkLine = false, ?Carbon $startAt = null): Reservation
    {
        $user = User::factory()->for($salon)->create();
        $customer = Customer::factory()->for($salon)->create(
            $linkLine ? ['line_user_id' => 'U'.fake()->unique()->numerify('#########')] : [],
        );
        $menu = Menu::factory()->for($salon)->create();
        $startAt ??= Carbon::now()->utc()->addDays(2);

        return Reservation::factory()->for($salon)->for($customer)->for($menu)->for($user)->create([
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addMinutes($menu->duration_minutes),
            'status' => ReservationStatus::Reserved,
            'booking_token' => Str::random(32),
        ]);
    }
}
