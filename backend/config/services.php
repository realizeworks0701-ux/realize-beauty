<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => env('OPENAI_TIMEOUT', 30),
    ],

    'line' => [
        'base_url' => env('LINE_BASE_URL', 'https://api.line.me'),
        'timeout' => env('LINE_TIMEOUT', 10),
    ],

    /*
     * Googleカレンダー同期（ADR-025 §9）。
     * 資格情報は env、トークンは google_calendar_connections（encrypted cast）の混在型。
     * OAuth 系（accounts.google.com / oauth2.googleapis.com）と API 系（www.googleapis.com）は別ホスト。
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'auth_base_url' => env('GOOGLE_AUTH_BASE_URL', 'https://accounts.google.com'),
        'token_url' => env('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'revoke_url' => env('GOOGLE_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
        'api_base_url' => env('GOOGLE_API_BASE_URL', 'https://www.googleapis.com'),
        'timeout' => env('GOOGLE_TIMEOUT', 10),
        // staleness ガードの許容幅（秒）。RB の更新〜送信同期完了の時差と時計ずれを吸収する
        'sync_leeway_seconds' => env('GOOGLE_SYNC_LEEWAY_SECONDS', 10),
    ],

];
