<?php

namespace App\Services\Google;

/**
 * syncToken の失効（410 Gone）。
 * 保存済み sync_token を破棄して全同期し直す契機であり、異常ではなく設計上の常道。
 */
class GoogleSyncTokenExpiredException extends GoogleApiException
{
    public function __construct(string $message = '')
    {
        parent::__construct(410, $message !== '' ? $message : 'Google Calendar sync token has expired (410).');
    }
}
