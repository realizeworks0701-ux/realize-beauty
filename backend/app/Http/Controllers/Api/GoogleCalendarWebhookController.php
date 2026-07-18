<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Google\GoogleCalendarWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GoogleCalendarWebhookController extends Controller
{
    public function __construct(
        private readonly GoogleCalendarWebhookService $service,
    ) {}

    /**
     * Google のリトライ暴走を防ぐため、3段検証のいずれに失敗しても常に 200 を返す。
     */
    public function __invoke(Request $request): Response
    {
        $this->service->handle(
            $request->header('X-Goog-Channel-ID'),
            $request->header('X-Goog-Channel-Token'),
            $request->header('X-Goog-Resource-ID'),
            $request->header('X-Goog-Resource-State'),
        );

        return response()->noContent(200);
    }
}
