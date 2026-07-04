<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Photo\UploadPhotoRequest;
use App\Http\Resources\PhotoResource;
use App\Services\PhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PhotoController extends Controller
{
    public function __construct(
        private readonly PhotoService $photoService,
    ) {}

    public function store(UploadPhotoRequest $request, int $recordId): JsonResponse
    {
        $photo = $this->photoService->upload(
            $request->user()->salon_id,
            $recordId,
            $request->file('image'),
            $request->validated('caption'),
        );

        return response()->json(['data' => new PhotoResource($photo)], 201);
    }

    public function destroy(Request $request, int $photoId): Response
    {
        $this->photoService->delete(
            $request->user()->salon_id,
            $photoId,
        );

        return response()->noContent();
    }
}
