<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Stripe Webhook の署名検証に失敗した。自分宛でないリクエストとして 400 で返す。
 */
class StripeSignatureException extends RuntimeException {}
