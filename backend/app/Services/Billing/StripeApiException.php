<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Stripe API がエラーを返した。メッセージに顧客情報を含めない。
 */
class StripeApiException extends RuntimeException {}
