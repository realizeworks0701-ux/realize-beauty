<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * デモ環境のリセット（ADR-031）。
 *
 * 無料プランには Shell が無いため、このコマンドはローカルから DB_URL を
 * develop に向けて実行される。その経路では APP_ENV はローカルの値になり
 * 本番判定が効かないため、接続先の実体を確認させるガードが要になる。
 */
class ResetDemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('demo:reset', ['--force' => true, '--expect-database' => DB::connection()->getDatabaseName()])
            ->expectsOutputToContain('本番環境では実行できません')
            ->assertExitCode(1);
    }

    public function test_it_refuses_when_the_expected_database_name_does_not_match(): void
    {
        $this->artisan('demo:reset', ['--force' => true, '--expect-database' => 'realize_beauty'])
            ->expectsOutputToContain('--expect-database')
            ->assertExitCode(1);
    }

    public function test_it_refuses_force_without_an_expected_database_name(): void
    {
        $this->artisan('demo:reset', ['--force' => true])
            ->assertExitCode(1);
    }

    public function test_it_rejects_an_unknown_plan(): void
    {
        $this->artisan('demo:reset', [
            '--plan' => 'platinum',
            '--force' => true,
            '--expect-database' => DB::connection()->getDatabaseName(),
        ])
            ->expectsOutputToContain('--plan')
            ->assertExitCode(1);
    }

    /**
     * 5件のガードテストはいずれも migrate:fresh の手前で終了するため、
     * 唯一このテストだけが実際に demo:reset を最後まで走らせる。
     * --plan=lite でのプラン切り替え（Subscription::query()->update() が
     * enum キャストを経ずに書き込む経路）を実データで確認する。
     */
    public function test_it_switches_to_the_requested_plan_after_reseeding(): void
    {
        $this->artisan('demo:reset', [
            '--plan' => 'lite',
            '--force' => true,
            '--expect-database' => DB::connection()->getDatabaseName(),
        ])->assertExitCode(0);

        $this->assertSame('lite', DB::table('subscriptions')->value('plan'));
    }

    public function test_it_asks_for_the_database_name_when_not_forced(): void
    {
        $this->artisan('demo:reset')
            ->expectsQuestion('全データを削除します。続けるにはデータベース名を入力してください', 'wrong_name')
            ->expectsOutputToContain('データベース名が一致しません')
            ->assertExitCode(1);
    }
}
