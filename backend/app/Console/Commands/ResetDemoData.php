<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionPlan;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * デモ環境のデータを初期状態に戻す（ADR-031）。
 *
 * シーダーは予約日時を実行時の today() で作るため、放置するとダッシュボードが
 * 空に見える。また Checkout を1人が完了すると stripe_subscription_id が入り、
 * 次の見込み客は「すでに契約中です」で弾かれる。見せる相手が変わるたびに実行する。
 *
 * 無料プランには Shell が無く、実行はローカルから DB_URL を develop へ向けて行う。
 * その経路では APP_ENV がローカルの値になり本番判定が効かないため、
 * 接続先の実体（ホストとデータベース名）を確認させるガードを持つ。
 */
class ResetDemoData extends Command
{
    protected $signature = 'demo:reset
        {--plan=pro : シード後のプラン（lite/standard/pro）。lite にするとアップグレード導線を見せられる}
        {--force : 対話確認を省略する。--expect-database が必須になる}
        {--expect-database= : --force のときに接続先データベース名として期待する値}';

    protected $description = 'デモ用データを再投入して初期状態に戻す（本番では実行不可）';

    private const CONFIRM_QUESTION = '全データを削除します。続けるにはデータベース名を入力してください';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:reset は本番環境では実行できません。');

            return self::FAILURE;
        }

        $plan = SubscriptionPlan::tryFrom((string) $this->option('plan'));

        if ($plan === null) {
            $this->error('--plan は lite / standard / pro のいずれかを指定してください。');

            return self::FAILURE;
        }

        $connection = DB::connection();
        $database = $connection->getDatabaseName();

        $this->line('接続先ホスト : '.($connection->getConfig('host') ?? '(unknown)'));
        $this->line("データベース : {$database}");
        $this->newLine();

        if (! $this->confirmTarget($database)) {
            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);

        if ($plan !== SubscriptionPlan::Pro) {
            Subscription::query()->update(['plan' => $plan->value]);
            $this->line("プランを {$plan->label()} に変更しました。");
        }

        $this->info('デモデータを初期状態に戻しました。');

        return self::SUCCESS;
    }

    /**
     * APP_ENV ではなく接続先の実体を確認させる。
     * ローカルから DB_URL を向け違えた場合を捕まえられるのはこの検査だけ。
     */
    private function confirmTarget(string $database): bool
    {
        if ($this->option('force')) {
            if ($this->option('expect-database') !== $database) {
                $this->error("--force を使うときは --expect-database={$database} を指定してください。");

                return false;
            }

            return true;
        }

        if ($this->ask(self::CONFIRM_QUESTION) !== $database) {
            $this->error('データベース名が一致しません。中止しました。');

            return false;
        }

        return true;
    }
}
