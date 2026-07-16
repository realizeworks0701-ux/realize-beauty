<?php

namespace App\Http\Controllers\Api\PublicV1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicBooking\ListAvailabilityRequest;
use App\Http\Resources\AvailabilitySlotResource;
use App\Services\PublicBookingService;
use Illuminate\Http\JsonResponse;

class PublicAvailabilityController extends Controller
{
    public function __construct(
        private readonly PublicBookingService $publicBookingService,
    ) {}

    public function __invoke(ListAvailabilityRequest $request, string $bookingSlug): JsonResponse
    {
        $slots = $this->publicBookingService->listAvailability($bookingSlug, $request->validated());

        return response()->json(['data' => AvailabilitySlotResource::collection($slots)]);
    }
}
