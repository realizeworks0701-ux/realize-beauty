<?php

namespace App\Enums;

/**
 * 契約プラン（ADR-029）。
 *
 * case の宣言順は安い順とする。Feature::minimumPlan() が
 * この順序に依存して「その機能を含む最も安いプラン」を求める。
 *
 * 月額・Stripe Price ID・利用可能機能はすべて config/billing.php を参照し、
 * このクラスには持たせない（プラン内容の変更をコード変更なしで行えるようにするため）。
 */
enum SubscriptionPlan: string
{
    case Lite = 'lite';
    case Standard = 'standard';
    case Pro = 'pro';

    public function label(): string
    {
        return (string) $this->config('label', ucfirst($this->value));
    }

    /**
     * 月額（税込・円）。
     */
    public function monthlyPrice(): int
    {
        return (int) $this->config('monthly_price', 0);
    }

    /**
     * Stripe の Price ID。未設定なら null（テスト環境・未セットアップ時）。
     */
    public function stripePriceId(): ?string
    {
        $priceId = $this->config('stripe_price_id');

        return is_string($priceId) && $priceId !== '' ? $priceId : null;
    }

    /**
     * @return list<Feature>
     */
    public function features(): array
    {
        $keys = $this->config('features', []);

        return array_values(array_filter(array_map(
            fn (string $key) => Feature::tryFrom($key),
            is_array($keys) ? $keys : [],
        )));
    }

    public function includes(Feature $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * この機能を含むプランをすべて返す。
     * 「機能を持つサロン」をSQLで絞り込むための whereIn 用（config が唯一の根拠であることは変わらない）。
     *
     * @return list<self>
     */
    public static function withFeature(Feature $feature): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $plan) => $plan->includes($feature),
        ));
    }

    /**
     * @return list<string>
     */
    public static function valuesWithFeature(Feature $feature): array
    {
        return array_map(fn (self $plan) => $plan->value, self::withFeature($feature));
    }

    /**
     * Stripe の Price ID から契約プランを引く。
     * Webhook や Checkout 完了時に、Stripe が返した price をアプリのプランへ変換する唯一の入口。
     */
    public static function fromStripePriceId(string $priceId): ?self
    {
        if ($priceId === '') {
            return null;
        }

        foreach (self::cases() as $plan) {
            if ($plan->stripePriceId() === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("billing.plans.{$this->value}.{$key}", $default);
    }
}
