<?php

namespace App\Services;

use App\Jobs\SendLineReplyJob;
use App\Models\LineSetting;
use App\Repositories\CustomerRepository;
use App\Services\Line\LineMessages;

/**
 * 署名検証済みの LINE webhook イベントを処理する（連携コードのライフサイクルは booking.md）。
 */
class LineEventService
{
    public function __construct(
        private readonly CustomerRepository $customerRepository,
    ) {}

    public function process(LineSetting $setting, array $event): void
    {
        match ($event['type'] ?? null) {
            'follow' => $this->handleFollow($setting, $event),
            'message' => $this->handleMessage($setting, $event),
            'unfollow' => $this->handleUnfollow($setting, $event),
            default => null,
        };
    }

    private function handleFollow(LineSetting $setting, array $event): void
    {
        $this->reply($setting, $event, LineMessages::followGreeting());
    }

    /**
     * text メッセージを連携コードとして照合する。
     * 不一致（期限切れ・連携済み顧客のコードを含む）は誤爆防止のため reply しない。
     */
    private function handleMessage(LineSetting $setting, array $event): void
    {
        if (($event['message']['type'] ?? null) !== 'text') {
            return;
        }

        $lineUserId = $event['source']['userId'] ?? null;
        $text = $event['message']['text'] ?? null;

        if (! is_string($lineUserId) || ! is_string($text)) {
            return;
        }

        // 全角スペース（U+3000）も trim 対象にする（booking.md「前後の空白を除去し大文字化して比較」）
        $code = strtoupper(preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $text));

        $customer = $this->customerRepository->findByActiveLineLinkCode($setting->salon_id, $code);

        if ($customer === null) {
            return;
        }

        // 事前チェックで部分 unique index (salon_id, line_user_id) の制約違反を回避する
        if ($this->customerRepository->findByLineUserId($setting->salon_id, $lineUserId) !== null) {
            $this->reply($setting, $event, LineMessages::alreadyLinked());

            return;
        }

        $this->customerRepository->linkLineUser($customer, $lineUserId);

        $this->reply($setting, $event, LineMessages::linkCompleted());
    }

    private function handleUnfollow(LineSetting $setting, array $event): void
    {
        $lineUserId = $event['source']['userId'] ?? null;

        if (! is_string($lineUserId)) {
            return;
        }

        $this->customerRepository->unlinkByLineUserId($setting->salon_id, $lineUserId);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function reply(LineSetting $setting, array $event, array $message): void
    {
        $replyToken = $event['replyToken'] ?? null;

        if (! is_string($replyToken) || $replyToken === '') {
            return;
        }

        SendLineReplyJob::dispatch($setting->id, $replyToken, [$message]);
    }
}
