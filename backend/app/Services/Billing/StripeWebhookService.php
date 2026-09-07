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
     * @throws StripeConfigException Stripe のモードを判定できない場合（5xx を返して Stripe に再送させる）
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
            // 署名は通ったが、モードが食い違うイベント。本番の whsec を別環境に貼るなどの
            // 取り違えでしか起きないが、起きた場合はサロンIDが両DBとも1始まりのため
            // metadata.salon_id が必ず「どれかのサロン」に当たってしまう。
            // 監査のため claim 後に failed として記録する。
            $rejection = $this->modeRejectionReason($event);

            if ($rejection !== null) {
                Log::warning('Stripe webhook rejected for livemode mismatch', [
                    'event_id' => $eventId,
                    'type' => $type,
                    'event_livemode' => $event['livemode'] ?? null,
                ]);

                $this->webhookEventRepository->markFailed($eventId, $rejection);

                return;
            }

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
     * このイベントを取り違えとして拒否する理由を返す。受理してよければ null。
     *
     * 拒否したイベントは skipped ではなく failed として記録する。
     * StripeWebhookEventRepository::claim() が再処理を許すのは failed と滞留した
     * processing だけで、skipped にするとその evt_ が二度と処理できなくなり、
     * ダッシュボードからの再送も重複として弾かれるため。
     *
     * @param  array<string, mixed>  $event
     *
     * @throws StripeConfigException モードを判定できない場合（呼び出し元へ抜けて 5xx になる）
     */
    private function modeRejectionReason(array $event): ?string
    {
        // Stripe の Event は常に livemode を持つ。欠けているものは正規のイベントではない。
        if (! array_key_exists('livemode', $event) || ! is_bool($event['livemode'])) {
            return 'イベントに livemode が無いか、真偽値ではありません。';
        }

        $mode = StripeClient::configuredMode();

        // モードを判定できないのは「別環境のイベントが届いた」のではなく設定不備
        // （STRIPE_SECRET 未設定・想定外の接頭辞）。取り違えとして握りつぶすと、
        // 正しい署名の Live イベントを 200 で捨て続けることになるため例外にする。
        // 呼び出し元の catch が failed として記録したうえで 5xx を返し、Stripe に再送させる。
        if ($mode === null) {
            throw new StripeConfigException(
                'STRIPE_SECRET が未設定か想定外の形式のため、Stripe のモード（live/test）を判定できません。',
            );
        }

        return $event['livemode'] === ($mode === StripeClient::MODE_LIVE)
            ? null
            : 'イベントの livemode が STRIPE_SECRET のモードと一致しません。';
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
