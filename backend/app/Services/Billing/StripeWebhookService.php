<?php

namespace App\Services\Billing;

use App\Repositories\StripeWebhookEventRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe Webhook の受信処理（ADR-029）。
 *
 * 契約状態はフロントの申告ではなく必ずこの経路で同期する。
 * 署名検証 → 冪等性の確保 → payload から必要項目だけを取り出して反映、の順で行い、
 * payload をそのまま DB へ書かない。
 */
class StripeWebhookService
{
    public function __construct(
        private readonly StripeSignatureVerifier $signatureVerifier,
        private readonly StripeWebhookEventRepository $webhookEventRepository,
        private readonly SubscriptionService $subscriptionService,
    ) {}

    /**
     * @throws StripeSignatureException 署名検証に失敗した場合（呼び出し側は 400 を返す）
     */
    public function handle(string $payload, ?string $signatureHeader): void
    {
        $this->signatureVerifier->verify($payload, $signatureHeader);

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw new StripeSignatureException('Webhook のペイロードを解釈できませんでした。');
        }

        $eventId = $event['id'] ?? null;
        $type = $event['type'] ?? null;

        if (! is_string($eventId) || ! is_string($type)) {
            throw new StripeSignatureException('Webhook のペイロードに id / type がありません。');
        }

        $occurredAt = isset($event['created']) && is_int($event['created'])
            ? Carbon::createFromTimestampUTC($event['created'])
            : null;

        // 同一イベントの再送はここで止める。unique 制約が唯一のガード。
        if (! $this->webhookEventRepository->claim($eventId, $type, $occurredAt)) {
            Log::info('Stripe webhook skipped as duplicate', ['event_id' => $eventId, 'type' => $type]);

            return;
        }

        try {
            $handled = $this->dispatch($type, $event['data']['object'] ?? [], $eventId, $occurredAt);
        } catch (Throwable $e) {
            // 失敗を記録して Stripe の再送で復旧できるようにしたうえで、
            // 呼び出し元に投げ直して 500 を返す（Stripe が再送する）。
            $this->webhookEventRepository->markFailed($eventId, $e::class);

            throw $e;
        }

        $handled
            ? $this->webhookEventRepository->markProcessed($eventId)
            : $this->webhookEventRepository->markSkipped($eventId, '対象外のイベント種別、または該当するサロンが見つかりませんでした。');
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function dispatch(string $type, array $object, string $eventId, ?Carbon $occurredAt): bool
    {
        return match ($type) {
            'checkout.session.completed' => $this->subscriptionService->applyCheckoutSession($object, $eventId, $occurredAt) !== null,

            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->subscriptionService->syncFromStripe($object, $eventId, $occurredAt) !== null,

            'invoice.payment_failed' => $this->subscriptionService->recordPaymentFailure($object, $eventId) !== null,

            // 支払い成功は subscription.updated で状態が届くため、ここでは記録のみ。
            'invoice.paid' => true,

            default => false,
        };
    }
}
