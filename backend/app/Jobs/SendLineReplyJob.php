<?php

namespace App\Jobs;

use App\Repositories\LineSettingRepository;
use App\Services\Line\LineApiException;
use App\Services\Line\LineClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * LINE 応答メッセージを送信する。
 * replyToken は短命・単回使用のためリトライせず（tries=1）、失敗はログのみ残す。
 */
class SendLineReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function __construct(
        public readonly int $lineSettingId,
        public readonly string $replyToken,
        public readonly array $messages,
    ) {}

    public function handle(
        LineSettingRepository $lineSettingRepository,
        LineClient $lineClient,
    ): void {
        $setting = $lineSettingRepository->find($this->lineSettingId);

        if ($setting === null) {
            return;
        }

        try {
            $lineClient->reply($setting->channel_access_token, $this->replyToken, $this->messages);
        } catch (LineApiException $e) {
            Log::warning('LINE reply の送信に失敗しました。', [
                'salon_id' => $setting->salon_id,
                'status' => $e->status,
            ]);
        }
    }
}
