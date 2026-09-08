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

    // SPA は全リクエストに Authorization ヘッダを付けるため、すべてがプリフライト対象になる。
    // 既定の 0 は Access-Control-Max-Age: 0 を返し、ブラウザが結果を一切キャッシュしないので、
    // API 呼び出し1回ごとに OPTIONS と本リクエストで2往復していた（本番実測で OPTIONS 単体 418ms）。
    // 2時間にしておく。Chrome の上限が 7200 秒で、それ以上を返しても切り詰められるだけ。
    // 副作用として、許可オリジンやヘッダを絞る変更は最大2時間ブラウザ側に反映されない。
    'max_age' => 7200,

    'supports_credentials' => false,

];
