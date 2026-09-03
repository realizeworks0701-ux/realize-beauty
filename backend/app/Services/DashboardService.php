<?php

namespace App\Services;

use App\Enums\Feature;
use App\Repositories\DashboardRepository;
use App\Services\Billing\EntitlementService;

class DashboardService
{
    /**
     * 高度な分析にあたるセクション（ADR-026 で v0.2 Analytics として定義された範囲）。
     */
    private const ANALYTICS_SECTIONS = ['sales_trend', 'popular_menus', 'customer_segments'];

    public function __construct(
        private readonly DashboardRepository $dashboardRepository,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * ダッシュボードは全プランで開けるが、高度な分析セクションは Pro のみ（ADR-029）。
     *
     * キー自体は残して null を返す。レスポンスの形を変えないことで、
     * OpenAPI の契約と SPA の型を壊さずに出し分けだけを行う。
     */
    public function getSummary(int $salonId): array
    {
        $summary = $this->dashboardRepository->getSummary($salonId);

        if ($this->entitlements->can($salonId, Feature::Analytics)) {
            return $summary;
        }

        foreach (self::ANALYTICS_SECTIONS as $section) {
            $summary[$section] = null;
        }

        return $summary;
    }
}
