<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\StripeSignatureException;
use App\Services\Billing\StripeWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeWebhookService $stripeWebhookService,
    ) {}

    /**
     * 署名が検証できないリクエストは 400 で拒否する（LINE の「常に 200」とは異なる）。
     * Stripe は 4xx を「自分宛でない・設定不備」と扱い、ダッシュボードに失敗として残すため、
     * 取り違えや秘密鍵の不一致に気づける。処理できた場合と対象外の場合は 200 で受理する。
     */
    public function __invoke(Request $request): Response
    {
        try {
            $this->stripeWebhookService->handle(
                $request->getContent(),
                $request->header('Stripe-Signature'),
            );
        } catch (StripeSignatureException $e) {
            Log::warning('Stripe webhook rejected', ['reason' => $e->getMessage()]);

            return response()->noContent(400);
        }

        return response()->noContent(200);
    }
}
