<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Salon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresStripe;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

/**
 * 契約の確認・開始・変更・解約 API（ADR-029）。
 * Stripe へは接続せず Http::fake() で応答を差し替える。
 */
class SubscriptionApiTest extends TestCase
{
    use ConfiguresStripe, CreatesSalonUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStripe();
        Http::preventStrayRequests();
    }

    // ---- GET /subscription ----------------------------------

    public function test_show_returns_current_plan_features_and_catalog(): void
    {
        $this->actingAsSalonUser(Salon::factory()->onPlan(SubscriptionPlan::Standard)->create());

        $response = $this->getJson('/api/v1/subscription');

        $response->assertOk();
        $response->assertJsonPath('data.plan', 'standard');
        $response->assertJsonPath('data.subscription.plan', 'standard');
        $response->assertJsonPath('data.subscription.plan_label', 'Standard');
        $response->assertJsonPath('data.subscription.monthly_price', 1980);
        $response->assertJsonPath('data.subscription.status', 'active');
        $response->assertJsonPath('data.subscription.status_label', '利用中');
        $response->assertJsonPath('data.subscription.is_active', true);
        $response->assertJsonPath('data.features.reservation', true);
        $response->assertJsonPath('data.features.ai_summary', false);
        $response->assertJsonCount(3, 'data.plans');
        $response->assertJsonPath('data.plans.0.code', 'lite');
        $response->assertJsonPath('data.plans.0.monthly_price', 980);
        $response->assertJsonPath('data.plans.2.monthly_price', 3980);
    }

    /**
     * Stripe の識別子は運用に不要なので露出させない。
     */
    public function test_show_does_not_expose_stripe_identifiers(): void
    {
        $this->actingAsSalonUser(Salon::factory()->create());

        $body = $this->getJson('/api/v1/subscription')->content();

        $this->assertStringNotContainsString('stripe_customer_id', $body);
        $this->assertStringNotContainsString('stripe_subscription_id', $body);
        $this->assertStringNotContainsString('cus_', $body);
        $this->assertStringNotContainsString('sub_', $body);
    }

    public function test_show_returns_null_subscription_when_salon_has_no_contract(): void
    {
        $this->actingAsSalonUser(Salon::factory()->withoutSubscription()->create());

        $response = $this->getJson('/api/v1/subscription');

        $response->assertOk();
        $response->assertJsonPath('data.subscription', null);
        $response->assertJsonPath('data.plan', null);
        $response->assertJsonPath('data.features.customer', false);
    }

    // ---- POST /subscription/checkout -------------------------

    public function test_checkout_creates_a_stripe_session_for_the_selected_plan(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_1',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
            ]),
        ]);
        $salon = Salon::factory()->withoutStripe()->onPlan(SubscriptionPlan::Lite, SubscriptionStatus::Incomplete)->create();
        $user = $this->actingAsSalonUser($salon);

        $response = $this->postJson('/api/v1/subscription/checkout', ['plan' => 'standard']);

        $response->assertOk();
        $response->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_test_1');

        Http::assertSent(function (Request $request) use ($salon, $user) {
            $data = $request->data();

            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $data['mode'] === 'subscription'
                && $data['line_items'][0]['price'] === self::PRICE_STANDARD
                && $data['metadata']['salon_id'] === (string) $salon->id
                && $data['metadata']['plan'] === 'standard'
                && $data['customer_email'] === $user->email
                && str_contains($data['success_url'], 'checkout=success');
        });
    }

    /**
     * クライアントが Price ID を指定しても Stripe には渡らない。
     */
    public function test_checkout_ignores_a_price_id_supplied_by_the_client(): void
    {
        Http::fake([
            'api.stripe.com/*' => Http::response(['url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
        ]);
        $this->actingAsSalonUser(
            Salon::factory()->withoutStripe()->onPlan(SubscriptionPlan::Lite, SubscriptionStatus::Incomplete)->create(),
        );

        $this->postJson('/api/v1/subscription/checkout', [
            'plan' => 'lite',
            'price' => 'price_attacker_controlled',
            'stripe_price_id' => 'price_attacker_controlled',
        ])->assertOk();

        Http::assertSent(function (Request $request) {
            $body = json_encode($request->data());

            return str_contains($body, self::PRICE_LITE)
                && ! str_contains($body, 'price_attacker_controlled');
        });
    }

    public function test_checkout_reuses_the_existing_stripe_customer(): void
    {
        Http::fake([
            'api.stripe.com/*' => Http::response(['url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
        ]);
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite, SubscriptionStatus::Canceled)->create();
        $salon->subscription()->update(['stripe_customer_id' => 'cus_existing', 'stripe_subscription_id' => null]);
        $this->actingAsSalonUser($salon);

        $this->postJson('/api/v1/subscription/checkout', ['plan' => 'pro'])->assertOk();

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return ($data['customer'] ?? null) === 'cus_existing'
                && ! array_key_exists('customer_email', $data);
        });
    }

    /**
     * Checkout 完了から Webhook 到着までの数秒はDBが「未契約」のまま。
     * その窓で2本目の Checkout を通すと、アプリから見えないまま二重に課金される。
     */
    public function test_checkout_is_blocked_when_stripe_already_has_a_live_subscription(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/v1/subscriptions')
            ? Http::response(['object' => 'list', 'data' => [$this->stripeSubscription([
                'id' => 'sub_just_created',
                'customer' => 'cus_existing',
                'status' => 'active',
                'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => self::PRICE_PRO]]]],
            ])]])
            : Http::response(['url' => 'https://checkout.stripe.com/should-not-be-used']));

        // DB はまだ Webhook を受け取っていない状態
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite, SubscriptionStatus::Incomplete)->create();
        $salon->subscription()->update([
            'stripe_customer_id' => 'cus_existing',
            'stripe_subscription_id' => null,
        ]);
        $this->actingAsSalonUser($salon);

        $this->postJson('/api/v1/subscription/checkout', ['plan' => 'standard'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');

        // Checkout セッションは作らせない
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/checkout/sessions'));

        // 見つかった契約は取り込んでおく（再読み込みで正しい状態が見える）
        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame('sub_just_created', $subscription->stripe_subscription_id);
        $this->assertSame(SubscriptionPlan::Pro, $subscription->plan);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    /**
     * 解約済みの契約しか無ければ再契約できる。
     */
    public function test_checkout_proceeds_when_stripe_only_has_terminal_subscriptions(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/v1/checkout/sessions')
            ? Http::response(['url' => 'https://checkout.stripe.com/c/pay/cs_new'])
            : Http::response(['object' => 'list', 'data' => [$this->stripeSubscription([
                'id' => 'sub_old',
                'customer' => 'cus_existing',
                'status' => 'canceled',
            ])]]));

        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite, SubscriptionStatus::Canceled)->create();
        $salon->subscription()->update([
            'stripe_customer_id' => 'cus_existing',
            'stripe_subscription_id' => 'sub_old',
        ]);
        $this->actingAsSalonUser($salon);

        $this->postJson('/api/v1/subscription/checkout', ['plan' => 'standard'])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_new');
    }

    public function test_checkout_rejects_an_unknown_plan(): void
    {
        $this->actingAsSalonUser(Salon::factory()->withoutSubscription()->create());

        $this->postJson('/api/v1/subscription/checkout', ['plan' => 'enterprise'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');

        Http::assertNothingSent();
    }

    public function test_checkout_is_rejected_while_a_contract_is_active(): void
    {
        $this->actingAsSalonUser(Salon::factory()->create());

        $this->postJson('/api/v1/subscription/checkout', ['plan' => 'pro'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');

        Http::assertNothingSent();
    }

    public function test_checkout_requires_authentication(): void
    {
        $this->postJson('/api/v1/subscription/checkout', ['plan' => 'pro'])->assertUnauthorized();
    }

    // ---- POST /subscription/sync-checkout --------------------

    /**
     * Webhook を待たずに契約を確定させる。これが無いと、戻った画面に購入ボタンが並んだままで
     * 2本目を契約でき、二重課金になる。
     */
    public function test_sync_checkout_applies_the_result_without_waiting_for_the_webhook(): void
    {
        $salon = Salon::factory()->withoutStripe()->onPlan(SubscriptionPlan::Lite, SubscriptionStatus::Incomplete)->create();
        Http::fake(fn (Request $request) => str_contains($request->url(), '/v1/checkout/sessions/')
            ? Http::response([
                'id' => 'cs_test_1',
                'object' => 'checkout.session',
                'customer' => 'cus_test_1',
                'subscription' => 'sub_test_1',
                'client_reference_id' => (string) $salon->id,
                'metadata' => ['salon_id' => (string) $salon->id],
            ])
            : Http::response($this->stripeSubscription([
                'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
            ])));
        $this->actingAsSalonUser($salon);

        $response = $this->postJson('/api/v1/subscription/sync-checkout', ['session_id' => 'cs_test_1']);

        $response->assertOk();
        $response->assertJsonPath('data.plan', 'pro');

        $subscription = $salon->subscription()->firstOrFail();
        $this->assertSame('sub_test_1', $subscription->stripe_subscription_id);
        $this->assertSame('cus_test_1', $subscription->stripe_customer_id);

        // 取り込み後は2本目の Checkout を作らせない
        $this->postJson('/api/v1/subscription/checkout', ['plan' => 'standard'])->assertStatus(422);
    }

    /**
     * session_id は URL 経由で渡ってくるので、他サロンのセッションを取り込ませない。
     */
    public function test_sync_checkout_rejects_a_session_belonging_to_another_salon(): void
    {
        $other = Salon::factory()->withoutStripe()->create();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_other',
                'object' => 'checkout.session',
                'customer' => 'cus_other',
                'subscription' => 'sub_other',
                'client_reference_id' => (string) $other->id,
                'metadata' => ['salon_id' => (string) $other->id],
            ]),
        ]);
        $this->actingAsSalonUser(Salon::factory()->withoutStripe()->create());

        $this->postJson('/api/v1/subscription/sync-checkout', ['session_id' => 'cs_other'])
            ->assertForbidden();

        $this->assertNull($other->subscription()->firstOrFail()->stripe_subscription_id);
    }

    public function test_sync_checkout_requires_the_billing_role(): void
    {
        $this->actingAsSalonUser(Salon::factory()->create(), ['role' => Role::Staff]);

        $this->postJson('/api/v1/subscription/sync-checkout', ['session_id' => 'cs_1'])->assertForbidden();

        Http::assertNothingSent();
    }

    // ---- POST /subscription/portal ---------------------------

    public function test_portal_returns_a_stripe_hosted_url(): void
    {
        Http::fake([
            'api.stripe.com/v1/billing_portal/sessions' => Http::response([
                'url' => 'https://billing.stripe.com/p/session_1',
            ]),
        ]);
        $this->actingAsSalonUser(Salon::factory()->create());

        $this->postJson('/api/v1/subscription/portal')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://billing.stripe.com/p/session_1');
    }

    public function test_portal_is_rejected_without_a_stripe_customer(): void
    {
        $this->actingAsSalonUser(Salon::factory()->withoutStripe()->create());

        $this->postJson('/api/v1/subscription/portal')->assertStatus(422);

        Http::assertNothingSent();
    }

    // ---- POST /subscription/change-plan ----------------------

    public function test_change_plan_swaps_the_price_and_syncs_the_new_plan(): void
    {
        $this->fakeSubscriptionEndpoints(
            retrieved: $this->stripeSubscription(),
            updated: $this->stripeSubscription([
                'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
            ]),
        );
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1', 'stripe_customer_id' => 'cus_test_1']);
        $this->actingAsSalonUser($salon);

        $response = $this->postJson('/api/v1/subscription/change-plan', ['plan' => 'pro']);

        $response->assertOk();
        $response->assertJsonPath('data.plan', 'pro');
        $this->assertDatabaseHas('subscriptions', [
            'salon_id' => $salon->id,
            'plan' => 'pro',
            'stripe_price_id' => self::PRICE_PRO,
        ]);

        // 日割り精算は Stripe に委ねる（アプリで金額計算をしない）。
        // assertSent は1件でも一致すれば通るため、更新の POST そのものを名指しで検査する。
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_test_1'
            && ($request->data()['proration_behavior'] ?? null) === 'create_prorations'
            && ($request->data()['payment_behavior'] ?? null) === 'error_if_incomplete'
            && ($request->data()['items'][0]['price'] ?? null) === self::PRICE_PRO
            && ($request->data()['items'][0]['id'] ?? null) === 'si_test_1');
    }

    public function test_change_plan_records_a_plan_changed_event(): void
    {
        $this->fakeSubscriptionEndpoints(
            retrieved: $this->stripeSubscription(),
            updated: $this->stripeSubscription([
                'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
            ]),
        );
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);
        $this->actingAsSalonUser($salon);

        $this->postJson('/api/v1/subscription/change-plan', ['plan' => 'pro'])->assertOk();

        $this->assertDatabaseHas('subscription_events', [
            'salon_id' => $salon->id,
            'type' => 'plan_changed',
            'from_plan' => 'standard',
            'to_plan' => 'pro',
        ]);
    }

    public function test_change_plan_to_the_current_plan_is_rejected(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Pro)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);
        $this->actingAsSalonUser($salon);

        $this->postJson('/api/v1/subscription/change-plan', ['plan' => 'pro'])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_change_plan_is_rejected_without_a_contract(): void
    {
        $this->actingAsSalonUser(Salon::factory()->withoutStripe()->create());

        $this->postJson('/api/v1/subscription/change-plan', ['plan' => 'pro'])->assertStatus(422);

        Http::assertNothingSent();
    }

    // ---- POST /subscription/cancel & resume ------------------

    public function test_cancel_schedules_the_end_of_the_period_without_stopping_access(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::response(
                $this->stripeSubscription(['cancel_at_period_end' => true]),
            ),
        ]);
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);
        $this->actingAsSalonUser($salon);

        $response = $this->postJson('/api/v1/subscription/cancel');

        $response->assertOk();
        $response->assertJsonPath('data.cancel_at_period_end', true);
        $response->assertJsonPath('data.is_active', true);

        Http::assertSent(fn (Request $request) => ($request->data()['cancel_at_period_end'] ?? null) === 'true');

        // 即時停止せず、顧客データも残る
        $this->getJson('/api/v1/reservations')->assertOk();
        $this->assertDatabaseHas('subscriptions', ['salon_id' => $salon->id, 'cancel_at_period_end' => true]);

        // 解約申請は status が変わらないため、専用の遷移として記録する
        $this->assertDatabaseHas('subscription_events', [
            'salon_id' => $salon->id,
            'type' => 'cancel_requested',
        ]);
    }

    public function test_cancel_twice_is_rejected(): void
    {
        $salon = Salon::factory()->cancelScheduledSubscription()->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);
        $this->actingAsSalonUser($salon);

        $this->postJson('/api/v1/subscription/cancel')->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_resume_clears_the_scheduled_cancellation(): void
    {
        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_test_1' => Http::response(
                $this->stripeSubscription(['cancel_at_period_end' => false]),
            ),
        ]);
        $salon = Salon::factory()->cancelScheduledSubscription()->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);
        $this->actingAsSalonUser($salon);

        $this->postJson('/api/v1/subscription/resume')
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', false);

        Http::assertSent(fn (Request $request) => ($request->data()['cancel_at_period_end'] ?? null) === 'false');
        $this->assertDatabaseHas('subscription_events', [
            'salon_id' => $salon->id,
            'type' => 'cancel_revoked',
        ]);
    }

    public function test_resume_without_a_scheduled_cancellation_is_rejected(): void
    {
        $salon = Salon::factory()->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);
        $this->actingAsSalonUser($salon);

        $this->postJson('/api/v1/subscription/resume')->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * プラン変更の直前に発生していた webhook が、あとから届いて変更を巻き戻さないこと。
     * live API の応答はその瞬間の正本なので、鮮度を進めておく必要がある。
     */
    public function test_a_webhook_from_just_before_a_plan_change_does_not_roll_it_back(): void
    {
        $this->fakeSubscriptionEndpoints(
            retrieved: $this->stripeSubscription(),
            updated: $this->stripeSubscription([
                'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
            ]),
        );
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);
        $this->actingAsSalonUser($salon);

        $this->postJson('/api/v1/subscription/change-plan', ['plan' => 'pro'])->assertOk();
        $this->assertSame(SubscriptionPlan::Pro, $salon->subscription()->firstOrFail()->plan);

        // 変更操作の1分前に発生していた（＝Standard のままの）通知が遅れて届く
        [$payload, $signature] = $this->signedWebhook(
            'customer.subscription.updated',
            $this->stripeSubscription([
                'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_STANDARD]]]],
            ]),
            eventId: 'evt_stale_after_change',
            createdAt: Carbon::now()->utc()->subMinute(),
        );

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload)->assertOk();

        $this->assertSame(SubscriptionPlan::Pro, $salon->subscription()->firstOrFail()->plan);
    }

    // ---- 権限 -----------------------------------------------

    /**
     * 一般スタッフの操作でサロンの請求が変わらないようにする。
     */
    public function test_staff_cannot_move_the_contract(): void
    {
        $salon = Salon::factory()->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1']);
        $this->actingAsSalonUser($salon, ['role' => Role::Staff]);

        $this->postJson('/api/v1/subscription/checkout', ['plan' => 'pro'])->assertForbidden();
        $this->postJson('/api/v1/subscription/change-plan', ['plan' => 'lite'])->assertForbidden();
        $this->postJson('/api/v1/subscription/cancel')->assertForbidden();
        $this->postJson('/api/v1/subscription/resume')->assertForbidden();
        $this->postJson('/api/v1/subscription/portal')->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * 契約状態の閲覧は全ロールに許す（プラン制限の理由を知る必要があるため）。
     */
    public function test_staff_can_still_read_the_contract(): void
    {
        $this->actingAsSalonUser(Salon::factory()->create(), ['role' => Role::Staff]);

        $this->getJson('/api/v1/subscription')->assertOk();
    }

    public function test_manager_can_move_the_contract(): void
    {
        Http::fake([
            'api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/1']),
        ]);
        $this->actingAsSalonUser(Salon::factory()->create(), ['role' => Role::Manager]);

        $this->postJson('/api/v1/subscription/portal')->assertOk();
    }

    /**
     * GET は取得、POST は更新。同じ URL を持つため method で振り分ける。
     */
    private function fakeSubscriptionEndpoints(array $retrieved, array $updated): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response($retrieved)
            : Http::response($updated));
    }
}
