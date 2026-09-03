<?php

namespace App\Exceptions;

use App\Enums\Feature;
use App\Enums\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * 契約プランに含まれない機能へアクセスした（ADR-029）。
 *
 * 403 を返す。401 にしてはならない（SPA の apiClient が 401 を検知すると
 * ローカルの認証状態を破棄してログイン画面へ飛ばすため）。
 * フロントのアップグレード導線が文言を組み立てられるよう、
 * 対象機能と必要プランを機械可読な形で添える。
 */
class FeatureRequiredException extends RuntimeException
{
    public function __construct(
        public readonly Feature $feature,
        public readonly ?SubscriptionPlan $currentPlan = null,
    ) {
        parent::__construct(self::buildMessage($feature));
    }

    public function requiredPlan(): ?SubscriptionPlan
    {
        return $this->feature->minimumPlan();
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'feature' => $this->feature->value,
            'required_plan' => $this->requiredPlan()?->value,
            'current_plan' => $this->currentPlan?->value,
        ], 403);
    }

    private static function buildMessage(Feature $feature): string
    {
        $requiredPlan = $feature->minimumPlan();

        if ($requiredPlan === null) {
            return "{$feature->label()}は現在のプランではご利用いただけません。";
        }

        return "{$feature->label()}は{$requiredPlan->label()}プラン以上でご利用いただけます。";
    }
}
