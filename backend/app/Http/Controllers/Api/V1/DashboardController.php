<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $summary = $this->dashboardService->getSummary($request->user()->salon_id);

        return response()->json([
            'data' => [
                'kpis' => $summary['kpis'],
                'sales_trend' => $summary['sales_trend'],
                'today_reservations' => ReservationResource::collection($summary['today_reservations']),
                'popular_menus' => $summary['popular_menus'],
                'customer_segments' => $summary['customer_segments'],
            ],
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }
}
