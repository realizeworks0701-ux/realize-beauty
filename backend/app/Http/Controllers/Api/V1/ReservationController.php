<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\CreateReservationRequest;
use App\Http\Requests\Reservation\ListReservationsRequest;
use App\Http\Requests\Reservation\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    public function index(ListReservationsRequest $request): JsonResponse
    {
        $reservations = $this->reservationService->list(
            $request->user()->salon_id,
            $request->validated(),
        );

        return response()->json(['data' => ReservationResource::collection($reservations)]);
    }

    public function store(CreateReservationRequest $request): JsonResponse
    {
        $reservation = $this->reservationService->create(
            $request->user()->salon_id,
            $request->validated(),
        );

        return response()->json(['data' => new ReservationResource($reservation)], 201);
    }

    public function show(Request $request, int $reservationId): JsonResponse
    {
        $reservation = $this->reservationService->find(
            $request->user()->salon_id,
            $reservationId,
        );

        return response()->json(['data' => new ReservationResource($reservation)]);
    }

    public function update(UpdateReservationRequest $request, int $reservationId): JsonResponse
    {
        $reservation = $this->reservationService->update(
            $request->user()->salon_id,
            $reservationId,
            $request->validated(),
        );

        return response()->json(['data' => new ReservationResource($reservation)]);
    }

    public function destroy(Request $request, int $reservationId): Response
    {
        $this->reservationService->delete(
            $request->user()->salon_id,
            $reservationId,
        );

        return response()->noContent();
    }
}
