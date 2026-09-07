<?php

namespace App\Services\Billing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Stripe の設定不備（未設定、または Live/Test キーと APP_ENV の取り違え）。
 *
 * 本番に Test キー、開発に Live キーが入った状態で決済フローを走らせないための安全弁。
 *
 * getMessage() は「どの環境にどちらのキーが入っているか」を含むため利用者へは出さない。
 * 詳細はログに残る（render() を持っていてもレポートは行われる）。
 */
class StripeConfigException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'お支払い機能の設定に不備があります。管理者にお問い合わせください。',
        ], 503);
    }
}
