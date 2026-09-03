<?php

namespace App\Repositories;

use App\Models\StripeWebhookEvent;
use Illuminate\Support\Carbon;

class StripeWebhookEventRepository
{
    /**
     * processing のまま放置された記録を「異常終了した」とみなすまでの秒数。
     * Stripe の再送間隔より短く、同一イベントの同時処理と重ならない長さにする。
     */
    private const STALE_PROCESSING_SECONDS = 900;

    /**
     * 受信記録を確保し、このプロセスが処理してよいかを返す。
     *
     * stripe_event_id の unique 制約が二重処理のガード。既に成功・スキップ済みの
     * イベントが再送された場合は false を返し、呼び出し側は何もしない。
     * 前回失敗した記録は Stripe の再送で復旧できるよう true を返す。
     */
    public function claim(string $stripeEventId, string $type, ?Carbon $occurredAt): bool
    {
        $event = StripeWebhookEvent::firstOrCreate(
            ['stripe_event_id' => $stripeEventId],
            [
                'type' => $type,
                'status' => StripeWebhookEvent::STATUS_PROCESSING,
                'occurred_at' => $occurredAt,
            ],
        );

        if ($event->wasRecentlyCreated) {
            return true;
        }

        // 失敗した記録は再送で復旧させる。
        // processing のまま残った記録は、処理中にプロセスが落ちた（OOM・タイムアウト・デプロイ）
        // 痕跡なので、一定時間を過ぎたものは同じく再処理を許す。
        // これが無いと 1 度の異常終了でそのイベントが永久に握りつぶされる。
        $isStale = $event->status === StripeWebhookEvent::STATUS_PROCESSING
            && $event->updated_at !== null
            && $event->updated_at->lt(Carbon::now()->utc()->subSeconds(self::STALE_PROCESSING_SECONDS));

        if ($event->status === StripeWebhookEvent::STATUS_FAILED || $isStale) {
            $event->update([
                'status' => StripeWebhookEvent::STATUS_PROCESSING,
                'message' => null,
            ]);

            return true;
        }

        return false;
    }

    public function markProcessed(string $stripeEventId): void
    {
        $this->find($stripeEventId)?->update([
            'status' => StripeWebhookEvent::STATUS_PROCESSED,
            'processed_at' => Carbon::now()->utc(),
        ]);
    }

    public function markSkipped(string $stripeEventId, string $message): void
    {
        $this->find($stripeEventId)?->update([
            'status' => StripeWebhookEvent::STATUS_SKIPPED,
            'message' => $message,
            'processed_at' => Carbon::now()->utc(),
        ]);
    }

    public function markFailed(string $stripeEventId, string $message): void
    {
        $this->find($stripeEventId)?->update([
            'status' => StripeWebhookEvent::STATUS_FAILED,
            'message' => $message,
        ]);
    }

    public function find(string $stripeEventId): ?StripeWebhookEvent
    {
        return StripeWebhookEvent::where('stripe_event_id', $stripeEventId)->first();
    }
}
