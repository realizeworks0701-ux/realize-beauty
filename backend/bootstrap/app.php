<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Render はプロキシ配下で TLS を終端する。信頼しないと $request->ip() が常に
        // プロキシのIPになり、レート制限が全利用者で1つのバケットを共有してしまう。
        // X-Forwarded-Proto も見えないため生成される絶対URLが http:// になる。
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // QueryException の既定メッセージはバインド値（顧客の氏名・メール等）をそのまま含む。
        // 本番ログは Render のログストリームに残るため、プレースホルダのままのSQLだけを記録する。
        $exceptions->report(function (QueryException $e) {
            Log::error('DB query failed', [
                'sqlstate' => $e->getCode(),
                'connection' => $e->getConnectionName(),
                'sql' => $e->getSql(),
            ]);

            return false;
        });
    })->create();
