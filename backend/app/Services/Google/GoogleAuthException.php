<?php

namespace App\Services\Google;

/**
 * 認証失敗（401 / invalid_grant）。
 * invalid_grant は refresh_token の失効・取り消しであり、再接続（needs_reconnect）が必要。
 * 回復にユーザー操作を要するため、同期ジョブはリトライせず打ち切る。
 */
class GoogleAuthException extends GoogleApiException
{
    public function __construct(int $status = 401, string $message = '')
    {
        parent::__construct($status, $message !== '' ? $message : 'Google API authentication failed.');
    }
}
