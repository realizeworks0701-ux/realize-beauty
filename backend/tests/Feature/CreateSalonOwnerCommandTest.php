<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class CreateSalonOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function commandOptions(string $email = 'owner@example.com'): array
    {
        return [
            '--salon' => 'Realize Beauty',
            '--phone' => '03-1234-5678',
            '--postal-code' => '150-0001',
            '--address' => '東京都渋谷区神宮前1-1-1',
            '--name' => '山田 太郎',
            '--email' => $email,
        ];
    }

    public function test_creates_owner_with_hashed_password(): void
    {
        $this->artisan('salon:create-owner', $this->commandOptions())
            ->expectsQuestion('パスワード（12文字以上）', 'StrongPassphrase2026')
            ->expectsQuestion('パスワード（確認のため再入力）', 'StrongPassphrase2026')
            ->assertExitCode(0);

        $user = User::where('email', 'owner@example.com')->firstOrFail();

        $this->assertSame(Role::Owner, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotSame('StrongPassphrase2026', $user->password);
        $this->assertTrue(Hash::check('StrongPassphrase2026', $user->password));
    }

    public function test_rejects_password_shorter_than_twelve_characters(): void
    {
        $this->artisan('salon:create-owner', $this->commandOptions())
            ->expectsQuestion('パスワード（12文字以上）', 'password')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_rejects_mismatched_confirmation(): void
    {
        $this->artisan('salon:create-owner', $this->commandOptions())
            ->expectsQuestion('パスワード（12文字以上）', 'StrongPassphrase2026')
            ->expectsQuestion('パスワード（確認のため再入力）', 'DifferentPassphrase2026')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('salon:create-owner', $this->commandOptions())
            ->expectsQuestion('パスワード（12文字以上）', 'StrongPassphrase2026')
            ->assertExitCode(1);
    }

    public function test_database_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);

        $this->app->make(DatabaseSeeder::class)->run();
    }
}
