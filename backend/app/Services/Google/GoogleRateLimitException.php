<?php

namespace App\Services\Google;

/**
 * レート制限・クォータ超過（429 / 403 rateLimitExceeded）。
 * 429 の Retry-After は固定バックオフで押し返さず従う（ADR-025 Consequences）。
 */
class GoogleRateLimitException extends GoogleApiException
{
    public function __construct(
        int $status = 429,
        public readonly ?int $retryAfter = null,
        string $message = '',
    ) {
        parent::__construct($status, $message !== '' ? $message : "Google API rate limit exceeded ({$status}).");
    }
}
