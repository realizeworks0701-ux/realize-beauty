<?php

namespace App\Services;

use App\Repositories\DashboardRepository;

class DashboardService
{
    public function __construct(
        private readonly DashboardRepository $dashboardRepository,
    ) {}

    public function getSummary(int $salonId): array
    {
        return $this->dashboardRepository->getSummary($salonId);
    }
}
