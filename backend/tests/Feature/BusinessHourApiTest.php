<?php

namespace Tests\Feature;

use App\Models\BusinessHour;
use App\Models\Salon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class BusinessHourApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_index_returns_seven_days_with_defaults(): void
    {
        $this->actingAsSalonUser();

        $response = $this->getJson('/api/v1/business-hours');

        $response->assertOk();
        $response->assertJsonCount(7, 'data');
        $response->assertJsonPath('data.0.day_of_week', 0);
        $response->assertJsonPath('data.0.is_closed', false);
        $response->assertJsonPath('data.0.open_time', '09:00');
        $response->assertJsonPath('data.0.close_time', '19:00');
        $response->assertJsonPath('data.6.day_of_week', 6);
    }

    public function test_index_merges_saved_rows_with_defaults(): void
    {
        $user = $this->actingAsSalonUser();
        BusinessHour::factory()->closed()->for($user->salon)->create([
            'day_of_week' => 1,
            'open_time' => '10:00',
            'close_time' => '18:00',
        ]);

        $response = $this->getJson('/api/v1/business-hours');

        $response->assertOk();
        $response->assertJsonCount(7, 'data');
        $response->assertJsonPath('data.1.is_closed', true);
        $response->assertJsonPath('data.1.open_time', '10:00');
        $response->assertJsonPath('data.1.close_time', '18:00');
        $response->assertJsonPath('data.0.is_closed', false);
        $response->assertJsonPath('data.0.open_time', '09:00');
    }

    public function test_index_is_scoped_to_own_salon(): void
    {
        $this->actingAsSalonUser();
        BusinessHour::factory()->closed()->for(Salon::factory())->create(['day_of_week' => 0]);

        $response = $this->getJson('/api/v1/business-hours');

        $response->assertOk();
        $response->assertJsonPath('data.0.is_closed', false);
    }

    public function test_update_replaces_business_hours(): void
    {
        $user = $this->actingAsSalonUser();
        BusinessHour::factory()->for($user->salon)->create([
            'day_of_week' => 0,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $response = $this->putJson('/api/v1/business-hours', [
            'business_hours' => $this->businessHoursPayload([
                1 => ['is_closed' => true],
            ]),
        ]);

        $response->assertOk();
        $response->assertJsonCount(7, 'data');
        $response->assertJsonPath('data.0.open_time', '10:00');
        $response->assertJsonPath('data.1.is_closed', true);
        $this->assertDatabaseCount('business_hours', 7);
        $this->assertDatabaseHas('business_hours', [
            'salon_id' => $user->salon_id,
            'day_of_week' => 1,
            'is_closed' => true,
        ]);
    }

    public function test_update_requires_seven_days(): void
    {
        $this->actingAsSalonUser();

        $payload = $this->businessHoursPayload();
        array_pop($payload);

        $this->putJson('/api/v1/business-hours', ['business_hours' => $payload])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_hours']);
    }

    public function test_update_rejects_duplicated_day_of_week(): void
    {
        $this->actingAsSalonUser();

        $payload = $this->businessHoursPayload();
        $payload[1]['day_of_week'] = 0;

        $this->putJson('/api/v1/business-hours', ['business_hours' => $payload])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_hours.0.day_of_week']);
    }

    public function test_update_rejects_close_time_not_after_open_time(): void
    {
        $this->actingAsSalonUser();

        $payload = $this->businessHoursPayload([
            2 => ['open_time' => '19:00', 'close_time' => '09:00'],
        ]);

        $this->putJson('/api/v1/business-hours', ['business_hours' => $payload])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_hours.2.close_time']);
    }

    public function test_update_does_not_touch_other_salon(): void
    {
        $otherSalon = Salon::factory()->create();
        BusinessHour::factory()->for($otherSalon)->create([
            'day_of_week' => 0,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);
        $this->actingAsSalonUser();

        $this->putJson('/api/v1/business-hours', [
            'business_hours' => $this->businessHoursPayload(),
        ])->assertOk();

        $this->assertDatabaseHas('business_hours', [
            'salon_id' => $otherSalon->id,
            'day_of_week' => 0,
            'open_time' => '08:00:00',
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/business-hours')->assertUnauthorized();
    }

    public function test_update_requires_authentication(): void
    {
        $this->putJson('/api/v1/business-hours', [
            'business_hours' => $this->businessHoursPayload(),
        ])->assertUnauthorized();
    }

    /**
     * 7曜日分のリクエストペイロードを組み立てる。
     */
    private function businessHoursPayload(array $overrides = []): array
    {
        return collect(range(0, 6))
            ->map(fn (int $dayOfWeek) => array_merge([
                'day_of_week' => $dayOfWeek,
                'is_closed' => false,
                'open_time' => '10:00',
                'close_time' => '20:00',
            ], $overrides[$dayOfWeek] ?? []))
            ->values()
            ->all();
    }
}
