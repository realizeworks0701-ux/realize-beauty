<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class CustomerVisitDateTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_records_visit_dates_when_status_becomes_visited(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => ReservationStatus::Visited->value,
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('2026-08-10', $customer->first_visit_at?->toDateString());
        $this->assertSame('2026-08-10', $customer->last_visit_at?->toDateString());
    }

    public function test_clears_visit_dates_when_visited_is_reverted(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt(
            $user, $customer, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Visited,
        );
        $customer->update(['first_visit_at' => '2026-08-10', 'last_visit_at' => '2026-08-10']);

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => ReservationStatus::Cancelled->value,
        ])->assertOk();

        $customer->refresh();
        $this->assertNull($customer->first_visit_at);
        $this->assertNull($customer->last_visit_at);
    }

    public function test_uses_min_and_max_of_visited_reservations(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $this->reservationAt($user, $customer, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited);
        $this->reservationAt($user, $customer, $menu, '2026-07-01T10:00:00+09:00', ReservationStatus::Visited);
        $latest = $this->reservationAt($user, $customer, $menu, '2026-08-01T10:00:00+09:00');

        $this->patchJson("/api/v1/reservations/{$latest->id}", [
            'status' => ReservationStatus::Visited->value,
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('2026-06-01', $customer->first_visit_at?->toDateString());
        $this->assertSame('2026-08-01', $customer->last_visit_at?->toDateString());
    }

    public function test_recalculates_after_reservation_is_deleted(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $this->reservationAt($user, $customer, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited);
        $latest = $this->reservationAt($user, $customer, $menu, '2026-08-01T10:00:00+09:00', ReservationStatus::Visited);
        $customer->update(['first_visit_at' => '2026-06-01', 'last_visit_at' => '2026-08-01']);

        $this->deleteJson("/api/v1/reservations/{$latest->id}")->assertNoContent();

        $customer->refresh();
        $this->assertSame('2026-06-01', $customer->first_visit_at?->toDateString());
        $this->assertSame('2026-06-01', $customer->last_visit_at?->toDateString());
    }

    public function test_recalculates_both_customers_when_reservation_is_reassigned(): void
    {
        [$user, $from, $menu] = $this->createSalonContext();
        $to = Customer::factory()->for($user->salon)->create();
        $reservation = $this->reservationAt(
            $user, $from, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Visited,
        );
        $from->update(['first_visit_at' => '2026-08-10', 'last_visit_at' => '2026-08-10']);

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'customer_id' => $to->id,
        ])->assertOk();

        $from->refresh();
        $to->refresh();
        $this->assertNull($from->first_visit_at);
        $this->assertNull($from->last_visit_at);
        $this->assertSame('2026-08-10', $to->first_visit_at?->toDateString());
        $this->assertSame('2026-08-10', $to->last_visit_at?->toDateString());
    }

    public function test_uses_salon_timezone_for_the_date_boundary(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        // UTC 2026-08-09 15:00 = JST 2026-08-10 00:00
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T00:00:00+09:00');

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => ReservationStatus::Visited->value,
        ])->assertOk();

        $this->assertSame('2026-08-10', $customer->refresh()->first_visit_at?->toDateString());
    }

    public function test_ignores_reservations_of_other_customers(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $other = Customer::factory()->for($user->salon)->create();
        $this->reservationAt($user, $other, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited);
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => ReservationStatus::Visited->value,
        ])->assertOk();

        $this->assertSame('2026-08-10', $customer->refresh()->first_visit_at?->toDateString());
    }

    /**
     * @return array{0: User, 1: Customer, 2: Menu}
     */
    private function createSalonContext(): array
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create([
            'first_visit_at' => null,
            'last_visit_at' => null,
        ]);
        $menu = Menu::factory()->for($user->salon)->create(['duration_minutes' => 60]);

        return [$user, $customer, $menu];
    }

    private function reservationAt(
        User $user,
        Customer $customer,
        Menu $menu,
        string $startAt,
        ReservationStatus $status = ReservationStatus::Reserved,
    ): Reservation {
        $start = Carbon::parse($startAt)->utc();

        return Reservation::factory()->for($user->salon)->create([
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes($menu->duration_minutes),
            'status' => $status,
        ]);
    }
}
