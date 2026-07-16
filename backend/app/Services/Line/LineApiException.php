<?php

namespace App\Services\Line;

use RuntimeException;

/**
 * LINE API 呼び出し失敗（4xx / 5xx）。
 */
class LineApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "LINE API request failed with status {$status}");
    }
}
