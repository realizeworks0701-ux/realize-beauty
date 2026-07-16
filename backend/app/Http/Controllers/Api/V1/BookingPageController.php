<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingPageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingPageController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new BookingPageResource($request->user()->salon)]);
    }
}
