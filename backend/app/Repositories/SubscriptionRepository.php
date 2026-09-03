<?php

namespace App\Repositories;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use Illuminate\Support\Carbon;

class SubscriptionRepository
{
    public function findBySalonId(int $salonId): ?Subscription
    {
        return Subscription::where('salon_id', $salonId)->first();
    }

    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?Subscription
    {
        return Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
    }

    public function findByStripeCustomerId(string $stripeCustomerId): ?Subscription
    {
        return Subscription::where('stripe_customer_id', $stripeCustomerId)->first();
    }

    /**
     * サロンの契約行を取得し、無ければ作る。1サロン1行を unique 制約で保証している。
     */
    public function firstOrCreateForSalon(int $salonId, array $attributes): Subscription
    {
        return Subscription::firstOrCreate(['salon_id' => $salonId], $attributes);
    }

    public function update(Subscription $subscription, array $attributes): Subscription
    {
        $subscription->update($attributes);

        return $subscription->fresh();
    }

    public function recordEvent(array $attributes): SubscriptionEvent
    {
        $attributes['occurred_at'] ??= Carbon::now()->utc();

        return SubscriptionEvent::create($attributes);
    }
}
