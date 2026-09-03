<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Salon;
use App\Services\Billing\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

/**
 * 機能判定の集約点（ADR-029）。
 */
class EntitlementServiceTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    /**
     * 利用可否は契約の生死そのもの。取り違えると課金していないサロンが使えたり、
     * 支払い済みのサロンが止まったりする。全ケースを明示的に固定する。
     */
    public function test_only_trialing_active_and_past_due_grant_access(): void
    {
        $granting = [];
        $blocking = [];

        foreach (SubscriptionStatus::cases() as $status) {
            $status->grantsAccess()
                ? $granting[] = $status->value
                : $blocking[] = $status->value;
        }

        $this->assertSame(['trialing', 'active', 'past_due'], $granting);
        $this->assertSame(
            ['canceled', 'unpaid', 'incomplete', 'incomplete_expired', 'paused'],
            $blocking,
        );
    }

    /**
     * 状態ごとに実際の API が通るかまで確かめる（enum の表だけでは経路を保証できない）。
     */
    public function test_every_status_gates_the_api_consistently_with_grants_access(): void
    {
        foreach (SubscriptionStatus::cases() as $status) {
            $salon = Salon::factory()->onPlan(SubscriptionPlan::Pro, $status)->create();
            $this->actingAsSalonUser($salon);

            $response = $this->getJson('/api/v1/customers');

            $status->grantsAccess()
                ? $response->assertOk()
                : $response->assertForbidden();
        }
    }

    public function test_resolves_the_plan_from_the_active_subscription(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Standard)->create();

        $entitlements = app(EntitlementService::class);

        $this->assertSame(SubscriptionPlan::Standard, $entitlements->planFor($salon->id));
        $this->assertTrue($entitlements->can($salon->id, Feature::Reservation));
        $this->assertFalse($entitlements->can($salon->id, Feature::AiSummary));
    }

    public function test_a_salon_without_a_subscription_has_no_plan(): void
    {
        $salon = Salon::factory()->withoutSubscription()->create();

        $entitlements = app(EntitlementService::class);

        $this->assertNull($entitlements->planFor($salon->id));
        foreach (Feature::cases() as $feature) {
            $this->assertFalse($entitlements->can($salon->id, $feature), $feature->value);
        }
    }

    public function test_features_lists_every_feature_key(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();

        $flags = app(EntitlementService::class)->features($salon->id);

        $this->assertSame(
            array_map(fn (Feature $feature) => $feature->value, Feature::cases()),
            array_keys($flags),
        );
        $this->assertTrue($flags['customer']);
        $this->assertFalse($flags['reservation']);
    }

    /**
     * 契約を更新したらキャッシュを捨てる。
     */
    public function test_forget_drops_the_cached_plan(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Pro)->create();
        $entitlements = app(EntitlementService::class);
        $this->assertTrue($entitlements->can($salon->id, Feature::AiSummary));

        $salon->subscription()->update(['plan' => SubscriptionPlan::Lite]);
        $entitlements->forget($salon->id);

        $this->assertFalse($entitlements->can($salon->id, Feature::AiSummary));
    }

    /**
     * queue:work は1プロセスで多数のジョブを処理する。singleton だとワーカーが生きている間
     * 古いプランを掴み続けてしまうため、ジョブ境界（forgetScopedInstances）で破棄される
     * scoped で登録していることを保証する。
     */
    public function test_is_scoped_so_a_queue_worker_does_not_cache_across_jobs(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Pro)->create();

        $first = app(EntitlementService::class);
        $this->assertTrue($first->can($salon->id, Feature::AiSummary));
        $this->assertSame($first, app(EntitlementService::class), '同一ジョブ内では使い回す');

        // ジョブの切れ目（Illuminate\Queue\QueueServiceProvider が毎ジョブ呼ぶ）
        $salon->subscription()->update(['status' => SubscriptionStatus::Canceled]);
        $this->app->forgetScopedInstances();

        $second = app(EntitlementService::class);
        $this->assertNotSame($first, $second);
        $this->assertFalse($second->can($salon->id, Feature::AiSummary));
    }
}
