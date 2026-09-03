<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Stripe の設定不備（未設定、または Live/Test キーと APP_ENV の取り違え）。
 *
 * 本番に Test キー、開発に Live キーが入った状態で決済フローを走らせないための安全弁。
 */
class StripeConfigException extends RuntimeException {}
