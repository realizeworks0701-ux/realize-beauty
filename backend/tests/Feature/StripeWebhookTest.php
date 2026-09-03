<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Salon;
use App\Models\StripeWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresStripe;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

/**
 * Stripe Webhook による契約状態の同期（ADR-029）。
 *
 * 署名検証・冪等性・payload を鵜呑みにしないことを確かめる。
 */
class StripeWebhookTest extends TestCase
{
    use ConfiguresStripe, CreatesSalonUsers, RefreshDatabase;

    private const URI = '/api/webhooks/stripe';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStripe();
        Http::preventStrayRequests();
    }

    // ---- 署名検証 -------------------------------------------

    public function test_rejects_a_request_without_a_signature_header(): void
    {
        [$payload] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription());

        $this->call('POST', self::URI, [], [], [], $this->jsonHeaders(), $payload)
            ->assertStatus(400);

        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_rejects_a_forged_signature(): void
    {
        [$payload] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription());
        $forged = 't='.Carbon::now()->utc()->getTimestamp().',v1='.str_repeat('0', 64);

        $this->postWebhook($payload, $forged)->assertStatus(400);

        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    /**
     * 古い署名の再送（リプレイ）は許容時刻差で弾く。
     */
    public function test_rejects_a_signature_outside_the_tolerance_window(): void
    {
        $stale = Carbon::now()->utc()->getTimestamp() - 3600;
        [$payload, $signature] = $this->signedWebhook(
            'customer.subscription.updated',
            $this->stripeSubscription(),
            timestamp: $stale,
        );

        $this->postWebhook($payload, $signature)->assertStatus(400);
    }

    public function test_rejects_when_the_webhook_secret_is_not_configured(): void
    {
        [$payload, $signature] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription());
        config(['billing.stripe.webhook_secret' => null]);

        $this->postWebhook($payload, $signature)->assertStatus(400);
    }

    // ---- 契約の同期 -----------------------------------------

    public function test_subscription_updated_syncs_plan_status_and_period(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1', 'stripe_customer_id' => 'cus_test_1']);
        $periodEnd = Carbon::now()->utc()->addMonth()->startOfSecond();

        [$payload, $signature] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription([
            'status' => 'active',
            'current_period_end' => $periodEnd->getTimestamp(),
            'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
        ]));

        $this->postWebhook($payload, $signature)->assertOk();

        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame(SubscriptionPlan::Pro, $subscription->plan);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(self::PRICE_PRO, $subscription->stripe_price_id);
        $this->assertTrue($periodEnd->equalTo($subscription->current_period_end));
    }

    /**
     * stripe_subscription_id が未設定でも metadata.salon_id からサロンを解決できる。
     */
    public function test_subscription_created_binds_to_the_salon_via_metadata(): void
    {
        $salon = Salon::factory()->withoutStripe()->onPlan(SubscriptionPlan::Lite, SubscriptionStatus::Incomplete)->create();

        [$payload, $signature] = $this->signedWebhook('customer.subscription.created', $this->stripeSubscription([
            'metadata' => ['salon_id' => (string) $salon->id],
            'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_STANDARD]]]],
        ]));

        $this->postWebhook($payload, $signature)->assertOk();

        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame(SubscriptionPlan::Standard, $subscription->plan);
        $this->assertSame('sub_test_1', $subscription->stripe_subscription_id);
        $this->assertSame('cus_test_1', $subscription->stripe_customer_id);
        $this->assertDatabaseHas('subscription_events', [
            'salon_id' => $salon->id,
            'type' => 'started',
            'to_plan' => 'standard',
        ]);
    }

    public function test_subscription_deleted_ends_the_contract_and_revokes_access(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Pro)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);
        $endedAt = Carbon::now()->utc()->startOfSecond();

        [$payload, $signature] = $this->signedWebhook('customer.subscription.deleted', $this->stripeSubscription([
            'status' => 'canceled',
            'canceled_at' => $endedAt->getTimestamp(),
            'ended_at' => $endedAt->getTimestamp(),
        ]));

        $this->postWebhook($payload, $signature)->assertOk();

        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertNotNull($subscription->ended_at);
        $this->assertDatabaseHas('subscription_events', ['salon_id' => $salon->id, 'type' => 'ended']);

        // 契約終了で利用停止になるが、顧客データは残る
        $this->actingAsSalonUser($salon);
        $this->getJson('/api/v1/customers')->assertForbidden();
        $this->assertDatabaseHas('salons', ['id' => $salon->id]);
    }

    public function test_unpaid_status_suspends_the_salon(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Pro)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);

        [$payload, $signature] = $this->signedWebhook(
            'customer.subscription.updated',
            $this->stripeSubscription(['status' => 'unpaid']),
        );

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertDatabaseHas('subscription_events', ['salon_id' => $salon->id, 'type' => 'suspended']);
        $this->actingAsSalonUser($salon);
        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    /**
     * 支払い失敗では止めない。Stripe の回収・再試行に委ねる。
     */
    public function test_payment_failed_is_recorded_without_revoking_access(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Pro)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1', 'stripe_customer_id' => 'cus_test_1']);

        [$payload, $signature] = $this->signedWebhook('invoice.payment_failed', [
            'id' => 'in_test_1',
            'object' => 'invoice',
            'customer' => 'cus_test_1',
            'subscription' => 'sub_test_1',
        ]);

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertDatabaseHas('subscription_events', ['salon_id' => $salon->id, 'type' => 'payment_failed']);
        $this->actingAsSalonUser($salon);
        $this->getJson('/api/v1/customers')->assertOk();
    }

    public function test_checkout_session_completed_binds_the_customer_and_pulls_the_subscription(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::response($this->stripeSubscription([
                'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
            ])),
        ]);
        $salon = Salon::factory()->withoutStripe()->onPlan(SubscriptionPlan::Lite, SubscriptionStatus::Incomplete)->create();

        [$payload, $signature] = $this->signedWebhook('checkout.session.completed', [
            'id' => 'cs_test_1',
            'object' => 'checkout.session',
            'customer' => 'cus_test_1',
            'subscription' => 'sub_test_1',
            'client_reference_id' => (string) $salon->id,
            'metadata' => ['salon_id' => (string) $salon->id, 'plan' => 'pro'],
        ]);

        $this->postWebhook($payload, $signature)->assertOk();

        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame('cus_test_1', $subscription->stripe_customer_id);
        $this->assertSame(SubscriptionPlan::Pro, $subscription->plan);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    /**
     * 契約行がまだ無いサロンでも、決済が済んだ以上は必ず契約を起こす。
     * ここで取りこぼすと「課金されたのに全機能 403」から復旧できない。
     */
    public function test_checkout_creates_the_contract_for_a_salon_that_had_none(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::response($this->stripeSubscription([
                'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
            ])),
        ]);
        $salon = Salon::factory()->withoutSubscription()->create();

        [$payload, $signature] = $this->signedWebhook('checkout.session.completed', [
            'id' => 'cs_test_1',
            'object' => 'checkout.session',
            'customer' => 'cus_test_1',
            'subscription' => 'sub_test_1',
            'client_reference_id' => (string) $salon->id,
            'metadata' => ['salon_id' => (string) $salon->id, 'plan' => 'pro'],
        ]);

        $this->postWebhook($payload, $signature)->assertOk();

        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame(SubscriptionPlan::Pro, $subscription->plan);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('cus_test_1', $subscription->stripe_customer_id);

        $this->actingAsSalonUser($salon);
        $this->getJson('/api/v1/customers')->assertOk();
    }

    /**
     * checkout.session.completed が届かず subscription.created だけが来た場合も同じ。
     */
    public function test_subscription_created_creates_the_contract_for_a_salon_that_had_none(): void
    {
        $salon = Salon::factory()->withoutSubscription()->create();

        [$payload, $signature] = $this->signedWebhook('customer.subscription.created', $this->stripeSubscription([
            'metadata' => ['salon_id' => (string) $salon->id],
            'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_STANDARD]]]],
        ]));

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertSame(SubscriptionPlan::Standard, $salon->subscription()->firstOrFail()->plan);
    }

    /**
     * 実在しないサロンIDの metadata で契約行を作らせない（FK違反で再送ループにもしない）。
     */
    public function test_an_event_naming_a_nonexistent_salon_is_skipped_without_creating_rows(): void
    {
        [$payload, $signature] = $this->signedWebhook('customer.subscription.created', $this->stripeSubscription([
            'metadata' => ['salon_id' => '999999'],
        ]), eventId: 'evt_ghost_1');

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => 'evt_ghost_1', 'status' => 'skipped']);
    }

    /**
     * Stripe は Webhook の順序を保証しない。解約を処理したあとに遅れて届いた
     * 古い updated が契約を復活させてはならない。
     */
    public function test_a_late_arriving_older_event_does_not_revive_a_canceled_contract(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Pro)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);

        // 先に「解約」（新しいイベント）を処理する
        [$cancelPayload, $cancelSignature] = $this->signedWebhook(
            'customer.subscription.deleted',
            $this->stripeSubscription(['status' => 'canceled']),
            eventId: 'evt_newer',
        );
        $this->postWebhook($cancelPayload, $cancelSignature)->assertOk();
        $this->assertSame(SubscriptionStatus::Canceled, $salon->subscription()->firstOrFail()->status);

        // 10分前に発生していた active の通知が、いま遅れて届く（署名自体は新しい）
        [$stalePayload, $staleSignature] = $this->signedWebhook(
            'customer.subscription.updated',
            $this->stripeSubscription(['status' => 'active']),
            eventId: 'evt_older',
            createdAt: Carbon::now()->utc()->subMinutes(10),
        );

        $this->postWebhook($stalePayload, $staleSignature)->assertOk();

        $this->assertSame(SubscriptionStatus::Canceled, $salon->subscription()->firstOrFail()->status);
        $this->actingAsSalonUser($salon);
        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    /**
     * 逆に、新しいイベントは通常どおり適用される。
     */
    public function test_a_newer_event_is_applied_after_an_older_one(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);

        [$first, $firstSig] = $this->signedWebhook(
            'customer.subscription.updated',
            $this->stripeSubscription(['items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_STANDARD]]]]]),
            eventId: 'evt_first',
            createdAt: Carbon::now()->utc()->subMinutes(10),
        );
        $this->postWebhook($first, $firstSig)->assertOk();

        [$second, $secondSig] = $this->signedWebhook(
            'customer.subscription.updated',
            $this->stripeSubscription(['items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]]]),
            eventId: 'evt_second',
        );
        $this->postWebhook($second, $secondSig)->assertOk();

        $this->assertSame(SubscriptionPlan::Pro, $salon->subscription()->firstOrFail()->plan);
    }

    /**
     * Checkout を途中で放棄すると、その subscription は約24時間後に incomplete_expired になる。
     * その間に本契約を済ませていると期限切れ通知のほうが新しいイベントとして届くため、
     * サロン一致だけで引き当てると生きている契約を壊して課金中のサロンを止めてしまう。
     */
    public function test_an_abandoned_subscriptions_expiry_does_not_clobber_the_live_contract(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Pro)->create();
        $salon->subscription()->update([
            'stripe_subscription_id' => 'sub_live',
            'stripe_customer_id' => 'cus_test_1',
            'last_stripe_event_at' => Carbon::now()->utc()->subHours(23),
        ]);

        [$payload, $signature] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription([
            'id' => 'sub_abandoned',
            'customer' => 'cus_test_1',
            'status' => 'incomplete_expired',
            'metadata' => ['salon_id' => (string) $salon->id],
            'items' => ['data' => [['id' => 'si_old', 'price' => ['id' => self::PRICE_LITE]]]],
        ]), eventId: 'evt_abandoned_expired');

        $this->postWebhook($payload, $signature)->assertOk();

        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame('sub_live', $subscription->stripe_subscription_id);
        $this->assertSame(SubscriptionPlan::Pro, $subscription->plan);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);

        $this->actingAsSalonUser($salon);
        $this->getJson('/api/v1/customers')->assertOk();
    }

    /**
     * バックフィル移行と salon:create-owner が作る契約は stripe 未連携のまま status=active。
     * 本番の既存サロンはすべてこの形なので、ここを守れないと 3DS 中断ひとつで全社的に止まる。
     */
    public function test_an_incomplete_checkout_does_not_break_a_live_but_unlinked_contract(): void
    {
        $salon = Salon::factory()->withoutStripe()->onPlan(SubscriptionPlan::Pro)->create();
        $this->actingAsSalonUser($salon);
        $this->getJson('/api/v1/customers')->assertOk();

        // 3D Secure を途中でやめた Checkout が生む incomplete な subscription
        [$payload, $signature] = $this->signedWebhook('customer.subscription.created', $this->stripeSubscription([
            'id' => 'sub_abandoned',
            'status' => 'incomplete',
            'metadata' => ['salon_id' => (string) $salon->id],
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => self::PRICE_STANDARD]]]],
        ]), eventId: 'evt_incomplete_created');

        $this->postWebhook($payload, $signature)->assertOk();

        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame(SubscriptionPlan::Pro, $subscription->plan);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->stripe_subscription_id);
        $this->getJson('/api/v1/customers')->assertOk();
    }

    /**
     * 24時間後の期限切れ通知でも同じ（同一の放棄契約が状態を固定してしまわない）。
     */
    public function test_an_expired_checkout_does_not_break_a_live_but_unlinked_contract(): void
    {
        $salon = Salon::factory()->withoutStripe()->onPlan(SubscriptionPlan::Pro)->create();

        [$payload, $signature] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription([
            'id' => 'sub_abandoned',
            'status' => 'incomplete_expired',
            'metadata' => ['salon_id' => (string) $salon->id],
        ]), eventId: 'evt_incomplete_expired');

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertSame(SubscriptionStatus::Active, $salon->subscription()->firstOrFail()->status);
    }

    /**
     * 未連携のまま本契約を完了した場合は、有効な契約として正しく紐づく。
     */
    public function test_a_completed_checkout_links_to_a_live_but_unlinked_contract(): void
    {
        $salon = Salon::factory()->withoutStripe()->onPlan(SubscriptionPlan::Pro)->create();

        [$payload, $signature] = $this->signedWebhook('customer.subscription.created', $this->stripeSubscription([
            'id' => 'sub_paid',
            'status' => 'active',
            'metadata' => ['salon_id' => (string) $salon->id],
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => self::PRICE_STANDARD]]]],
        ]), eventId: 'evt_paid');

        $this->postWebhook($payload, $signature)->assertOk();

        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame('sub_paid', $subscription->stripe_subscription_id);
        $this->assertSame(SubscriptionPlan::Standard, $subscription->plan);
    }

    /**
     * 逆に、解約後の再契約（別サブスクリプションへの正当な乗り換え）は引き継げる。
     */
    public function test_a_new_active_subscription_takes_over_a_canceled_one(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite, SubscriptionStatus::Canceled)->create();
        $salon->subscription()->update([
            'stripe_subscription_id' => 'sub_old',
            'stripe_customer_id' => 'cus_test_1',
        ]);

        [$payload, $signature] = $this->signedWebhook('customer.subscription.created', $this->stripeSubscription([
            'id' => 'sub_new',
            'customer' => 'cus_test_1',
            'status' => 'active',
            'metadata' => ['salon_id' => (string) $salon->id],
            'items' => ['data' => [['id' => 'si_new', 'price' => ['id' => self::PRICE_PRO]]]],
        ]), eventId: 'evt_resubscribe');

        $this->postWebhook($payload, $signature)->assertOk();

        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame('sub_new', $subscription->stripe_subscription_id);
        $this->assertSame(SubscriptionPlan::Pro, $subscription->plan);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    // ---- 冪等性 ---------------------------------------------

    public function test_a_duplicate_event_is_processed_only_once(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);

        [$payload, $signature] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription([
            'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
        ]), eventId: 'evt_duplicate');

        $this->postWebhook($payload, $signature)->assertOk();
        $this->postWebhook($payload, $signature)->assertOk();
        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertDatabaseCount('stripe_webhook_events', 1);
        // 状態遷移の監査ログも1回だけ
        $this->assertDatabaseCount('subscription_events', 1);
        $this->assertSame(SubscriptionPlan::Pro, $salon->subscription()->firstOrFail()->plan);
    }

    /**
     * 処理中にプロセスが落ちた（processing のまま残った）イベントは、
     * Stripe の再送で復旧できなければならない。握りつぶすと契約状態が永久にずれる。
     */
    public function test_an_event_abandoned_mid_processing_is_retried_on_redelivery(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);

        // 処理中に異常終了した痕跡
        StripeWebhookEvent::create([
            'stripe_event_id' => 'evt_stale_1',
            'type' => 'customer.subscription.updated',
            'status' => StripeWebhookEvent::STATUS_PROCESSING,
            'occurred_at' => Carbon::now()->utc()->subHour(),
        ]);
        StripeWebhookEvent::where('stripe_event_id', 'evt_stale_1')
            ->update(['updated_at' => Carbon::now()->utc()->subHour()]);

        [$payload, $signature] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription([
            'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
        ]), eventId: 'evt_stale_1');

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertSame(SubscriptionPlan::Pro, $salon->subscription()->firstOrFail()->plan);
        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_stale_1',
            'status' => 'processed',
        ]);
    }

    /**
     * 処理中の記録が新しいうちは同時実行とみなして再処理しない。
     */
    public function test_a_recently_claimed_event_is_not_processed_twice(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);

        StripeWebhookEvent::create([
            'stripe_event_id' => 'evt_inflight_1',
            'type' => 'customer.subscription.updated',
            'status' => StripeWebhookEvent::STATUS_PROCESSING,
            'occurred_at' => Carbon::now()->utc(),
        ]);

        [$payload, $signature] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription([
            'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
        ]), eventId: 'evt_inflight_1');

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertSame(SubscriptionPlan::Lite, $salon->subscription()->firstOrFail()->plan);
    }

    public function test_records_the_received_event_for_auditing(): void
    {
        $salon = Salon::factory()->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);

        [$payload, $signature] = $this->signedWebhook(
            'customer.subscription.updated',
            $this->stripeSubscription(),
            eventId: 'evt_audit_1',
        );

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_audit_1',
            'type' => 'customer.subscription.updated',
            'status' => 'processed',
        ]);
    }

    public function test_an_unhandled_event_type_is_acknowledged_and_marked_skipped(): void
    {
        [$payload, $signature] = $this->signedWebhook('customer.created', ['id' => 'cus_x'], eventId: 'evt_skip_1');

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_skip_1',
            'status' => 'skipped',
        ]);
    }

    /**
     * 見覚えのない Customer / Subscription の通知でも 200 で受理し、状態は書き換えない。
     */
    public function test_an_event_for_an_unknown_salon_is_acknowledged_without_side_effects(): void
    {
        [$payload, $signature] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription([
            'id' => 'sub_unknown',
            'customer' => 'cus_unknown',
        ]), eventId: 'evt_unknown_1');

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => 'evt_unknown_1', 'status' => 'skipped']);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    /**
     * 未知のプラン（Price ID が config に無い）ではプランを書き換えない。
     */
    public function test_an_unknown_price_does_not_overwrite_the_plan(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);

        [$payload, $signature] = $this->signedWebhook('customer.subscription.updated', $this->stripeSubscription([
            'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => 'price_unknown']]]],
        ]));

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertSame(SubscriptionPlan::Standard, $salon->subscription()->firstOrFail()->plan);
    }

    private function postWebhook(string $payload, string $signature)
    {
        return $this->call(
            'POST',
            self::URI,
            [],
            [],
            [],
            $this->jsonHeaders(['HTTP_STRIPE_SIGNATURE' => $signature]),
            $payload,
        );
    }

    /**
     * @return array<string, string>
     */
    private function jsonHeaders(array $extra = []): array
    {
        return array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $extra);
    }
}
