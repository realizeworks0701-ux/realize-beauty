<?php

namespace App\Services\Billing;

use App\Enums\Feature;
use App\Enums\SubscriptionPlan;
use App\Exceptions\FeatureRequiredException;
use App\Models\Subscription;
use App\Repositories\SubscriptionRepository;

/**
 * 機能の利用可否を判定する唯一の場所（ADR-029）。
 *
 * Controller・Service・Job・Console・Middleware はすべてここを経由し、
 * プラン名や契約ステータスを自前で比較しない。プラン内容を変えても
 * 変更箇所が config/billing.php とこのクラスに閉じるようにする。
 *
 * AppServiceProvider で singleton に束ねているため、同一リクエスト内で
 * 同じサロンを何度問い合わせても DB アクセスは1回で済む。
 */
class EntitlementService
{
    /** @var array<int, ?SubscriptionPlan> */
    private array $planCache = [];

    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
    ) {}

    /**
     * 現在有効なプラン。契約が無い・失効している場合は fallback_plan（既定 null）。
     */
    public function planFor(int $salonId): ?SubscriptionPlan
    {
        if (array_key_exists($salonId, $this->planCache)) {
            return $this->planCache[$salonId];
        }

        $subscription = $this->subscriptionRepository->findBySalonId($salonId);

        return $this->planCache[$salonId] = $this->resolvePlan($subscription);
    }

    public function can(int $salonId, Feature $feature): bool
    {
        return $this->planFor($salonId)?->includes($feature) ?? false;
    }

    /**
     * 利用できなければ 403 を投げる。副作用の前に呼ぶ。
     */
    public function ensure(int $salonId, Feature $feature): void
    {
        if (! $this->can($salonId, $feature)) {
            throw new FeatureRequiredException($feature, $this->planFor($salonId));
        }
    }

    /**
     * フロントエンドへ渡す機能フラグ。全 Feature を漏れなく列挙する。
     *
     * @return array<string, bool>
     */
    public function features(int $salonId): array
    {
        $plan = $this->planFor($salonId);

        $flags = [];
        foreach (Feature::cases() as $feature) {
            $flags[$feature->value] = $plan?->includes($feature) ?? false;
        }

        return $flags;
    }

    /**
     * 契約を更新した直後にキャッシュを捨てる。
     */
    public function forget(int $salonId): void
    {
        unset($this->planCache[$salonId]);
    }

    /**
     * 契約が無い、または失効している場合はプラン無し（＝全機能不可）とする。
     * 課金のゲートは fail closed でなければならないため、既定値で救済しない。
     */
    private function resolvePlan(?Subscription $subscription): ?SubscriptionPlan
    {
        return $subscription?->status->grantsAccess() === true
            ? $subscription->plan
            : null;
    }
}
