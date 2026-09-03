<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionPlan;
use App\Services\Billing\StripeClient;
use App\Services\Billing\StripeConfigException;
use Illuminate\Console\Command;

/**
 * Stripe 設定の事前診断（ADR-029）。
 *
 * DEV に Live キー、本番に Test キーが入っていないかをデプロイ前に確かめる。
 * 秘密鍵そのものは出力せず、モード（test/live）と設定有無だけを表示する。
 */
class CheckStripeConfig extends Command
{
    protected $signature = 'stripe:check';

    protected $description = 'Stripe のキー・Price ID・Webhook Secret の設定と Live/Test の整合を確認する';

    public function handle(StripeClient $stripeClient): int
    {
        $environment = app()->environment();
        $this->line("APP_ENV: {$environment}");
        $this->line('想定モード: '.(app()->environment('production') ? 'live' : 'test'));
        $this->newLine();

        $ok = true;
        $ok = $this->reportKey('STRIPE_SECRET', config('billing.stripe.secret'), ['sk_test_', 'sk_live_']) && $ok;
        $ok = $this->reportKey('STRIPE_KEY', config('billing.stripe.key'), ['pk_test_', 'pk_live_']) && $ok;
        $ok = $this->reportSecret('STRIPE_WEBHOOK_SECRET', config('billing.stripe.webhook_secret')) && $ok;

        $this->newLine();

        foreach (SubscriptionPlan::cases() as $plan) {
            $priceId = $plan->stripePriceId();

            if ($priceId === null) {
                $this->error(sprintf('%-9s Price ID 未設定', $plan->label()));
                $ok = false;

                continue;
            }

            // Price ID は test/live を見分けられないため、設定されていることのみ確認する。
            $this->info(sprintf('%-9s %s（月額 %s円）', $plan->label(), $this->mask($priceId), number_format($plan->monthlyPrice())));
        }

        $ok = $this->reportDuplicatePriceIds() && $ok;

        $this->newLine();

        try {
            $stripeClient->assertModeMatchesEnvironment();
            $this->info('Live/Test モードと APP_ENV は整合しています。');
        } catch (StripeConfigException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 同じ Price ID を複数プランに設定すると、Stripe から届いた price を
     * どのプランへ写すかが宣言順で決まってしまい、黙って取り違える。
     */
    private function reportDuplicatePriceIds(): bool
    {
        $byPrice = [];

        foreach (SubscriptionPlan::cases() as $plan) {
            $priceId = $plan->stripePriceId();

            if ($priceId !== null) {
                $byPrice[$priceId][] = $plan->label();
            }
        }

        $duplicates = array_filter($byPrice, fn (array $plans) => count($plans) > 1);

        foreach ($duplicates as $priceId => $plans) {
            $this->error(sprintf(
                '%s が %s に重複して設定されています。プランごとに別の Price を用意してください。',
                $this->mask((string) $priceId),
                implode(' / ', $plans),
            ));
        }

        return $duplicates === [];
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function reportKey(string $name, mixed $value, array $prefixes): bool
    {
        if (! is_string($value) || $value === '') {
            $this->error("{$name}: 未設定");

            return false;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $mode = str_contains($prefix, 'test') ? 'test' : 'live';
                $expected = app()->environment('production') ? 'live' : 'test';

                $mode === $expected
                    ? $this->info("{$name}: {$mode} モード")
                    : $this->error("{$name}: {$mode} モード（この環境では {$expected} を使用すること）");

                return $mode === $expected;
            }
        }

        $this->warn("{$name}: 想定外の接頭辞です。");

        return false;
    }

    private function reportSecret(string $name, mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            $this->error("{$name}: 未設定");

            return false;
        }

        $this->info("{$name}: 設定済み（{$this->mask($value)}）");

        return true;
    }

    private function mask(string $value): string
    {
        return mb_strlen($value) <= 8
            ? str_repeat('*', mb_strlen($value))
            : mb_substr($value, 0, 8).str_repeat('*', 6);
    }
}
