<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GoogleCalendarMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleCalendar\ListGoogleBusyBlocksRequest;
use App\Http\Requests\GoogleCalendar\SetGoogleCalendarModeRequest;
use App\Http\Requests\GoogleCalendar\UpdateGoogleCalendarConnectionRequest;
use App\Http\Resources\GoogleBusyBlockResource;
use App\Http\Resources\GoogleCalendarConnectionResource;
use App\Services\Google\GoogleCalendarConnectionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GoogleCalendarController extends Controller
{
    public function __construct(
        private readonly GoogleCalendarConnectionService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->overviewResponse(
            $this->service->overview($request->user()->salon_id),
        );
    }

    public function setMode(SetGoogleCalendarModeRequest $request): JsonResponse
    {
        return $this->overviewResponse(
            $this->service->setMode($request->user(), $request->validated('mode')),
        );
    }

    public function authUrl(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['auth_url' => $this->service->buildAuthUrl($request->user())],
        ]);
    }

    /**
     * Google からのブラウザリダイレクト（認証なし）。SPA へ 302 で戻す。
     */
    public function callback(Request $request): RedirectResponse
    {
        return redirect()->away($this->service->handleCallback($request->query()));
    }

    public function calendars(Request $request, int $connectionId): JsonResponse
    {
        return response()->json([
            'data' => $this->service->listCalendars($request->user(), $connectionId),
        ]);
    }

    public function updateConnection(UpdateGoogleCalendarConnectionRequest $request, int $connectionId): JsonResponse
    {
        $connection = $this->service->changeCalendar(
            $request->user(),
            $connectionId,
            $request->validated('calendar_id'),
        );

        return response()->json(['data' => new GoogleCalendarConnectionResource($connection)]);
    }

    public function destroy(Request $request, int $connectionId): Response
    {
        $this->service->disconnect($request->user(), $connectionId);

        return response()->noContent();
    }

    public function busyBlocks(ListGoogleBusyBlocksRequest $request): JsonResponse
    {
        $blocks = $this->service->listBusyBlocks(
            $request->user()->salon_id,
            $request->validated(),
        );

        return response()->json(['data' => GoogleBusyBlockResource::collection($blocks)]);
    }

    /**
     * @param  array{mode: ?GoogleCalendarMode, connections: Collection}  $overview
     */
    private function overviewResponse(array $overview): JsonResponse
    {
        return response()->json([
            'data' => [
                'mode' => $overview['mode']?->value,
                'connections' => GoogleCalendarConnectionResource::collection($overview['connections']),
            ],
        ]);
    }
}
