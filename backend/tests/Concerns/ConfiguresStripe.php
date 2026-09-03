<?php

namespace Tests\Concerns;

use App\Enums\SubscriptionPlan;
use Illuminate\Support\Carbon;

/**
 * Stripe を使うテストの共通設定。
 * 実際の Stripe へは接続せず、Http::fake() で応答を差し替える前提。
 */
trait ConfiguresStripe
{
    protected const PRICE_LITE = 'price_test_lite';

    protected const PRICE_STANDARD = 'price_test_standard';

    protected const PRICE_PRO = 'price_test_pro';

    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function configureStripe(array $overrides = []): void
    {
        config([
            'billing.stripe.secret' => 'sk_test_dummy',
            'billing.stripe.key' => 'pk_test_dummy',
            'billing.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'billing.stripe.api_base_url' => 'https://api.stripe.com',
            'billing.stripe.webhook_tolerance' => 300,
            'billing.stripe.enforce_mode' => true,
            'billing.plans.'.SubscriptionPlan::Lite->value.'.stripe_price_id' => self::PRICE_LITE,
            'billing.plans.'.SubscriptionPlan::Standard->value.'.stripe_price_id' => self::PRICE_STANDARD,
            'billing.plans.'.SubscriptionPlan::Pro->value.'.stripe_price_id' => self::PRICE_PRO,
            ...$overrides,
        ]);
    }

    protected function priceIdFor(SubscriptionPlan $plan): string
    {
        return match ($plan) {
            SubscriptionPlan::Lite => self::PRICE_LITE,
            SubscriptionPlan::Standard => self::PRICE_STANDARD,
            SubscriptionPlan::Pro => self::PRICE_PRO,
        };
    }

    /**
     * Stripe が返す subscription オブジェクトの最小形。
     *
     * @return array<string, mixed>
     */
    protected function stripeSubscription(array $overrides = []): array
    {
        $now = Carbon::now()->utc();

        return array_replace_recursive([
            'id' => 'sub_test_1',
            'object' => 'subscription',
            'customer' => 'cus_test_1',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
            'trial_end' => null,
            'current_period_start' => $now->getTimestamp(),
            'current_period_end' => $now->copy()->addMonth()->getTimestamp(),
            'metadata' => [],
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_test_1',
                    'object' => 'subscription_item',
                    'price' => ['id' => self::PRICE_STANDARD, 'object' => 'price'],
                ]],
            ],
        ], $overrides);
    }

    /**
     * Stripe の Webhook イベント本文と、それに対応する正しい署名ヘッダを組み立てる。
     *
     * イベントの発生時刻（created）と署名の時刻（t）は別物である。
     * Stripe は再送のたびに新しい t で署名し直すため、
     * 「古いイベントが遅れて届く」状況は created だけを過去にして表す。
     *
     * @return array{0: string, 1: string}
     */
    protected function signedWebhook(
        string $type,
        array $object,
        string $eventId = 'evt_test_1',
        ?int $timestamp = null,
        ?Carbon $createdAt = null,
    ): array {
        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created' => ($createdAt ?? Carbon::now()->utc())->getTimestamp(),
            'data' => ['object' => $object],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $timestamp ??= Carbon::now()->utc()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        return [$payload, "t={$timestamp},v1={$signature}"];
    }
}
