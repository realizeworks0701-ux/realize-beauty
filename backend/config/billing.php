<?php

use App\Enums\Feature;
use App\Enums\SubscriptionPlan;

return [

    /*
    |--------------------------------------------------------------------------
    | プランカタログ（ADR-029）
    |--------------------------------------------------------------------------
    |
    | プラン → 利用可能機能の対応表。アプリ全体で唯一の正とし、
    | 「Pro だから AI 要約」のようなプラン名ベースの判定をコードに書かない。
    | 機能を増減する場合はこの features 配列だけを変更する。
    |
    | Stripe の Price ID は環境ごとに異なるため env から注入する（ハードコード禁止）。
    | DEV は Test Mode の price_xxx、本番は Live Mode の price_xxx を設定する。
    |
    */

    'plans' => [

        SubscriptionPlan::Lite->value => [
            'label' => 'Lite',
            'monthly_price' => 980,
            'stripe_price_id' => env('STRIPE_PRICE_LITE'),
            'features' => [
                Feature::Customer->value,
                Feature::MedicalRecord->value,
                Feature::Photo->value,
            ],
        ],

        SubscriptionPlan::Standard->value => [
            'label' => 'Standard',
            'monthly_price' => 1980,
            'stripe_price_id' => env('STRIPE_PRICE_STANDARD'),
            'features' => [
                Feature::Customer->value,
                Feature::MedicalRecord->value,
                Feature::Photo->value,
                Feature::Reservation->value,
                Feature::GoogleCalendar->value,
                Feature::Line->value,
            ],
        ],

        SubscriptionPlan::Pro->value => [
            'label' => 'Pro',
            'monthly_price' => 3980,
            'stripe_price_id' => env('STRIPE_PRICE_PRO'),
            'features' => [
                Feature::Customer->value,
                Feature::MedicalRecord->value,
                Feature::Photo->value,
                Feature::Reservation->value,
                Feature::GoogleCalendar->value,
                Feature::Line->value,
                Feature::AiSummary->value,
                Feature::Analytics->value,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe（ADR-029 §DEV/PRODUCTION 分離）
    |--------------------------------------------------------------------------
    |
    | secret は絶対にフロントエンドへ渡さない。key（publishable）は Checkout の
    | リダイレクト方式では不要だが、将来 Stripe.js を使う場合に備えて保持する。
    |
    | DEV は Test Mode（sk_test_ / pk_test_）、本番は Live Mode（sk_live_ / pk_live_）。
    | 取り違えは StripeClient が起動時に検出して例外にする（php artisan stripe:check）。
    |
    */

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_base_url' => env('STRIPE_API_BASE_URL', 'https://api.stripe.com'),
        'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),
        'timeout' => (int) env('STRIPE_TIMEOUT', 15),

        // Webhook 署名の許容時刻差（秒）。Stripe 推奨は 300。リプレイ攻撃の窓を絞る。
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),

        // Live/Test キーと APP_ENV の突き合わせ検査。テスト以外で無効化しない。
        'enforce_mode' => (bool) env('STRIPE_ENFORCE_MODE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout / Customer Portal の戻り先
    |--------------------------------------------------------------------------
    |
    | SPA は API とは別オリジンのため FRONTEND_URL を基点にする。
    |
    */

    'return_path' => env('BILLING_RETURN_PATH', '/settings/plan'),

];
