<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stripe REST API への薄いクライアント（ADR-029）。
 *
 * 公式 SDK を入れず Laravel の Http クライアントで実装する（ADR-021 と同じ方針。
 * Http::fake() でテストできる利点を優先）。
 *
 * DEV は Test Mode（sk_test_）、本番は Live Mode（sk_live_）。取り違えは
 * assertModeMatchesEnvironment() が最初の呼び出し時に例外にする。
 */
class StripeClient
{
    public function __construct() {}

    /**
     * Checkout セッションを作成し、リダイレクト先URLを含むレスポンスを返す。
     *
     * カード番号・CVC・有効期限は Stripe がホストする画面で入力されるため、
     * アプリのサーバーにはいかなる形でも到達しない。
     *
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     */
    public function createCheckoutSession(
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        ?string $customerId,
        ?string $customerEmail,
        array $metadata,
    ): array {
        $payload = [
            'mode' => 'subscription',
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $metadata['salon_id'] ?? null,
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
        ];

        // 既存 Customer があれば必ず再利用する（1サロン1 Customer）。
        if ($customerId !== null) {
            $payload['customer'] = $customerId;
        } elseif ($customerEmail !== null) {
            $payload['customer_email'] = $customerEmail;
        }

        return $this->post('/v1/checkout/sessions', array_filter(
            $payload,
            fn ($value) => $value !== null,
        ));
    }

    /**
     * Customer Portal のセッションを作成する。
     * 支払い方法の変更・請求履歴の確認は Stripe 側の画面に委ねる。
     *
     * @return array<string, mixed>
     */
    public function createBillingPortalSession(string $customerId, string $returnUrl): array
    {
        return $this->post('/v1/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveSubscription(string $subscriptionId): array
    {
        return $this->get("/v1/subscriptions/{$subscriptionId}");
    }

    /**
     * その Customer のサブスクリプションを列挙する。
     *
     * アプリDBは Webhook 到着まで最新ではないため、二重契約の判定には Stripe 側を直接見る。
     *
     * @return list<array<string, mixed>>
     */
    public function listSubscriptions(string $customerId, int $limit = 20): array
    {
        $response = $this->get('/v1/subscriptions', [
            'customer' => $customerId,
            'status' => 'all',
            'limit' => $limit,
        ]);

        return array_values(array_filter(
            $response['data'] ?? [],
            fn ($subscription) => is_array($subscription),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveCheckoutSession(string $sessionId): array
    {
        return $this->get("/v1/checkout/sessions/{$sessionId}");
    }

    /**
     * 契約中の price を差し替えてプランを変更する。
     *
     * 既存の subscription item を置き換える形にし、日割り精算は Stripe に委ねる
     * （proration_behavior=create_prorations）。独自の請求計算は行わない。
     *
     * @return array<string, mixed>
     */
    public function updateSubscriptionPrice(string $subscriptionId, string $itemId, string $priceId): array
    {
        return $this->post("/v1/subscriptions/{$subscriptionId}", [
            'items' => [['id' => $itemId, 'price' => $priceId]],
            'proration_behavior' => 'create_prorations',
            'payment_behavior' => 'error_if_incomplete',
        ]);
    }

    /**
     * 期間終了時の解約を予約する／取り消す。即時解約はしない（ADR-029 §解約）。
     *
     * @return array<string, mixed>
     */
    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): array
    {
        return $this->post("/v1/subscriptions/{$subscriptionId}", [
            'cancel_at_period_end' => $cancel ? 'true' : 'false',
        ]);
    }

    /**
     * Live/Test キーと APP_ENV の整合を検査する。設定確認コマンドからも使う。
     */
    public function assertModeMatchesEnvironment(): void
    {
        $secret = $this->secret();

        if (! config('billing.stripe.enforce_mode')) {
            return;
        }

        $isProduction = app()->environment('production');

        if ($isProduction && str_starts_with($secret, 'sk_test_')) {
            throw new StripeConfigException(
                '本番環境に Stripe の Test キーが設定されています。Live キー（sk_live_）を設定してください。',
            );
        }

        if (! $isProduction && str_starts_with($secret, 'sk_live_')) {
            throw new StripeConfigException(
                '本番以外の環境に Stripe の Live キーが設定されています。Test キー（sk_test_）を設定してください。',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send(
            fn (PendingRequest $request) => $request->get($path, $query),
            'GET',
            $path,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->send(
            fn (PendingRequest $request) => $request->asForm()->post($path, $payload),
            'POST',
            $path,
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call, string $method, string $path): array
    {
        $this->assertModeMatchesEnvironment();

        $response = $call($this->request());

        if ($response->failed()) {
            // Stripe のエラーメッセージは請求先情報を含みうるため type/code のみ記録する。
            Log::warning('Stripe API request failed', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'type' => $response->json('error.type'),
                'code' => $response->json('error.code'),
            ]);

            throw new StripeApiException(
                'Stripe API request failed with status '.$response->status(),
            );
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->secret())
            ->baseUrl((string) config('billing.stripe.api_base_url'))
            ->withHeaders(['Stripe-Version' => (string) config('billing.stripe.api_version')])
            ->timeout((int) config('billing.stripe.timeout'))
            ->acceptJson();
    }

    private function secret(): string
    {
        $secret = config('billing.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new StripeConfigException('STRIPE_SECRET が設定されていません。');
        }

        return $secret;
    }
}
