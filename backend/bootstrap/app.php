<?php

use App\Http\Middleware\EnsureFeatureEnabled;
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
        // Render はプロキシ配下で TLS を終端する。信頼しないと X-Forwarded-Proto が
        // 見えず、生成される絶対URLが http:// になる。
        //
        // at: '*' は REMOTE_ADDR（直近の呼び出し元）だけを信頼する指定で、X-Forwarded-For を
        // 右から辿った「最後のホップ」を返す。Render は Cloudflare 配下のため、この値は
        // リクエストごとに変わり、IPベースのレート制限が機能しなかった（本番で実測）。
        // チェーン全体を信頼して左端＝本来のクライアントIPを採用する。
        // ただし左端は client が X-Forwarded-For を自分で付けると詐称できるため、
        // レート制限はIPだけに依存させない（AppServiceProvider の auth-login を参照）。
        $middleware->trustProxies(at: ['0.0.0.0/0', '::/0']);

        // 契約プランに含まれない機能を 403 で遮断する（ADR-029）。
        // 例: Route::middleware('feature:reservation')。auth:sanctum の内側でのみ使う。
        $middleware->alias([
            'feature' => EnsureFeatureEnabled::class,
        ]);
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
