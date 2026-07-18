<?php

namespace App\Services\Google;

use RuntimeException;

/**
 * Google API 呼び出し失敗（4xx / 5xx / 接続失敗）の基底。
 * 接続失敗（タイムアウト等）は status 0 で表す。
 */
class GoogleApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Google API request failed with status {$status}");
    }
}
