<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Models\Customer;
use App\Models\Record;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class SalonRecordApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_index_all_returns_paginated_envelope(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $this->createRecord($customer, '2026-08-01 10:00:00');
        $this->createRecord($customer, '2026-08-02 10:00:00');

        $response = $this->getJson('/api/v1/records');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [['id', 'status', 'visited_at', 'customer' => ['id', 'name', 'kana', 'phone'], 'user' => ['id', 'name']]],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonCount(2, 'data');
    }

    public function test_index_all_is_scoped_to_own_salon(): void
    {
        $user = $this->actingAsSalonUser();
        $ownCustomer = Customer::factory()->for($user->salon)->create(['name' => '自店 顧客']);
        $this->createRecord($ownCustomer, '2026-08-01 10:00:00');

        $otherCustomer = Customer::factory()->for(Salon::factory())->create(['name' => '他店 顧客']);
        $this->createRecord($otherCustomer, '2026-08-02 10:00:00');

        $response = $this->getJson('/api/v1/records');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.customer.name', '自店 顧客');
    }

    public function test_index_all_requires_authentication(): void
    {
        $this->getJson('/api/v1/records')->assertUnauthorized();
    }

    public function test_index_all_filters_by_status(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $draft = $this->createRecord($customer, '2026-08-01 10:00:00', RecordStatus::Draft);
        $this->createRecord($customer, '2026-08-02 10:00:00', RecordStatus::Completed);

        $response = $this->getJson('/api/v1/records?status=draft');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $draft->id);
    }

    public function test_index_all_filters_by_customer_name_keyword(): void
    {
        $user = $this->actingAsSalonUser();
        $target = Customer::factory()->for($user->salon)->create(['name' => '佐藤 花子', 'kana' => 'サトウ ハナコ']);
        $other = Customer::factory()->for($user->salon)->create(['name' => '田中 美咲', 'kana' => 'タナカ ミサキ']);
        $this->createRecord($target, '2026-08-01 10:00:00');
        $this->createRecord($other, '2026-08-02 10:00:00');

        // 実クライアント同様にURLエンコードする（生のマルチバイトはURIパーサ依存で壊れる）
        $response = $this->getJson('/api/v1/records?keyword='.urlencode('佐藤'));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.customer.name', '佐藤 花子');
    }

    public function test_index_all_filters_by_customer_kana_keyword(): void
    {
        $user = $this->actingAsSalonUser();
        $target = Customer::factory()->for($user->salon)->create(['name' => '佐藤 花子', 'kana' => 'サトウ ハナコ']);
        $other = Customer::factory()->for($user->salon)->create(['name' => '田中 美咲', 'kana' => 'タナカ ミサキ']);
        $this->createRecord($target, '2026-08-01 10:00:00');
        $this->createRecord($other, '2026-08-02 10:00:00');

        $response = $this->getJson('/api/v1/records?keyword='.urlencode('ハナコ'));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.customer.kana', 'サトウ ハナコ');
    }

    public function test_index_all_orders_by_visited_at_desc_then_id_desc(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $oldest = $this->createRecord($customer, '2026-08-01 10:00:00');
        $sameDayFirst = $this->createRecord($customer, '2026-08-10 10:00:00');
        $sameDaySecond = $this->createRecord($customer, '2026-08-10 10:00:00');

        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/records');
        $orderedQuery = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(fn (string $sql) => str_contains($sql, 'order by'));
        DB::disableQueryLog();

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $sameDaySecond->id);
        $response->assertJsonPath('data.1.id', $sameDayFirst->id);
        $response->assertJsonPath('data.2.id', $oldest->id);

        // 同一 visited_at の並びは実行計画次第で偶然一致しうるため、並び順の指定そのものを検証する
        $this->assertStringContainsString('order by "visited_at" desc, "id" desc', (string) $orderedQuery);
    }

    public function test_index_all_excludes_records_of_soft_deleted_customer(): void
    {
        $user = $this->actingAsSalonUser();
        $activeCustomer = Customer::factory()->for($user->salon)->create(['name' => '在籍 顧客']);
        $deletedCustomer = Customer::factory()->for($user->salon)->create(['name' => '削除済 顧客']);
        $this->createRecord($activeCustomer, '2026-08-01 10:00:00');
        $this->createRecord($deletedCustomer, '2026-08-02 10:00:00');
        $deletedCustomer->delete();

        $response = $this->getJson('/api/v1/records');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.customer.name', '在籍 顧客');
    }

    public function test_index_all_rejects_status_out_of_enum(): void
    {
        $this->actingAsSalonUser();

        $this->getJson('/api/v1/records?status=unknown')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/v1/records?status=')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_index_all_rejects_per_page_over_limit(): void
    {
        $this->actingAsSalonUser();

        $this->getJson('/api/v1/records?per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_index_all_respects_per_page(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $this->createRecord($customer, '2026-08-01 10:00:00');
        $this->createRecord($customer, '2026-08-02 10:00:00');
        $this->createRecord($customer, '2026-08-03 10:00:00');

        $response = $this->getJson('/api/v1/records?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.last_page', 2);
    }

    /**
     * 顧客のサロンにカルテを揃えて作成する（RecordFactory 既定は別サロンの顧客を作るため）。
     */
    private function createRecord(Customer $customer, string $visitedAt, RecordStatus $status = RecordStatus::Completed): Record
    {
        $salon = $customer->salon;

        return Record::factory()
            ->for($salon)
            ->for($customer)
            ->for(User::factory()->for($salon))
            ->create(['visited_at' => $visitedAt, 'status' => $status]);
    }
}
