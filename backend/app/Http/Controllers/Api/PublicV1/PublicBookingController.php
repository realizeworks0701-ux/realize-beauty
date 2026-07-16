<?php

namespace App\Http\Controllers\Api\PublicV1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicBookingResource;
use App\Services\PublicBookingService;
use Illuminate\Http\JsonResponse;

class PublicBookingController extends Controller
{
    public function __construct(
        private readonly PublicBookingService $publicBookingService,
    ) {}

    public function show(string $bookingToken): JsonResponse
    {
        $reservation = $this->publicBookingService->findBooking($bookingToken);

        return response()->json(['data' => new PublicBookingResource($reservation)]);
    }

    public function cancel(string $bookingToken): JsonResponse
    {
        $reservation = $this->publicBookingService->cancelBooking($bookingToken);

        return response()->json(['data' => new PublicBookingResource($reservation)]);
    }
}
