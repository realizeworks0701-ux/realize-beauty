<?php

namespace App\Http\Resources;

use App\Services\Billing\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * 契約プランと機能フラグを同梱する（ADR-029）。
     *
     * SPA はログイン応答と /auth/me からしかテナント情報を受け取れないため、
     * メニューや画面の出し分けに必要な最小限をここに載せる。
     * これは表示制御のための情報であり、実際の遮断は常にサーバ側の 403 が担う。
     */
    public function toArray(Request $request): array
    {
        $entitlements = app(EntitlementService::class);
        $salonId = (int) $this->salon_id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'plan' => $entitlements->planFor($salonId)?->value,
            'subscription_status' => $this->subscriptionStatus(),
            'features' => $entitlements->features($salonId),
        ];
    }

    private function subscriptionStatus(): ?string
    {
        return $this->resource->salon?->subscription?->status->value;
    }
}
