<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Salon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class MenuApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_index_returns_menus_ordered_by_display_order_then_id(): void
    {
        $user = $this->actingAsSalonUser();
        $second = Menu::factory()->for($user->salon)->create(['display_order' => 2]);
        $firstByOrder = Menu::factory()->for($user->salon)->create(['display_order' => 1]);
        $sameOrderLaterId = Menu::factory()->for($user->salon)->create(['display_order' => 1]);

        $response = $this->getJson('/api/v1/menus');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('data.0.id', $firstByOrder->id);
        $response->assertJsonPath('data.1.id', $sameOrderLaterId->id);
        $response->assertJsonPath('data.2.id', $second->id);
    }

    public function test_index_filters_by_is_active(): void
    {
        $user = $this->actingAsSalonUser();
        $active = Menu::factory()->for($user->salon)->create();
        Menu::factory()->inactive()->for($user->salon)->create();

        $response = $this->getJson('/api/v1/menus?is_active=true');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $active->id);
    }

    public function test_index_is_scoped_to_own_salon(): void
    {
        $user = $this->actingAsSalonUser();
        $ownMenu = Menu::factory()->for($user->salon)->create();
        Menu::factory()->for(Salon::factory())->create();

        $response = $this->getJson('/api/v1/menus');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $ownMenu->id);
    }

    public function test_store_creates_menu(): void
    {
        $user = $this->actingAsSalonUser();

        $response = $this->postJson('/api/v1/menus', [
            'name' => 'カット',
            'price' => 5500,
            'duration_minutes' => 60,
            'display_order' => 1,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'カット');
        $response->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('menus', [
            'salon_id' => $user->salon_id,
            'name' => 'カット',
            'price' => 5500,
            'duration_minutes' => 60,
        ]);
    }

    public function test_store_assigns_next_display_order_when_omitted(): void
    {
        $user = $this->actingAsSalonUser();
        Menu::factory()->for($user->salon)->create(['display_order' => 3]);

        $response = $this->postJson('/api/v1/menus', [
            'name' => 'カラー',
            'price' => 8800,
            'duration_minutes' => 90,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.display_order', 4);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAsSalonUser();

        $this->postJson('/api/v1/menus', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'price', 'duration_minutes']);
    }

    public function test_store_validates_duration_minutes_range(): void
    {
        $this->actingAsSalonUser();

        $this->postJson('/api/v1/menus', [
            'name' => 'カット',
            'price' => 5500,
            'duration_minutes' => 481,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['duration_minutes']);
    }

    public function test_show_returns_menu(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = Menu::factory()->for($user->salon)->create(['name' => 'パーマ']);

        $this->getJson("/api/v1/menus/{$menu->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'パーマ');
    }

    public function test_update_modifies_menu(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = Menu::factory()->for($user->salon)->create();

        $response = $this->putJson("/api/v1/menus/{$menu->id}", [
            'name' => 'カット（更新）',
            'is_active' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'カット（更新）');
        $response->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('menus', ['id' => $menu->id, 'name' => 'カット（更新）']);
    }

    public function test_destroy_soft_deletes_menu(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = Menu::factory()->for($user->salon)->create();

        $this->deleteJson("/api/v1/menus/{$menu->id}")->assertNoContent();
        $this->assertSoftDeleted('menus', ['id' => $menu->id]);
    }

    public function test_show_is_scoped_to_own_salon(): void
    {
        $this->actingAsSalonUser();
        $otherMenu = Menu::factory()->for(Salon::factory())->create();

        $this->getJson("/api/v1/menus/{$otherMenu->id}")->assertNotFound();
    }

    public function test_update_is_scoped_to_own_salon(): void
    {
        $this->actingAsSalonUser();
        $otherMenu = Menu::factory()->for(Salon::factory())->create();

        $this->putJson("/api/v1/menus/{$otherMenu->id}", ['name' => '乗っ取り'])->assertNotFound();
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/menus')->assertUnauthorized();
    }
}
