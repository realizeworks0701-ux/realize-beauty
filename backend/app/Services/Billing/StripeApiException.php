<?php

namespace App\Services\Billing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Stripe API がエラーを返した。メッセージに顧客情報を含めない。
 *
 * 上流の障害なので 502 を返す。Stripe 側の文言をそのまま出すと英語が混ざるため、
 * 利用者へは日本語の一般化した文言を返し、詳細はログに残す。
 */
class StripeApiException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'お支払いサービスに接続できませんでした。時間をおいて再度お試しください。',
        ], 502);
    }
}
