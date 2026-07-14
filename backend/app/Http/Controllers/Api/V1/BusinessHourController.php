<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessHour\UpdateBusinessHoursRequest;
use App\Http\Resources\BusinessHourResource;
use App\Services\BusinessHourService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessHourController extends Controller
{
    public function __construct(
        private readonly BusinessHourService $businessHourService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $businessHours = $this->businessHourService->list($request->user()->salon_id);

        return response()->json(['data' => BusinessHourResource::collection($businessHours)]);
    }

    public function update(UpdateBusinessHoursRequest $request): JsonResponse
    {
        $businessHours = $this->businessHourService->replace(
            $request->user()->salon_id,
            $request->validated('business_hours'),
        );

        return response()->json(['data' => BusinessHourResource::collection($businessHours)]);
    }
}
