<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Salon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_index_returns_paginated_envelope(): void
    {
        $user = $this->actingAsSalonUser();
        Customer::factory()->count(2)->for($user->salon)->create();

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [['id', 'name', 'kana', 'gender', 'birthday', 'phone', 'email', 'memo']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_keyword(): void
    {
        $user = $this->actingAsSalonUser();
        Customer::factory()->for($user->salon)->create(['name' => '佐藤 花子', 'kana' => 'サトウ ハナコ']);
        Customer::factory()->for($user->salon)->create(['name' => '田中 美咲', 'kana' => 'タナカ ミサキ']);

        $response = $this->getJson('/api/v1/customers?keyword=佐藤');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', '佐藤 花子');
    }

    public function test_index_is_scoped_to_own_salon(): void
    {
        $user = $this->actingAsSalonUser();
        Customer::factory()->for($user->salon)->create(['name' => '自店 顧客']);
        Customer::factory()->for(Salon::factory())->create(['name' => '他店 顧客']);

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', '自店 顧客');
    }

    public function test_store_creates_customer(): void
    {
        $user = $this->actingAsSalonUser();

        $response = $this->postJson('/api/v1/customers', [
            'name' => '結城 あかり',
            'kana' => 'ユウキ アカリ',
            'gender' => 2,
            'phone' => '090-1111-2222',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', '結城 あかり');
        $this->assertDatabaseHas('customers', [
            'salon_id' => $user->salon_id,
            'name' => '結城 あかり',
            'kana' => 'ユウキ アカリ',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAsSalonUser();

        $this->postJson('/api/v1/customers', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'kana']);
    }

    public function test_show_returns_customer(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create(['name' => '高橋 結衣']);

        $this->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.name', '高橋 結衣');
    }

    public function test_update_modifies_customer(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'name' => '更新 太郎',
            'kana' => 'コウシン タロウ',
        ]);

        $response->assertOk()->assertJsonPath('data.name', '更新 太郎');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => '更新 太郎']);
    }

    public function test_destroy_soft_deletes_customer(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertNoContent();
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_show_is_scoped_to_own_salon(): void
    {
        $this->actingAsSalonUser();
        $otherCustomer = Customer::factory()->for(Salon::factory())->create();

        $this->getJson("/api/v1/customers/{$otherCustomer->id}")->assertNotFound();
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }
}
