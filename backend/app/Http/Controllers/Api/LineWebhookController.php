<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LineWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LineWebhookController extends Controller
{
    public function __construct(
        private readonly LineWebhookService $lineWebhookService,
    ) {}

    /**
     * LINE のリトライ暴走を防ぐため、署名検証失敗・未知の destination でも常に 200 を返す。
     */
    public function __invoke(Request $request): Response
    {
        $this->lineWebhookService->handle(
            $request->getContent(),
            $request->header('x-line-signature'),
        );

        return response()->noContent(200);
    }
}
