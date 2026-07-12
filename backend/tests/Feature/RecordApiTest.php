<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Record;
use App\Models\RecordBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class RecordApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_index_lists_records_of_customer(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        Record::factory()->count(3)->for($customer)->for($user->salon)->for($user)->create();

        $response = $this->getJson("/api/v1/customers/{$customer->id}/records");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [['id', 'status', 'visited_at', 'customer', 'user']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonCount(3, 'data');
    }

    public function test_store_creates_record_with_blocks(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();

        $payload = [
            'visited_at' => '2026-07-09T14:00:00+09:00',
            'status' => 'completed',
            'blocks' => [
                ['label' => '施術内容', 'content' => 'カット', 'sort_order' => 0],
                ['label' => 'カウンセリング', 'content' => '毛先ケア', 'sort_order' => 1],
            ],
        ];

        $response = $this->postJson("/api/v1/customers/{$customer->id}/records", $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'completed');
        $response->assertJsonCount(2, 'data.blocks');
        $this->assertDatabaseHas('records', ['customer_id' => $customer->id, 'status' => 'completed']);
        $this->assertDatabaseHas('record_blocks', ['label' => '施術内容', 'content' => 'カット']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();

        $this->postJson("/api/v1/customers/{$customer->id}/records", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['visited_at', 'status', 'blocks']);
    }

    public function test_show_returns_record_with_blocks(): void
    {
        $user = $this->actingAsSalonUser();
        $record = Record::factory()->for($user->salon)->for($user)
            ->for(Customer::factory()->for($user->salon))->create();
        RecordBlock::factory()->for($record)->create(['label' => '施術内容', 'content' => 'カラー']);

        $this->getJson("/api/v1/records/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.blocks.0.label', '施術内容');
    }

    public function test_update_modifies_record(): void
    {
        $user = $this->actingAsSalonUser();
        $record = Record::factory()->for($user->salon)->for($user)
            ->for(Customer::factory()->for($user->salon))->draft()->create();

        $response = $this->patchJson("/api/v1/records/{$record->id}", ['status' => 'completed']);

        $response->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('records', ['id' => $record->id, 'status' => 'completed']);
    }

    public function test_destroy_soft_deletes_record(): void
    {
        $user = $this->actingAsSalonUser();
        $record = Record::factory()->for($user->salon)->for($user)
            ->for(Customer::factory()->for($user->salon))->create();

        $this->deleteJson("/api/v1/records/{$record->id}")->assertNoContent();
        $this->assertSoftDeleted('records', ['id' => $record->id]);
    }

    public function test_records_are_scoped_to_own_salon(): void
    {
        $user = $this->actingAsSalonUser();
        $otherRecord = Record::factory()->create(); // 別サロン

        $this->getJson("/api/v1/records/{$otherRecord->id}")->assertNotFound();
    }

    public function test_index_requires_authentication(): void
    {
        $customer = Customer::factory()->create();
        $this->getJson("/api/v1/customers/{$customer->id}/records")->assertUnauthorized();
    }
}
