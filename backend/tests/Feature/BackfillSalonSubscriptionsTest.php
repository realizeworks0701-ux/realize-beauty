<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Salon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

/**
 * 課金導入前から存在するサロンへの契約行の投入（ADR-029）。
 *
 * 既存サロンは全機能を使える前提で運用されてきたため、移行で機能を取り上げない。
 */
class BackfillSalonSubscriptionsTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_gives_every_existing_salon_an_active_pro_contract(): void
    {
        $salons = $this->salonsWithoutSubscription(3);

        $this->runBackfill();

        foreach ($salons as $salon) {
            $subscription = $salon->subscription()->firstOrFail();
            $this->assertSame(SubscriptionPlan::Pro, $subscription->plan);
            $this->assertSame(SubscriptionStatus::Active, $subscription->status);
            // Stripe 側にはまだ実体が無い。初回 Checkout で紐づく
            $this->assertNull($subscription->stripe_customer_id);
            $this->assertNull($subscription->stripe_subscription_id);
        }
    }

    public function test_existing_salons_keep_working_after_the_backfill(): void
    {
        $salon = $this->salonsWithoutSubscription(1)->first();

        $this->runBackfill();
        $this->actingAsSalonUser($salon);

        $this->getJson('/api/v1/customers')->assertOk();
        $this->getJson('/api/v1/reservations')->assertOk();
        $this->getJson('/api/v1/line-settings')->assertSuccessful();
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.customer_segments.new', 0);
    }

    public function test_does_not_touch_salons_that_already_have_a_contract(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();

        $this->runBackfill();

        $this->assertSame(SubscriptionPlan::Lite, $salon->subscription()->firstOrFail()->plan);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_is_idempotent(): void
    {
        $this->salonsWithoutSubscription(2);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertDatabaseCount('subscriptions', 2);
    }

    /**
     * ロールバックは Stripe と未接続の行だけを消す。契約済みの行は残す。
     */
    public function test_rollback_only_removes_rows_never_linked_to_stripe(): void
    {
        $backfilled = $this->salonsWithoutSubscription(1)->first();
        $this->runBackfill();
        $subscribed = Salon::factory()->create();

        $this->migration()->down();

        $this->assertDatabaseMissing('subscriptions', ['salon_id' => $backfilled->id]);
        $this->assertDatabaseHas('subscriptions', ['salon_id' => $subscribed->id]);
    }

    /**
     * 不正な値をDBへ通すと、以後この行を読むすべてのリクエストが enum キャストで 500 になる。
     */
    public function test_rejects_an_invalid_backfill_plan(): void
    {
        $this->salonsWithoutSubscription(1);
        putenv('BILLING_BACKFILL_PLAN=enterprise');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->runBackfill();
        } finally {
            putenv('BILLING_BACKFILL_PLAN');
        }
    }

    /**
     * @return Collection<int, Salon>
     */
    private function salonsWithoutSubscription(int $count)
    {
        $salons = Salon::factory()->count($count)->create();
        DB::table('subscriptions')->delete();

        return $salons;
    }

    private function runBackfill(): void
    {
        $this->migration()->up();
    }

    private function migration(): object
    {
        return include database_path('migrations/2026_09_03_000004_backfill_salon_subscriptions.php');
    }
}
