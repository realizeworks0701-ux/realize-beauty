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
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPublicBookingSalon;
use Tests\TestCase;

class AvailabilityApiTest extends TestCase
{
    use CreatesPublicBookingSalon, RefreshDatabase;

    private const NOW = '2026-07-20T08:00:00+09:00';

    private const DATE = '2026-07-21';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
    }

    public function test_returns_slots_on_30_minute_grid_within_business_hours(): void
    {
        [$salon, $menu, $staff] = $this->createContext();

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $response->assertJsonCount(19, 'data');
        $response->assertJsonPath('data.0.start_at', '2026-07-21T09:00:00+09:00');
        $response->assertJsonPath('data.1.start_at', '2026-07-21T09:30:00+09:00');
        $response->assertJsonPath('data.18.start_at', '2026-07-21T18:00:00+09:00');
    }

    public function test_slots_are_generated_from_open_time_as_origin(): void
    {
        [$salon, $menu, $staff] = $this->createContext(openTime: '09:15');

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $response->assertJsonPath('data.0.start_at', '2026-07-21T09:15:00+09:00');
        $response->assertJsonPath('data.1.start_at', '2026-07-21T09:45:00+09:00');
    }

    public function test_excludes_slots_whose_end_exceeds_close_time(): void
    {
        [$salon, $menu, $staff] = $this->createContext(durationMinutes: 90);

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $response->assertJsonCount(18, 'data');
        $response->assertJsonPath('data.17.start_at', '2026-07-21T17:30:00+09:00');
    }

    public function test_excludes_slots_earlier_than_30_minutes_from_now(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $this->travelTo(Carbon::parse('2026-07-20T10:10:00+09:00'));

        $response = $this->getSlots($salon, ['date' => '2026-07-20', 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $response->assertJsonPath('data.0.start_at', '2026-07-20T11:00:00+09:00');
    }

    public function test_includes_slot_exactly_30_minutes_from_now(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $this->travelTo(Carbon::parse('2026-07-20T10:00:00+09:00'));

        $response = $this->getSlots($salon, ['date' => '2026-07-20', 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $response->assertJsonPath('data.0.start_at', '2026-07-20T10:30:00+09:00');
    }

    public function test_returns_empty_for_past_date(): void
    {
        [$salon, $menu, $staff] = $this->createContext();

        $response = $this->getSlots($salon, ['date' => '2026-07-19', 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_returns_slots_on_the_60th_day_and_empty_beyond(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $query = ['menu_id' => $menu->id, 'user_id' => $staff->id];

        $lastDay = $this->getSlots($salon, $query + ['date' => '2026-09-18']);
        $beyond = $this->getSlots($salon, $query + ['date' => '2026-09-19']);

        $lastDay->assertOk();
        $lastDay->assertJsonCount(19, 'data');
        $beyond->assertOk();
        $beyond->assertJsonCount(0, 'data');
    }

    public function test_returns_empty_for_closed_day(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $this->closeOn($salon, self::DATE);

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_uses_default_business_hours_when_row_is_missing(): void
    {
        $salon = Salon::factory()->create();
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);
        $staff = User::factory()->for($salon)->create();

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $response->assertJsonCount(19, 'data');
        $response->assertJsonPath('data.0.start_at', '2026-07-21T09:00:00+09:00');
        $response->assertJsonPath('data.18.start_at', '2026-07-21T18:00:00+09:00');
    }

    public function test_excludes_slots_overlapping_an_existing_reservation(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $this->reserve($salon, $staff, $menu, '2026-07-21T10:00:00+09:00');

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $slots = $this->slotsOf($response);
        $this->assertContains('2026-07-21T09:00:00+09:00', $slots);
        $this->assertNotContains('2026-07-21T09:30:00+09:00', $slots);
        $this->assertNotContains('2026-07-21T10:00:00+09:00', $slots);
        $this->assertNotContains('2026-07-21T10:30:00+09:00', $slots);
        $this->assertContains('2026-07-21T11:00:00+09:00', $slots);
    }

    public function test_ignores_cancelled_and_no_show_reservations(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $this->reserve($salon, $staff, $menu, '2026-07-21T10:00:00+09:00', ReservationStatus::Cancelled);
        $this->reserve($salon, $staff, $menu, '2026-07-21T13:00:00+09:00', ReservationStatus::NoShow);

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $response->assertJsonCount(19, 'data');
    }

    public function test_ignores_reservations_of_other_staff_when_staff_is_specified(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $other = User::factory()->for($salon)->create();
        $this->reserve($salon, $other, $menu, '2026-07-21T10:00:00+09:00');

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertOk();
        $response->assertJsonCount(19, 'data');
    }

    public function test_without_staff_returns_slot_when_any_staff_is_free(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        User::factory()->for($salon)->create();
        $this->reserve($salon, $staff, $menu, '2026-07-21T10:00:00+09:00');

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id]);

        $response->assertOk();
        $response->assertJsonCount(19, 'data');
    }

    public function test_without_staff_excludes_slot_when_all_staff_are_busy(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $other = User::factory()->for($salon)->create();
        $this->reserve($salon, $staff, $menu, '2026-07-21T10:00:00+09:00');
        $this->reserve($salon, $other, $menu, '2026-07-21T10:00:00+09:00');

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id]);

        $response->assertOk();
        $this->assertNotContains('2026-07-21T10:00:00+09:00', $this->slotsOf($response));
    }

    public function test_returns_empty_when_salon_has_no_active_staff(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $staff->update(['is_active' => false]);

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id]);

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_returns_422_for_inactive_menu(): void
    {
        [$salon, , $staff] = $this->createContext();
        $menu = Menu::factory()->for($salon)->inactive()->create();

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('menu_id');
    }

    public function test_returns_422_for_menu_of_other_salon(): void
    {
        [$salon, , $staff] = $this->createContext();
        $menu = Menu::factory()->for(Salon::factory())->create();

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('menu_id');
    }

    public function test_returns_422_for_staff_of_other_salon(): void
    {
        [$salon, $menu] = $this->createContext();
        $staff = User::factory()->for(Salon::factory())->create();

        $response = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staff->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_id');
    }

    public function test_returns_422_when_date_or_menu_id_is_missing(): void
    {
        [$salon] = $this->createContext();

        $response = $this->getSlots($salon, []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date', 'menu_id']);
    }

    public function test_returns_422_for_invalid_date_format(): void
    {
        [$salon, $menu] = $this->createContext();

        $response = $this->getSlots($salon, ['date' => '2026/07/21', 'menu_id' => $menu->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('date');
    }

    public function test_returns_404_for_inactive_salon(): void
    {
        [$salon, $menu] = $this->createContext();
        $salon->update(['is_active' => false]);

        $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id])->assertNotFound();
    }

    /**
     * @return array{0: Salon, 1: Menu, 2: User}
     */
    private function createContext(
        string $openTime = '09:00',
        string $closeTime = '19:00',
        int $durationMinutes = 60,
    ): array {
        $salon = Salon::factory()->create();
        $this->createBusinessHours($salon, $openTime, $closeTime);

        return [
            $salon,
            Menu::factory()->for($salon)->create(['duration_minutes' => $durationMinutes]),
            User::factory()->for($salon)->create(),
        ];
    }

    private function getSlots(Salon $salon, array $query): TestResponse
    {
        return $this->getJson("/api/public/v1/salons/{$salon->booking_slug}/availability?".http_build_query($query));
    }

    /**
     * @return array<int, string>
     */
    private function slotsOf(TestResponse $response): array
    {
        return array_column($response->json('data'), 'start_at');
    }

    private function reserve(
        Salon $salon,
        User $staff,
        Menu $menu,
        string $startAt,
        ReservationStatus $status = ReservationStatus::Reserved,
    ): Reservation {
        $start = Carbon::parse($startAt)->utc();

        return Reservation::factory()->for($salon)->create([
            'customer_id' => Customer::factory()->for($salon),
            'menu_id' => $menu->id,
            'user_id' => $staff->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes($menu->duration_minutes),
            'status' => $status,
        ]);
    }
}
