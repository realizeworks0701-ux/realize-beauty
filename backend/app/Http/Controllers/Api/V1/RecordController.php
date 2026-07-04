<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Record\CreateRecordRequest;
use App\Http\Requests\Record\UpdateRecordRequest;
use App\Http\Resources\RecordResource;
use App\Services\RecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RecordController extends Controller
{
    public function __construct(
        private readonly RecordService $recordService,
    ) {}

    public function index(Request $request, int $customerId): JsonResponse
    {
        $records = $this->recordService->list(
            $request->user()->salon_id,
            $customerId,
            $request->only(['per_page']),
        );

        return response()->json(RecordResource::collection($records)->response()->getData(true));
    }

    public function store(CreateRecordRequest $request, int $customerId): JsonResponse
    {
        $record = $this->recordService->create(
            $request->user()->salon_id,
            $customerId,
            $request->user()->id,
            $request->validated(),
        );

        return response()->json(['data' => new RecordResource($record)], 201);
    }

    public function show(Request $request, int $recordId): JsonResponse
    {
        $record = $this->recordService->find(
            $request->user()->salon_id,
            $recordId,
        );

        return response()->json(['data' => new RecordResource($record)]);
    }

    public function update(UpdateRecordRequest $request, int $recordId): JsonResponse
    {
        $record = $this->recordService->update(
            $request->user()->salon_id,
            $recordId,
            $request->validated(),
        );

        return response()->json(['data' => new RecordResource($record)]);
    }

    public function destroy(Request $request, int $recordId): Response
    {
        $this->recordService->delete(
            $request->user()->salon_id,
            $recordId,
        );

        return response()->noContent();
    }
}
