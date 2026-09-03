<?php

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Services\Billing\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 契約プランに含まれない機能のエンドポイントを 403 で遮断する（ADR-029）。
 *
 * 使い方: Route::middleware('feature:reservation')。複数指定はすべてを満たす必要がある。
 * auth:sanctum の内側でのみ使う（認証ユーザーからサロンを解決するため）。
 */
class EnsureFeatureEnabled
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $salonId = $request->user()?->salon_id;

        if ($salonId === null) {
            abort(401);
        }

        foreach ($features as $key) {
            $feature = Feature::tryFrom($key)
                ?? throw new InvalidArgumentException("未定義の機能キーです: {$key}");

            $this->entitlements->ensure($salonId, $feature);
        }

        return $next($request);
    }
}
