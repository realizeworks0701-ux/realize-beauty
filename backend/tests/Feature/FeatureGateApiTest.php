<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Record;
use App\Models\Salon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

/**
 * 契約プランによる API の遮断（ADR-029）。
 *
 * 機能ごとに代表エンドポイントを1本ずつ叩き、403 になるか否かだけを見る。
 * 成功時のレスポンス形は各ドメインのテストが担保しているため、ここでは重複させない。
 */
class FeatureGateApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    /**
     * 機能キー => [HTTPメソッド, URI]。
     * 許可時は 404 などになってよく、「403 でないこと」だけを判定に使う。
     */
    private const ENDPOINTS = [
        'customer' => ['getJson', '/api/v1/customers'],
        'medical_record' => ['getJson', '/api/v1/records'],
        'photo' => ['deleteJson', '/api/v1/photos/999999'],
        'reservation' => ['getJson', '/api/v1/reservations'],
        'google_calendar' => ['getJson', '/api/v1/google-calendar'],
        'line' => ['getJson', '/api/v1/line-settings'],
        'ai_summary' => ['postJson', '/api/v1/records/999999/summarize'],
    ];

    public function test_lite_can_use_base_features_only(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Lite));

        $this->assertAllowed('customer', 'medical_record', 'photo');
        $this->assertBlocked('reservation', 'google_calendar', 'line', 'ai_summary');
    }

    public function test_standard_adds_reservation_google_calendar_and_line(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Standard));

        $this->assertAllowed('customer', 'medical_record', 'photo', 'reservation', 'google_calendar', 'line');
        $this->assertBlocked('ai_summary');
    }

    public function test_pro_can_use_every_feature(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Pro));

        $this->assertAllowed(...array_keys(self::ENDPOINTS));
    }

    public function test_menus_and_booking_page_follow_the_reservation_feature(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Lite));

        $this->getJson('/api/v1/menus')->assertForbidden();
        $this->postJson('/api/v1/menus', [])->assertForbidden();
        $this->getJson('/api/v1/booking-page')->assertForbidden();
    }

    /**
     * 営業時間はサロンの基本情報として全プランで扱える。
     */
    public function test_business_hours_are_available_on_every_plan(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Lite));

        $this->getJson('/api/v1/business-hours')->assertOk();
    }

    public function test_auth_and_subscription_endpoints_are_never_gated(): void
    {
        $this->actingAsSalonUser(Salon::factory()->withoutSubscription()->create());

        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/subscription')->assertOk();
        $this->getJson('/api/v1/dashboard')->assertOk();
        $this->getJson('/api/v1/users')->assertOk();
    }

    public function test_salon_without_subscription_cannot_use_any_gated_feature(): void
    {
        $this->actingAsSalonUser(Salon::factory()->withoutSubscription()->create());

        $this->assertBlocked(...array_keys(self::ENDPOINTS));
    }

    public function test_canceled_subscription_blocks_every_feature(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Pro, SubscriptionStatus::Canceled));

        $this->assertBlocked(...array_keys(self::ENDPOINTS));
    }

    /**
     * Stripe の回収フローが尽きた状態。ここで初めて利用停止になる。
     */
    public function test_unpaid_subscription_blocks_every_feature(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Pro, SubscriptionStatus::Unpaid));

        $this->assertBlocked(...array_keys(self::ENDPOINTS));
    }

    public function test_incomplete_subscription_blocks_every_feature(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Pro, SubscriptionStatus::Incomplete));

        $this->assertBlocked(...array_keys(self::ENDPOINTS));
    }

    /**
     * 支払い失敗の直後は Stripe が再試行中のため利用を止めない。
     */
    public function test_past_due_subscription_keeps_access_during_dunning(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Pro, SubscriptionStatus::PastDue));

        $this->assertAllowed(...array_keys(self::ENDPOINTS));
    }

    public function test_trialing_subscription_grants_access(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Standard, SubscriptionStatus::Trialing));

        $this->assertAllowed('customer', 'reservation', 'line');
        $this->assertBlocked('ai_summary');
    }

    /**
     * 解約申請中は Stripe 上 active のまま。期間終了までは使える。
     */
    public function test_cancel_scheduled_subscription_keeps_access_until_period_end(): void
    {
        $salon = Salon::factory()->create();
        $salon->subscription()->update(['cancel_at_period_end' => true]);
        $this->actingAsSalonUser($salon);

        $this->assertAllowed(...array_keys(self::ENDPOINTS));
    }

    public function test_unauthenticated_requests_are_401_not_403(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            [$method, $uri] = $endpoint;
            $this->{$method}($uri)->assertUnauthorized();
        }
    }

    public function test_forbidden_response_tells_the_client_which_plan_is_required(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Lite));

        $response = $this->getJson('/api/v1/reservations');

        $response->assertForbidden();
        $response->assertJsonPath('feature', 'reservation');
        $response->assertJsonPath('required_plan', 'standard');
        $response->assertJsonPath('current_plan', 'lite');
        $response->assertJsonPath('message', '予約管理はStandardプラン以上でご利用いただけます。');
    }

    public function test_ai_summary_is_blocked_before_reaching_openai(): void
    {
        $user = $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Standard));
        $customer = Customer::factory()->for($user->salon)->create();
        $record = Record::factory()->for($user->salon)->for($customer)->for($user)->create();

        $this->postJson("/api/v1/records/{$record->id}/summarize")
            ->assertForbidden()
            ->assertJsonPath('feature', 'ai_summary');
    }

    /**
     * ダッシュボード本体は全プランで開ける。高度な分析だけが null になる。
     */
    public function test_dashboard_hides_analytics_sections_without_the_feature(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Standard));

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('data.sales_trend', null);
        $response->assertJsonPath('data.popular_menus', null);
        $response->assertJsonPath('data.customer_segments', null);
        // 基本の指標は残す
        $response->assertJsonStructure(['data' => ['kpis', 'today_reservations']]);
    }

    public function test_dashboard_returns_analytics_sections_on_pro(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Pro));

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['kpis', 'sales_trend', 'today_reservations', 'popular_menus', 'customer_segments'],
        ]);
        $this->assertIsArray($response->json('data.customer_segments'));
    }

    public function test_auth_me_exposes_plan_and_feature_flags(): void
    {
        $this->actingAsSalonUser($this->salonOn(SubscriptionPlan::Standard));

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.plan', 'standard');
        $response->assertJsonPath('data.subscription_status', 'active');
        $response->assertJsonPath('data.features.reservation', true);
        $response->assertJsonPath('data.features.ai_summary', false);
        $response->assertJsonPath('data.features.analytics', false);
        // 全 Feature を漏れなく返す（フロントが未定義キーで判定しないように）
        $this->assertSame(
            array_map(fn (Feature $feature) => $feature->value, Feature::cases()),
            array_keys($response->json('data.features')),
        );
    }

    public function test_auth_me_reports_no_plan_when_subscription_is_missing(): void
    {
        $this->actingAsSalonUser(Salon::factory()->withoutSubscription()->create());

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.plan', null);
        $response->assertJsonPath('data.subscription_status', null);
        $this->assertSame([], array_filter($response->json('data.features')));
    }

    private function salonOn(SubscriptionPlan $plan, SubscriptionStatus $status = SubscriptionStatus::Active): Salon
    {
        return Salon::factory()->onPlan($plan, $status)->create();
    }

    private function assertAllowed(string ...$features): void
    {
        foreach ($features as $feature) {
            [$method, $uri] = self::ENDPOINTS[$feature];
            $this->assertNotSame(
                403,
                $this->{$method}($uri)->status(),
                "{$feature} は利用できるはずが 403 になりました（{$method} {$uri}）。",
            );
        }
    }

    private function assertBlocked(string ...$features): void
    {
        foreach ($features as $feature) {
            [$method, $uri] = self::ENDPOINTS[$feature];
            $this->{$method}($uri)->assertForbidden();
        }
    }
}
