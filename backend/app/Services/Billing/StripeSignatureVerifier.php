<?php

namespace App\Services\Billing;

use Illuminate\Support\Carbon;

/**
 * Stripe-Signature ヘッダの検証（ADR-029）。
 *
 * 公式 SDK を入れない方針のため自前で実装する。Stripe の仕様どおり
 * "{timestamp}.{payload}" を webhook secret で HMAC-SHA256 し、v1 スキームの
 * いずれかと一致するかを hash_equals（タイミング安全）で比較する。
 * 併せて timestamp の乖離を検査し、古い署名の再送（リプレイ）を弾く。
 */
class StripeSignatureVerifier
{
    /**
     * @throws StripeSignatureException
     */
    public function verify(string $payload, ?string $signatureHeader): void
    {
        $secret = config('billing.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new StripeSignatureException('STRIPE_WEBHOOK_SECRET が設定されていません。');
        }

        if ($signatureHeader === null || $signatureHeader === '') {
            throw new StripeSignatureException('Stripe-Signature ヘッダがありません。');
        }

        [$timestamp, $signatures] = $this->parse($signatureHeader);

        if ($timestamp === null || $signatures === []) {
            throw new StripeSignatureException('Stripe-Signature ヘッダの形式が不正です。');
        }

        $tolerance = (int) config('billing.stripe.webhook_tolerance');
        if ($tolerance > 0 && abs(Carbon::now()->getTimestamp() - $timestamp) > $tolerance) {
            throw new StripeSignatureException('Stripe-Signature のタイムスタンプが許容範囲外です。');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new StripeSignatureException('Stripe-Signature の検証に失敗しました。');
    }

    /**
     * "t=1234,v1=abc,v1=def" を timestamp と v1 署名の配列に分解する。
     *
     * @return array{0: ?int, 1: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
