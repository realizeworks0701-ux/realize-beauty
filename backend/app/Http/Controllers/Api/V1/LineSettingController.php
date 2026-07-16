<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LineSetting\UpdateLineSettingRequest;
use App\Http\Resources\LineSettingResource;
use App\Services\LineSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LineSettingController extends Controller
{
    public function __construct(
        private readonly LineSettingService $lineSettingService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $setting = $this->lineSettingService->get($request->user()->salon_id);

        return response()->json(['data' => new LineSettingResource($setting)]);
    }

    public function update(UpdateLineSettingRequest $request): JsonResponse
    {
        $setting = $this->lineSettingService->upsert(
            $request->user()->salon_id,
            $request->validated(),
        );

        return response()->json(['data' => new LineSettingResource($setting)]);
    }

    public function verify(Request $request): JsonResponse
    {
        $setting = $this->lineSettingService->verify($request->user()->salon_id);

        return response()->json(['data' => new LineSettingResource($setting)]);
    }

    public function destroy(Request $request): Response
    {
        $this->lineSettingService->disconnect($request->user()->salon_id);

        return response()->noContent();
    }
}
