<?php

namespace Tests\Feature;

use App\Models\BusinessHour;
use App\Models\Menu;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPublicBookingSalon;
use Tests\TestCase;

class PublicSalonApiTest extends TestCase
{
    use CreatesPublicBookingSalon, RefreshDatabase;

    public function test_show_returns_salon_name_business_hours_menus_and_staff_without_authentication(): void
    {
        $salon = Salon::factory()->create(['name' => 'Realize Beauty 表参道']);
        $this->createBusinessHours($salon);
        $menu = Menu::factory()->for($salon)->create(['name' => 'カット', 'price' => 5500, 'duration_minutes' => 60]);
        $staff = User::factory()->for($salon)->create(['name' => '田中 美咲']);

        $response = $this->getJson("/api/public/v1/salons/{$salon->booking_slug}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'name',
                'business_hours' => [['day_of_week', 'is_closed', 'open_time', 'close_time']],
                'menus' => [['id', 'name', 'price', 'duration_minutes']],
                'staff' => [['id', 'name']],
            ],
        ]);
        $response->assertJsonPath('data.name', 'Realize Beauty 表参道');
        $response->assertJsonCount(7, 'data.business_hours');
        $response->assertJsonPath('data.menus.0.id', $menu->id);
        $response->assertJsonPath('data.staff.0.id', $staff->id);
    }

    public function test_show_returns_only_id_and_name_for_staff(): void
    {
        $salon = Salon::factory()->create();
        $this->createBusinessHours($salon);
        User::factory()->for($salon)->create();

        $response = $this->getJson("/api/public/v1/salons/{$salon->booking_slug}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.staff.0.email');
        $response->assertJsonMissingPath('data.staff.0.role');
    }

    public function test_show_completes_missing_business_hours_with_default(): void
    {
        $salon = Salon::factory()->create();
        BusinessHour::factory()->for($salon)->create([
            'day_of_week' => 0,
            'is_closed' => true,
            'open_time' => '10:00',
            'close_time' => '20:00',
        ]);

        $response = $this->getJson("/api/public/v1/salons/{$salon->booking_slug}");

        $response->assertOk();
        $response->assertJsonCount(7, 'data.business_hours');
        $response->assertJsonPath('data.business_hours.0', [
            'day_of_week' => 0,
            'is_closed' => true,
            'open_time' => '10:00',
            'close_time' => '20:00',
        ]);
        $response->assertJsonPath('data.business_hours.1', [
            'day_of_week' => 1,
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '19:00',
        ]);
    }

    public function test_show_excludes_inactive_menus_and_staff(): void
    {
        $salon = Salon::factory()->create();
        $this->createBusinessHours($salon);
        Menu::factory()->for($salon)->inactive()->create();
        User::factory()->for($salon)->create(['is_active' => false]);
        $activeMenu = Menu::factory()->for($salon)->create();
        $activeStaff = User::factory()->for($salon)->create();

        $response = $this->getJson("/api/public/v1/salons/{$salon->booking_slug}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data.menus');
        $response->assertJsonCount(1, 'data.staff');
        $response->assertJsonPath('data.menus.0.id', $activeMenu->id);
        $response->assertJsonPath('data.staff.0.id', $activeStaff->id);
    }

    public function test_show_excludes_other_salon_menus_and_staff(): void
    {
        $salon = Salon::factory()->create();
        $other = Salon::factory()->create();
        Menu::factory()->for($other)->create();
        User::factory()->for($other)->create();

        $response = $this->getJson("/api/public/v1/salons/{$salon->booking_slug}");

        $response->assertOk();
        $response->assertJsonCount(0, 'data.menus');
        $response->assertJsonCount(0, 'data.staff');
    }

    public function test_show_returns_404_for_inactive_salon(): void
    {
        $salon = Salon::factory()->create(['is_active' => false]);

        $this->getJson("/api/public/v1/salons/{$salon->booking_slug}")->assertNotFound();
    }

    public function test_show_returns_404_for_unknown_slug(): void
    {
        $this->getJson('/api/public/v1/salons/unknownslug000000')->assertNotFound();
    }
}
