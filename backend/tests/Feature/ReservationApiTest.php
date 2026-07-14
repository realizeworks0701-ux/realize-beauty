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
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_index_returns_today_reservations_by_default(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $today = $this->reservationAt($user, $customer, $menu, now());
        $this->reservationAt($user, $customer, $menu, now()->addDays(3));

        $response = $this->getJson('/api/v1/reservations');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $today->id);
    }

    public function test_index_uses_jst_date_boundaries(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $inside = $this->reservationAt($user, $customer, $menu, '2026-08-10T00:30:00+09:00');
        $this->reservationAt($user, $customer, $menu, '2026-08-09T23:00:00+09:00');

        $response = $this->getJson('/api/v1/reservations?from=2026-08-10&to=2026-08-10');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $inside->id);
    }

    public function test_index_returns_range_ordered_by_start_at(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $second = $this->reservationAt($user, $customer, $menu, '2026-08-11T10:00:00+09:00');
        $first = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');
        $this->reservationAt($user, $customer, $menu, '2026-08-12T10:00:00+09:00');

        $response = $this->getJson('/api/v1/reservations?from=2026-08-10&to=2026-08-11');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.1.id', $second->id);
    }

    public function test_index_returns_nested_customer_menu_user_summaries(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $this->reservationAt($user, $customer, $menu, now());

        $response = $this->getJson('/api/v1/reservations');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [[
                'id',
                'customer' => ['id', 'name', 'kana', 'phone'],
                'menu' => ['id', 'name', 'price', 'duration_minutes', 'is_active'],
                'user' => ['id', 'name'],
                'start_at',
                'end_at',
                'status',
                'note',
                'created_at',
                'updated_at',
            ]],
        ]);
        $response->assertJsonPath('data.0.customer.id', $customer->id);
        $response->assertJsonPath('data.0.menu.id', $menu->id);
        $response->assertJsonPath('data.0.user.id', $user->id);
    }

    public function test_index_filters_by_user_id_and_status(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $otherStaff = User::factory()->for($user->salon)->create();
        $reserved = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');
        $this->reservationAt($user, $customer, $menu, '2026-08-10T13:00:00+09:00', ReservationStatus::Cancelled);
        $this->reservationAt($otherStaff, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $response = $this->getJson("/api/v1/reservations?from=2026-08-10&user_id={$user->id}&status=reserved");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $reserved->id);
    }

    public function test_index_allows_period_of_exactly_31_days(): void
    {
        $this->createSalonContext();

        $this->getJson('/api/v1/reservations?from=2026-08-01&to=2026-08-31')->assertOk();
    }

    public function test_index_rejects_period_longer_than_31_days(): void
    {
        $this->createSalonContext();

        $this->getJson('/api/v1/reservations?from=2026-08-01&to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_index_rejects_to_before_from(): void
    {
        $this->createSalonContext();

        $this->getJson('/api/v1/reservations?from=2026-08-10&to=2026-08-09')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_index_rejects_invalid_date_format(): void
    {
        $this->createSalonContext();

        $this->getJson('/api/v1/reservations?from=2026-8-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_index_is_scoped_to_own_salon(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $own = $this->reservationAt($user, $customer, $menu, now());

        $otherSalon = Salon::factory()->create();
        $otherStaff = User::factory()->for($otherSalon)->create();
        $otherCustomer = Customer::factory()->for($otherSalon)->create();
        $otherMenu = Menu::factory()->for($otherSalon)->create();
        $this->reservationAt($otherStaff, $otherCustomer, $otherMenu, now());

        $response = $this->getJson('/api/v1/reservations');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $own->id);
    }

    public function test_store_creates_reservation_with_derived_end_at(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();

        $response = $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-08-10T10:00:00+09:00',
            'note' => '初回予約',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.start_at', '2026-08-10T01:00:00+00:00');
        $response->assertJsonPath('data.end_at', '2026-08-10T02:00:00+00:00');
        $response->assertJsonPath('data.status', 'reserved');
        $response->assertJsonPath('data.note', '初回予約');
        $this->assertDatabaseHas('reservations', [
            'salon_id' => $user->salon_id,
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'status' => 'reserved',
        ]);
    }

    public function test_store_rejects_double_booking_for_same_staff(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $response = $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-08-10T10:30:00+09:00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_at']);
        $response->assertJsonPath('errors.start_at.0', '指定した時間帯は既に予約が入っています。');
    }

    public function test_store_allows_same_time_for_different_staff(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $otherStaff = User::factory()->for($user->salon)->create();
        $this->reservationAt($otherStaff, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-08-10T10:00:00+09:00',
        ])->assertCreated();
    }

    public function test_store_ignores_cancelled_reservations_in_double_booking_check(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Cancelled);

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-08-10T10:00:00+09:00',
        ])->assertCreated();
    }

    public function test_store_allows_adjacent_reservations(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-08-10T11:00:00+09:00',
        ])->assertCreated();
    }

    public function test_store_rejects_inactive_menu(): void
    {
        [$user, $customer] = $this->createSalonContext();
        $inactiveMenu = Menu::factory()->inactive()->for($user->salon)->create();

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $inactiveMenu->id,
            'user_id' => $user->id,
            'start_at' => '2026-08-10T10:00:00+09:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['menu_id']);
    }

    public function test_store_rejects_customer_of_other_salon(): void
    {
        [$user, , $menu] = $this->createSalonContext();
        $otherCustomer = Customer::factory()->for(Salon::factory())->create();

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $otherCustomer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-08-10T10:00:00+09:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_store_rejects_inactive_staff(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $inactiveStaff = User::factory()->for($user->salon)->create(['is_active' => false]);

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $inactiveStaff->id,
            'start_at' => '2026-08-10T10:00:00+09:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->createSalonContext();

        $this->postJson('/api/v1/reservations', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id', 'menu_id', 'user_id', 'start_at']);
    }

    public function test_show_returns_reservation(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $this->getJson("/api/v1/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $reservation->id)
            ->assertJsonPath('data.customer.name', $customer->name);
    }

    public function test_show_is_scoped_to_own_salon(): void
    {
        $this->createSalonContext();

        $otherSalon = Salon::factory()->create();
        $otherStaff = User::factory()->for($otherSalon)->create();
        $otherCustomer = Customer::factory()->for($otherSalon)->create();
        $otherMenu = Menu::factory()->for($otherSalon)->create();
        $other = $this->reservationAt($otherStaff, $otherCustomer, $otherMenu, now());

        $this->getJson("/api/v1/reservations/{$other->id}")->assertNotFound();
    }

    public function test_update_recalculates_end_at_when_start_at_changes(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $response = $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'start_at' => '2026-08-10T13:00:00+09:00',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.start_at', '2026-08-10T04:00:00+00:00');
        $response->assertJsonPath('data.end_at', '2026-08-10T05:00:00+00:00');
    }

    public function test_update_recalculates_end_at_when_menu_changes(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');
        $longerMenu = Menu::factory()->for($user->salon)->create(['duration_minutes' => 90]);

        $response = $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'menu_id' => $longerMenu->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.menu.id', $longerMenu->id);
        $response->assertJsonPath('data.end_at', '2026-08-10T02:30:00+00:00');
    }

    public function test_update_keeps_end_at_when_only_note_changes(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $response = $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'note' => 'メモのみ更新',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.note', 'メモのみ更新');
        $response->assertJsonPath('data.end_at', '2026-08-10T02:00:00+00:00');
    }

    public function test_update_excludes_self_from_double_booking_check(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'start_at' => '2026-08-10T10:30:00+09:00',
        ])->assertOk();
    }

    public function test_update_rejects_double_booking_with_other_reservation(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T13:00:00+09:00');

        $response = $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'start_at' => '2026-08-10T10:30:00+09:00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.start_at.0', '指定した時間帯は既に予約が入っています。');
    }

    public function test_update_changes_status_to_cancelled(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $response = $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => 'cancelled',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'cancelled']);
    }

    public function test_update_allows_keeping_inactive_menu(): void
    {
        [$user, $customer] = $this->createSalonContext();
        $inactiveMenu = Menu::factory()->inactive()->for($user->salon)->create(['duration_minutes' => 60]);
        $reservation = $this->reservationAt($user, $customer, $inactiveMenu, '2026-08-10T10:00:00+09:00');

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'note' => 'メニューは変更しない',
        ])->assertOk();
    }

    public function test_update_rejects_changing_to_inactive_menu(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');
        $inactiveMenu = Menu::factory()->inactive()->for($user->salon)->create();

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'menu_id' => $inactiveMenu->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['menu_id']);
    }

    public function test_destroy_soft_deletes_reservation(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $this->deleteJson("/api/v1/reservations/{$reservation->id}")->assertNoContent();
        $this->assertSoftDeleted('reservations', ['id' => $reservation->id]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/reservations')->assertUnauthorized();
    }

    public function test_index_includes_reservations_of_soft_deleted_customer(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, now());
        $customer->delete();

        $response = $this->getJson('/api/v1/reservations');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $reservation->id);
        $response->assertJsonPath('data.0.customer.name', $customer->name);

        $this->getJson("/api/v1/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', $customer->name);
    }

    public function test_index_includes_reservations_of_soft_deleted_staff(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $staff = User::factory()->for($user->salon)->create();
        $reservation = $this->reservationAt($staff, $customer, $menu, now());
        $staff->delete();

        $response = $this->getJson('/api/v1/reservations');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $reservation->id);
        $response->assertJsonPath('data.0.user.name', $staff->name);
    }

    public function test_store_rejects_start_at_without_timezone_offset(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();

        foreach (['2026-08-10T10:00:00', '2026-08-10 10:00:00', '2026-08-10'] as $startAt) {
            $this->postJson('/api/v1/reservations', [
                'customer_id' => $customer->id,
                'menu_id' => $menu->id,
                'user_id' => $user->id,
                'start_at' => $startAt,
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['start_at']);
        }
    }

    /**
     * 認証済みユーザーと同一サロンの顧客・メニュー（60分）を作成する。
     *
     * @return array{0: User, 1: Customer, 2: Menu}
     */
    private function createSalonContext(): array
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $menu = Menu::factory()->for($user->salon)->create(['duration_minutes' => 60]);

        return [$user, $customer, $menu];
    }

    private function reservationAt(
        User $user,
        Customer $customer,
        Menu $menu,
        Carbon|string $startAt,
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
