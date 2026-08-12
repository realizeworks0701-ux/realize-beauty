<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BackfillCustomerVisitDatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fills_visit_dates_from_visited_reservations(): void
    {
        [$salon, $user, $menu] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create([
            'first_visit_at' => null,
            'last_visit_at' => null,
        ]);
        $this->reservationAt($salon, $user, $customer, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited);
        $this->reservationAt($salon, $user, $customer, $menu, '2026-08-01T10:00:00+09:00', ReservationStatus::Visited);
        $this->reservationAt($salon, $user, $customer, $menu, '2026-09-01T10:00:00+09:00');

        $this->runBackfill();

        $customer->refresh();
        $this->assertSame('2026-06-01', $customer->first_visit_at?->toDateString());
        $this->assertSame('2026-08-01', $customer->last_visit_at?->toDateString());
    }

    public function test_keeps_existing_values_for_customers_without_visited_reservations(): void
    {
        [$salon] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create([
            'first_visit_at' => '2025-01-01',
            'last_visit_at' => '2025-02-01',
        ]);

        $this->runBackfill();

        $customer->refresh();
        $this->assertSame('2025-01-01', $customer->first_visit_at?->toDateString());
        $this->assertSame('2025-02-01', $customer->last_visit_at?->toDateString());
    }

    public function test_ignores_soft_deleted_reservations(): void
    {
        [$salon, $user, $menu] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create([
            'first_visit_at' => null,
            'last_visit_at' => null,
        ]);
        $this->reservationAt($salon, $user, $customer, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited);
        $this->reservationAt($salon, $user, $customer, $menu, '2026-08-01T10:00:00+09:00', ReservationStatus::Visited)
            ->delete();

        $this->runBackfill();

        $this->assertSame('2026-06-01', $customer->refresh()->last_visit_at?->toDateString());
    }

    public function test_uses_salon_timezone_for_the_date_boundary(): void
    {
        [$salon, $user, $menu] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create([
            'first_visit_at' => null,
            'last_visit_at' => null,
        ]);
        // UTC 2026-08-09 15:00 = JST 2026-08-10 00:00
        $this->reservationAt($salon, $user, $customer, $menu, '2026-08-10T00:00:00+09:00', ReservationStatus::Visited);

        $this->runBackfill();

        $this->assertSame('2026-08-10', $customer->refresh()->first_visit_at?->toDateString());
    }

    private function runBackfill(): void
    {
        (include database_path('migrations/2026_08_10_000001_backfill_customer_visit_dates.php'))->up();
    }

    /**
     * @return array{0: Salon, 1: User, 2: Menu}
     */
    private function createContext(): array
    {
        $salon = Salon::factory()->create();

        return [
            $salon,
            User::factory()->for($salon)->create(),
            Menu::factory()->for($salon)->create(['duration_minutes' => 60]),
        ];
    }

    private function reservationAt(
        Salon $salon,
        User $user,
        Customer $customer,
        Menu $menu,
        string $startAt,
        ReservationStatus $status = ReservationStatus::Reserved,
    ): Reservation {
        $start = Carbon::parse($startAt)->utc();

        return Reservation::factory()->for($salon)->create([
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes($menu->duration_minutes),
            'status' => $status,
        ]);
    }
}
