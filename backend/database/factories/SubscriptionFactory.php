<?php

namespace Database\Factories;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Salon;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'plan' => SubscriptionPlan::Pro,
            'status' => SubscriptionStatus::Active,
            'stripe_customer_id' => 'cus_'.fake()->unique()->bothify('##??##??##??##'),
            'stripe_subscription_id' => 'sub_'.fake()->unique()->bothify('##??##??##??##'),
            'stripe_price_id' => 'price_'.fake()->bothify('##??##??'),
            'current_period_start' => Carbon::now()->utc()->subDays(3),
            'current_period_end' => Carbon::now()->utc()->addDays(27),
            'cancel_at_period_end' => false,
        ];
    }

    public function plan(SubscriptionPlan $plan): static
    {
        return $this->state(fn () => ['plan' => $plan]);
    }

    public function status(SubscriptionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * Checkout 未実施。Stripe 側に実体が無い状態。
     */
    public function withoutStripe(): static
    {
        return $this->state(fn () => [
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
            'stripe_price_id' => null,
        ]);
    }

    /**
     * 解約申請済みで期間終了待ち。Stripe 上は active のまま。
     */
    public function cancelScheduled(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Active,
            'cancel_at_period_end' => true,
            'canceled_at' => Carbon::now()->utc(),
        ]);
    }
}
