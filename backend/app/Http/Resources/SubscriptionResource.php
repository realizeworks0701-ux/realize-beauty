<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'plan' => $this->plan->value,
            'plan_label' => $this->plan->label(),
            'monthly_price' => $this->plan->monthlyPrice(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_active' => $this->status->grantsAccess(),
            'needs_payment_attention' => $this->status->needsPaymentAttention(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            // Stripe の識別子は運用に不要なため露出させず、導線の出し分けに使う真偽値だけ返す。
            'has_payment_method' => $this->stripe_customer_id !== null,
            'is_subscribed' => $this->stripe_subscription_id !== null,
        ];
    }
}
