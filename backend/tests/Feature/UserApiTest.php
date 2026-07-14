<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_index_returns_active_staff_ordered_by_id(): void
    {
        $user = $this->actingAsSalonUser();
        $staff = User::factory()->for($user->salon)->role(Role::Staff)->create(['name' => '田中 美咲']);

        $response = $this->getJson('/api/v1/users');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $user->id);
        $response->assertJsonPath('data.1.id', $staff->id);
        $response->assertJsonPath('data.1.name', '田中 美咲');
        $response->assertJsonPath('data.1.role', 'staff');
    }

    public function test_index_returns_only_id_name_role(): void
    {
        $this->actingAsSalonUser();

        $response = $this->getJson('/api/v1/users');

        $response->assertOk();
        $this->assertSame(['id', 'name', 'role'], array_keys($response->json('data.0')));
    }

    public function test_index_excludes_inactive_staff(): void
    {
        $user = $this->actingAsSalonUser();
        User::factory()->for($user->salon)->create(['is_active' => false]);

        $response = $this->getJson('/api/v1/users');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $user->id);
    }

    public function test_index_is_scoped_to_own_salon(): void
    {
        $user = $this->actingAsSalonUser();
        User::factory()->for(Salon::factory())->create();

        $response = $this->getJson('/api/v1/users');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $user->id);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
    }
}
