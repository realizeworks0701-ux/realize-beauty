<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Models\Salon;
use App\Services\Billing\StripeClient;
use App\Services\Billing\StripeConfigException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresStripe;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

/**
 * DEV と本番で Stripe の接続先が混ざらないことを守る（ADR-029）。
 *
 * 本番に Test キー、開発に Live キーが入った状態で決済フローを走らせない。
 */
class StripeConfigTest extends TestCase
{
    use ConfiguresStripe, CreatesSalonUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStripe();
        Http::preventStrayRequests();
    }

    public function test_test_key_is_accepted_outside_production(): void
    {
        config(['billing.stripe.secret' => 'sk_test_dummy']);

        app(StripeClient::class)->assertModeMatchesEnvironment();

        $this->addToAssertionCount(1);
    }

    public function test_live_key_is_rejected_outside_production(): void
    {
        config(['billing.stripe.secret' => 'sk_live_dummy']);

        $this->expectException(StripeConfigException::class);
        $this->expectExceptionMessage('本番以外の環境に Stripe の Live キーが設定されています。');

        app(StripeClient::class)->assertModeMatchesEnvironment();
    }

    public function test_test_key_is_rejected_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['billing.stripe.secret' => 'sk_test_dummy']);

        $this->expectException(StripeConfigException::class);
        $this->expectExceptionMessage('本番環境に Stripe の Test キーが設定されています。');

        app(StripeClient::class)->assertModeMatchesEnvironment();
    }

    public function test_live_key_is_accepted_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['billing.stripe.secret' => 'sk_live_dummy']);

        app(StripeClient::class)->assertModeMatchesEnvironment();

        $this->addToAssertionCount(1);
    }

    /**
     * 取り違えたまま Checkout を始めさせない。API 呼び出しの手前で止める。
     */
    public function test_checkout_is_blocked_when_the_key_mode_does_not_match(): void
    {
        config(['billing.stripe.secret' => 'sk_live_dummy']);
        $this->actingAsSalonUser(Salon::factory()->withoutSubscription()->create());

        $this->expectException(StripeConfigException::class);

        $this->withoutExceptionHandling()
            ->postJson('/api/v1/subscription/checkout', ['plan' => 'lite']);
    }

    public function test_missing_secret_is_reported_clearly(): void
    {
        config(['billing.stripe.secret' => null]);

        $this->expectException(StripeConfigException::class);
        $this->expectExceptionMessage('STRIPE_SECRET が設定されていません。');

        app(StripeClient::class)->assertModeMatchesEnvironment();
    }

    public function test_checkout_fails_when_the_plan_has_no_price_id(): void
    {
        config(['billing.plans.'.SubscriptionPlan::Pro->value.'.stripe_price_id' => null]);
        $this->actingAsSalonUser(Salon::factory()->withoutSubscription()->create());

        $this->expectException(StripeConfigException::class);

        $this->withoutExceptionHandling()
            ->postJson('/api/v1/subscription/checkout', ['plan' => 'pro']);
    }

    /**
     * Price ID が未設定のプランは購入導線を出さない。
     */
    public function test_plan_catalog_marks_a_plan_without_price_as_not_purchasable(): void
    {
        config(['billing.plans.'.SubscriptionPlan::Pro->value.'.stripe_price_id' => null]);
        $this->actingAsSalonUser(Salon::factory()->create());

        $response = $this->getJson('/api/v1/subscription');

        $response->assertJsonPath('data.plans.0.is_purchasable', true);
        $response->assertJsonPath('data.plans.2.code', 'pro');
        $response->assertJsonPath('data.plans.2.is_purchasable', false);
    }

    /**
     * 同じ Price を複数プランに割り当てると、プランが黙って取り違えられる。
     */
    public function test_stripe_check_command_detects_duplicate_price_ids(): void
    {
        config(['billing.plans.'.SubscriptionPlan::Pro->value.'.stripe_price_id' => self::PRICE_STANDARD]);

        $this->artisan('stripe:check')->assertFailed();
    }

    public function test_stripe_check_command_fails_on_a_mode_mismatch(): void
    {
        config(['billing.stripe.secret' => 'sk_live_dummy']);

        $this->artisan('stripe:check')->assertFailed();
    }

    public function test_stripe_check_command_succeeds_when_everything_is_configured(): void
    {
        $this->artisan('stripe:check')->assertSuccessful();
    }

    /**
     * 秘密鍵そのものを出力しない。
     */
    public function test_stripe_check_command_does_not_print_the_secret(): void
    {
        $this->artisan('stripe:check')
            ->doesntExpectOutputToContain('sk_test_dummy')
            ->assertSuccessful();
    }
}
