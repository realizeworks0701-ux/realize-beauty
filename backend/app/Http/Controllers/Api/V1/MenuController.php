<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\CreateMenuRequest;
use App\Http\Requests\Menu\UpdateMenuRequest;
use App\Http\Resources\MenuResource;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MenuController extends Controller
{
    public function __construct(
        private readonly MenuService $menuService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $menus = $this->menuService->list(
            $request->user()->salon_id,
            $request->only(['is_active']),
        );

        return response()->json(['data' => MenuResource::collection($menus)]);
    }

    public function store(CreateMenuRequest $request): JsonResponse
    {
        $menu = $this->menuService->create(
            $request->user()->salon_id,
            $request->validated(),
        );

        return response()->json(['data' => new MenuResource($menu)], 201);
    }

    public function show(Request $request, int $menuId): JsonResponse
    {
        $menu = $this->menuService->find(
            $request->user()->salon_id,
            $menuId,
        );

        return response()->json(['data' => new MenuResource($menu)]);
    }

    public function update(UpdateMenuRequest $request, int $menuId): JsonResponse
    {
        $menu = $this->menuService->update(
            $request->user()->salon_id,
            $menuId,
            $request->validated(),
        );

        return response()->json(['data' => new MenuResource($menu)]);
    }

    public function destroy(Request $request, int $menuId): Response
    {
        $this->menuService->delete(
            $request->user()->salon_id,
            $menuId,
        );

        return response()->noContent();
    }
}
