<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeSalonUser(): User
    {
        $salon = Salon::create([
            'name' => 'テストサロン',
            'phone' => '03-0000-0000',
            'postal_code' => '100-0001',
            'address' => '東京都千代田区',
        ]);

        return User::create([
            'salon_id' => $salon->id,
            'name' => '山田 太郎',
            'email' => 'owner@example.com',
            'password' => 'password',
            'role' => 'owner',
        ]);
    }

    private function makeCustomer(int $salonId, string $name, string $kana): Customer
    {
        return Customer::create([
            'salon_id' => $salonId,
            'name' => $name,
            'kana' => $kana,
        ]);
    }

    public function test_customer_index_returns_paginated_envelope(): void
    {
        $user = $this->makeSalonUser();
        $this->makeCustomer($user->salon_id, '佐藤 花子', 'サトウ ハナコ');
        $this->makeCustomer($user->salon_id, '田中 美咲', 'タナカ ミサキ');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/customers');

        // 修正前は戻り値型エラーで500になっていた回帰テスト
        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                ['id', 'name', 'kana', 'gender', 'birthday', 'phone', 'email', 'memo'],
            ],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonCount(2, 'data');
    }

    public function test_customer_index_filters_by_keyword(): void
    {
        $user = $this->makeSalonUser();
        $this->makeCustomer($user->salon_id, '佐藤 花子', 'サトウ ハナコ');
        $this->makeCustomer($user->salon_id, '田中 美咲', 'タナカ ミサキ');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/customers?keyword=佐藤');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', '佐藤 花子');
    }

    public function test_customer_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }

    public function test_customer_index_is_scoped_to_own_salon(): void
    {
        $user = $this->makeSalonUser();
        $this->makeCustomer($user->salon_id, '自店 顧客', 'ジテン キャク');

        $otherSalon = Salon::create([
            'name' => '他店',
            'phone' => '06-0000-0000',
            'postal_code' => '530-0001',
            'address' => '大阪府大阪市',
        ]);
        $this->makeCustomer($otherSalon->id, '他店 顧客', 'タテン キャク');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', '自店 顧客');
    }
}
