<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class ReservationPriceSnapshotTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_create_snapshots_current_menu_price(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $menu = Menu::factory()->for($user->salon)->create(['price' => 12000, 'duration_minutes' => 60]);

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-09-01T10:00:00+09:00',
        ])->assertCreated();

        $this->assertSame(12000, Reservation::sole()->price);
    }

    public function test_price_is_kept_when_menu_price_changes_later(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $menu = Menu::factory()->for($user->salon)->create(['price' => 12000, 'duration_minutes' => 60]);

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-09-01T10:00:00+09:00',
        ])->assertCreated();

        $menu->update(['price' => 99999]);

        $this->assertSame(12000, Reservation::sole()->price);
    }

    public function test_update_resnapshots_price_only_when_menu_changes(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $menuA = Menu::factory()->for($user->salon)->create(['price' => 12000, 'duration_minutes' => 60]);
        $menuB = Menu::factory()->for($user->salon)->create(['price' => 8000, 'duration_minutes' => 60]);

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menuA->id,
            'user_id' => $user->id,
            'start_at' => '2026-09-01T10:00:00+09:00',
        ])->assertCreated();
        $reservation = Reservation::sole();

        // ステータスのみ更新 → price は変わらない（メニュー価格が変わっていても）
        $menuA->update(['price' => 50000]);
        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => ReservationStatus::Visited->value,
        ])->assertOk();
        $this->assertSame(12000, $reservation->refresh()->price);

        // メニュー変更 → 新メニューの価格で再スナップショット
        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'menu_id' => $menuB->id,
        ])->assertOk();
        $this->assertSame(8000, $reservation->refresh()->price);
    }

    public function test_backfill_fills_missing_prices_from_menus(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = Menu::factory()->for($user->salon)->create(['price' => 8000]);
        $filled = Reservation::factory()->for($user->salon)->create([
            'customer_id' => Customer::factory()->for($user->salon),
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'price' => null,
        ]);
        $kept = Reservation::factory()->for($user->salon)->create([
            'customer_id' => Customer::factory()->for($user->salon),
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'price' => 5000,
        ]);

        (include database_path('migrations/2026_08_29_000002_backfill_reservation_prices.php'))->up();

        $this->assertSame(8000, $filled->refresh()->price);
        $this->assertSame(5000, $kept->refresh()->price);
    }
}
