<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarMode;
use App\Enums\ReservationStatus;
use App\Jobs\SyncReservationToGoogleJob;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicBookingCancelTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-07-20T08:00:00+09:00';

    private const START_AT = '2026-07-21T10:00:00+09:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
    }

    public function test_show_returns_booking_summary_without_authentication(): void
    {
        $reservation = $this->webReservation();

        $response = $this->getJson("/api/public/v1/bookings/{$reservation->booking_token}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['salon_name', 'menu_name', 'staff_name', 'start_at', 'end_at', 'status', 'can_cancel'],
        ]);
        $response->assertJsonPath('data.salon_name', $reservation->salon->name);
        $response->assertJsonPath('data.menu_name', $reservation->menu->name);
        $response->assertJsonPath('data.staff_name', $reservation->user->name);
        $response->assertJsonPath('data.status', 'reserved');
        $response->assertJsonPath('data.can_cancel', true);
    }

    public function test_show_does_not_expose_customer_information(): void
    {
        $reservation = $this->webReservation();

        $response = $this->getJson("/api/public/v1/bookings/{$reservation->booking_token}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.customer');
        $response->assertJsonMissingPath('data.customer_name');
        $response->assertDontSee($reservation->customer->name);
        $response->assertDontSee($reservation->customer->phone);
    }

    public function test_show_returns_can_cancel_false_for_cancelled_reservation(): void
    {
        $reservation = $this->webReservation(status: ReservationStatus::Cancelled);

        $response = $this->getJson("/api/public/v1/bookings/{$reservation->booking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.can_cancel', false);
    }

    public function test_show_returns_can_cancel_false_after_start_at(): void
    {
        $reservation = $this->webReservation();
        $this->travelTo(Carbon::parse(self::START_AT));

        $response = $this->getJson("/api/public/v1/bookings/{$reservation->booking_token}");

        $response->assertOk();
        $response->assertJsonPath('data.can_cancel', false);
    }

    public function test_show_returns_404_for_unknown_token(): void
    {
        $this->getJson('/api/public/v1/bookings/'.Str::random(32))->assertNotFound();
    }

    public function test_show_returns_404_when_salon_is_inactive(): void
    {
        $reservation = $this->webReservation();
        $reservation->salon->update(['is_active' => false]);

        $this->getJson("/api/public/v1/bookings/{$reservation->booking_token}")->assertNotFound();
    }

    public function test_cancel_updates_status_to_cancelled(): void
    {
        $reservation = $this->webReservation();

        $response = $this->postJson("/api/public/v1/bookings/{$reservation->booking_token}/cancel");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'cancelled');
        $response->assertJsonPath('data.can_cancel', false);
        $this->assertSame(ReservationStatus::Cancelled, $reservation->refresh()->status);
    }

    public function test_cancel_returns_409_for_already_cancelled_reservation(): void
    {
        $reservation = $this->webReservation(status: ReservationStatus::Cancelled);

        $response = $this->postJson("/api/public/v1/bookings/{$reservation->booking_token}/cancel");

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'この予約はキャンセルできません。');
    }

    public function test_cancel_returns_409_for_visited_reservation(): void
    {
        $reservation = $this->webReservation(status: ReservationStatus::Visited);

        $response = $this->postJson("/api/public/v1/bookings/{$reservation->booking_token}/cancel");

        $response->assertStatus(409);
        $this->assertSame(ReservationStatus::Visited, $reservation->refresh()->status);
    }

    public function test_cancel_returns_409_exactly_at_start_at(): void
    {
        $reservation = $this->webReservation();
        $this->travelTo(Carbon::parse(self::START_AT));

        $this->postJson("/api/public/v1/bookings/{$reservation->booking_token}/cancel")->assertStatus(409);
        $this->assertSame(ReservationStatus::Reserved, $reservation->refresh()->status);
    }

    public function test_cancel_returns_409_after_start_at(): void
    {
        $reservation = $this->webReservation();
        $this->travelTo(Carbon::parse(self::START_AT)->addHour());

        $this->postJson("/api/public/v1/bookings/{$reservation->booking_token}/cancel")->assertStatus(409);
    }

    public function test_cancel_returns_404_for_unknown_token(): void
    {
        $this->postJson('/api/public/v1/bookings/'.Str::random(32).'/cancel')->assertNotFound();
    }

    public function test_cancel_returns_404_when_salon_is_inactive(): void
    {
        $reservation = $this->webReservation();
        $reservation->salon->update(['is_active' => false]);

        $this->postJson("/api/public/v1/bookings/{$reservation->booking_token}/cancel")->assertNotFound();
        $this->assertSame(ReservationStatus::Reserved, $reservation->refresh()->status);
    }

    public function test_cancel_dispatches_google_sync_job_via_bulk_update_path(): void
    {
        Queue::fake();
        $reservation = $this->webReservation(googleCalendarMode: GoogleCalendarMode::PerStaff);

        $this->postJson("/api/public/v1/bookings/{$reservation->booking_token}/cancel")->assertOk();

        // cancelByBookingToken は一括 UPDATE でモデルイベントが発火しないが、Service 層から明示 dispatch する
        Queue::assertPushed(
            SyncReservationToGoogleJob::class,
            fn (SyncReservationToGoogleJob $job) => $job->reservationId === $reservation->id,
        );
    }

    public function test_cancel_does_not_dispatch_google_sync_job_on_conflict(): void
    {
        Queue::fake();
        // 既にキャンセル済み → 条件付き UPDATE は0件 → 409 でキャンセルは成立しない
        $reservation = $this->webReservation(ReservationStatus::Cancelled, GoogleCalendarMode::PerStaff);

        $this->postJson("/api/public/v1/bookings/{$reservation->booking_token}/cancel")->assertStatus(409);

        Queue::assertNotPushed(SyncReservationToGoogleJob::class);
    }

    private function webReservation(
        ReservationStatus $status = ReservationStatus::Reserved,
        ?GoogleCalendarMode $googleCalendarMode = null,
    ): Reservation {
        $salon = Salon::factory()->create(['google_calendar_mode' => $googleCalendarMode]);
        $start = Carbon::parse(self::START_AT)->utc();

        return Reservation::factory()->for($salon)->create([
            'customer_id' => Customer::factory()->for($salon)->create(['name' => '山田 花子', 'phone' => '09012345678']),
            'menu_id' => Menu::factory()->for($salon),
            'user_id' => User::factory()->for($salon),
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(60),
            'status' => $status,
            'booking_token' => Str::random(32),
        ]);
    }
}
