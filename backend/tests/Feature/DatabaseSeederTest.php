<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * デモ用シードのログイン情報が docs/deployment.md の記述と一致することを守る。
 * develop 環境のログイン情報を見込み客に渡すため、食い違うと詰む。
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeded_owner_can_log_in_with_the_documented_password(): void
    {
        $this->seed();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertOk();
    }
}
