<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | フロント（Cloudflare Pages 等）が別ドメインから API を叩くための設定。
    | 認証は Sanctum の Bearer トークンのため credentials(Cookie) は不要。
    | 本番では CORS_ALLOWED_ORIGINS にフロントのURLをカンマ区切りで指定して絞る。
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        explode(',', env('CORS_ALLOWED_ORIGINS', '*'))
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
