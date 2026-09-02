<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | フロント（Cloudflare Pages 等）が別ドメインから API を叩くための設定。
    | 認証は Sanctum の Bearer トークンのため credentials(Cookie) は不要。
    | CORS_ALLOWED_ORIGINS にフロントのURLをカンマ区切りで指定する。
    | 未設定なら別オリジンからの呼び出しを一切許可しない（設定漏れで全開放しない）。
    | ローカル開発は Vite プロキシ経由の同一オリジンのため設定不要。
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
