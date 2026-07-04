<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\RecordResource;
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
                'today_customers' => $summary['today_customers'],
                'new_customers' => $summary['new_customers'],
                'total_customers' => $summary['total_customers'],
                'records_this_month' => $summary['records_this_month'],
                'recent_customers' => CustomerResource::collection($summary['recent_customers']),
                'recent_records' => RecordResource::collection($summary['recent_records']),
            ],
        ]);
    }
}
