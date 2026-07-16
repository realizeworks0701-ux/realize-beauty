<?php

namespace App\Http\Controllers\Api\PublicV1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicBooking\CreatePublicReservationRequest;
use App\Http\Resources\PublicReservationResource;
use App\Services\PublicBookingService;
use Illuminate\Http\JsonResponse;

class PublicReservationController extends Controller
{
    public function __construct(
        private readonly PublicBookingService $publicBookingService,
    ) {}

    public function __invoke(CreatePublicReservationRequest $request, string $bookingSlug): JsonResponse
    {
        $booking = $this->publicBookingService->create($bookingSlug, $request->validated());

        return response()->json([
            'data' => new PublicReservationResource($booking['reservation'], $booking['line']),
        ], 201);
    }
}
