<?php

namespace App\Services\Line;

/**
 * LINE API のレート制限・月間上限到達（429）。
 * 月間上限は月内に回復しないため、リトライせず恒久エラーとして扱う。
 */
class LineRateLimitException extends LineApiException
{
    public function __construct(string $message = '')
    {
        parent::__construct(429, $message !== '' ? $message : 'LINE API rate limit exceeded (429).');
    }
}
