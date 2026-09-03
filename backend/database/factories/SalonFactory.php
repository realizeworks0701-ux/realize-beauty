<?php

namespace Database\Factories;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Salon;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Salon>
 */
class SalonFactory extends Factory
{
    protected $model = Salon::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().'サロン',
            'phone' => fake()->numerify('03-####-####'),
            'postal_code' => fake()->numerify('###-####'),
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }

    /**
     * すべてのサロンに Pro / active の契約を付ける（ADR-029）。
     *
     * 課金導入前から存在するテストは全機能が使える前提で書かれているため、既定を Pro とし、
     * プラン制限を検証するテストだけが onPlan() / withoutSubscription() で明示的に下げる。
     * 状態は afterCreating の登録順に適用されるため、後続の state はこの行を上書きする。
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Salon $salon) {
            Subscription::factory()->for($salon)->create();
        });
    }

    public function onPlan(SubscriptionPlan $plan, SubscriptionStatus $status = SubscriptionStatus::Active): static
    {
        return $this->afterCreating(function (Salon $salon) use ($plan, $status) {
            $salon->subscription()->update(['plan' => $plan, 'status' => $status]);
        });
    }

    /**
     * 契約行そのものが存在しないサロン。
     */
    public function withoutSubscription(): static
    {
        return $this->afterCreating(function (Salon $salon) {
            $salon->subscription()->delete();
        });
    }

    /**
     * 解約申請済みで期間終了待ち。Stripe 上は active のまま。
     */
    public function cancelScheduledSubscription(): static
    {
        return $this->afterCreating(function (Salon $salon) {
            $salon->subscription()->update([
                'cancel_at_period_end' => true,
                'canceled_at' => Carbon::now()->utc(),
            ]);
        });
    }

    /**
     * Checkout 未実施で Stripe 側に実体が無い状態。
     */
    public function withoutStripe(): static
    {
        return $this->afterCreating(function (Salon $salon) {
            $salon->subscription()->update([
                'stripe_customer_id' => null,
                'stripe_subscription_id' => null,
                'stripe_price_id' => null,
            ]);
        });
    }
}
