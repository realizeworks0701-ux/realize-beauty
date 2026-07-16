<?php

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Repositories\ReservationRepository;
use App\Services\Line\LineApiException;
use App\Services\Line\LineClient;
use App\Services\Line\LineMessages;
use App\Services\Line\LineRateLimitException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 前日リマインダー push を1予約分送信する。
 * 予約単位で一意（ShouldBeUnique）とし、コマンド再実行による二重送信を防ぐ。
 */
class SendReservationReminderJob implements ShouldBeUnique, ShouldQueue
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

    public function uniqueId(): string
    {
        return (string) $this->reservationId;
    }

    public function handle(
        ReservationRepository $reservationRepository,
        LineClient $lineClient,
    ): void {
        $reservation = $reservationRepository->findWithLineContext($this->reservationId);

        // 送信直前の再確認（18:00 以降のキャンセル・連携解除・送信済みはスキップ）
        if ($reservation === null
            || $reservation->status !== ReservationStatus::Reserved
            || $reservation->reminder_sent_at !== null
            || $reservation->customer?->line_user_id === null
            || $reservation->salon->lineSetting?->is_active !== true) {
            return;
        }

        try {
            $lineClient->push(
                $reservation->salon->lineSetting->channel_access_token,
                $reservation->customer->line_user_id,
                [LineMessages::reservationReminder($reservation)],
            );
        } catch (LineRateLimitException $e) {
            // 月間上限は月内に回復しないため恒久エラー（リトライ打ち切り）
            Log::error('LINE リマインダー送信がレート制限（429）のため打ち切りました。', [
                'reservation_id' => $reservation->id,
                'salon_id' => $reservation->salon_id,
            ]);
            $this->fail($e);

            return;
        } catch (LineApiException $e) {
            Log::warning('LINE リマインダー送信に失敗しました。リトライします。', [
                'reservation_id' => $reservation->id,
                'salon_id' => $reservation->salon_id,
                'status' => $e->status,
            ]);

            throw $e;
        }

        $reservationRepository->markReminderSent($reservation);
    }
}
