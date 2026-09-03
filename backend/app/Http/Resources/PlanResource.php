<?php

namespace App\Http\Resources;

use App\Enums\Feature;
use App\Enums\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * プランカタログの1件。プラン一覧画面の表示に使う。
 *
 * @property-read SubscriptionPlan $resource
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plan = $this->resource;

        return [
            'code' => $plan->value,
            'label' => $plan->label(),
            'monthly_price' => $plan->monthlyPrice(),
            'features' => array_map(fn (Feature $feature) => $feature->value, $plan->features()),
            'is_purchasable' => $plan->stripePriceId() !== null,
        ];
    }
}
