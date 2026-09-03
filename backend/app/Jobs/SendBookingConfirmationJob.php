<?php

namespace App\Jobs;

use App\Enums\Feature;
use App\Enums\ReservationStatus;
use App\Repositories\ReservationRepository;
use App\Services\Billing\EntitlementService;
use App\Services\Line\LineApiException;
use App\Services\Line\LineClient;
use App\Services\Line\LineMessages;
use App\Services\Line\LineRateLimitException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Web予約確定 push を1通送信する（連携済み顧客のみ。公開予約APIから dispatch される）。
 */
class SendBookingConfirmationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $reservationId,
    ) {}

    public function handle(
        ReservationRepository $reservationRepository,
        LineClient $lineClient,
        EntitlementService $entitlements,
    ): void {
        $reservation = $reservationRepository->findWithLineContext($this->reservationId);

        // 送信直前の再確認（キャンセル・未連携・連携解除はスキップ）
        if ($reservation === null
            || $reservation->status !== ReservationStatus::Reserved
            || $reservation->customer?->line_user_id === null
            || $reservation->salon->lineSetting?->is_active !== true
            || ! $entitlements->can($reservation->salon_id, Feature::Line)) {
            return;
        }

        try {
            $lineClient->push(
                $reservation->salon->lineSetting->channel_access_token,
                $reservation->customer->line_user_id,
                [LineMessages::bookingConfirmation($reservation)],
            );
        } catch (LineRateLimitException $e) {
            // 月間上限は月内に回復しないため恒久エラー（リトライ打ち切り）
            Log::error('LINE 予約確定通知がレート制限（429）のため打ち切りました。', [
                'reservation_id' => $reservation->id,
                'salon_id' => $reservation->salon_id,
            ]);
            $this->fail($e);
        } catch (LineApiException $e) {
            Log::warning('LINE 予約確定通知の送信に失敗しました。リトライします。', [
                'reservation_id' => $reservation->id,
                'salon_id' => $reservation->salon_id,
                'status' => $e->status,
            ]);

            throw $e;
        }
    }
}
