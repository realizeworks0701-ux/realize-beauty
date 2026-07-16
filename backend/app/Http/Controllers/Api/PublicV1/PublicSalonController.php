<?php

namespace App\Http\Controllers\Api\PublicV1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessHourResource;
use App\Http\Resources\PublicMenuResource;
use App\Http\Resources\PublicStaffResource;
use App\Services\PublicBookingService;
use Illuminate\Http\JsonResponse;

class PublicSalonController extends Controller
{
    public function __construct(
        private readonly PublicBookingService $publicBookingService,
    ) {}

    public function __invoke(string $bookingSlug): JsonResponse
    {
        $salon = $this->publicBookingService->findSalon($bookingSlug);

        return response()->json([
            'data' => [
                'name' => $salon['salon']->name,
                'business_hours' => BusinessHourResource::collection($salon['business_hours']),
                'menus' => PublicMenuResource::collection($salon['menus']),
                'staff' => PublicStaffResource::collection($salon['staff']),
            ],
        ]);
    }
}
