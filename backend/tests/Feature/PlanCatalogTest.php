<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Enums\SubscriptionPlan;
use Tests\TestCase;

/**
 * プランカタログの内容そのものを固定する（ADR-029）。
 *
 * config/billing.php はプラン→機能の単一の正だが、フロントエンドの
 * frontend/src/composables/useFeatures.ts にも同じ対応表の写しがある
 * （ナビやアップグレード導線を API 往復なしで組み立てるため）。
 * 片方だけ変えると「必要なプラン」の案内だけがずれるので、
 * ここで内容を固定し、変更時に必ず両方を見直させる。
 */
class PlanCatalogTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    private const EXPECTED = [
        'lite' => ['customer', 'medical_record', 'photo'],
        'standard' => ['customer', 'medical_record', 'photo', 'reservation', 'google_calendar', 'line'],
        'pro' => ['customer', 'medical_record', 'photo', 'reservation', 'google_calendar', 'line', 'ai_summary', 'analytics'],
    ];

    private const EXPECTED_PRICES = [
        'lite' => 980,
        'standard' => 1980,
        'pro' => 3980,
    ];

    public function test_each_plan_grants_exactly_the_documented_features(): void
    {
        foreach (SubscriptionPlan::cases() as $plan) {
            $this->assertSame(
                self::EXPECTED[$plan->value],
                array_map(fn (Feature $feature) => $feature->value, $plan->features()),
                "{$plan->value} の機能一覧が変わりました。frontend/src/composables/useFeatures.ts の"
                    .' PLAN_FEATURES も更新してください。',
            );
        }
    }

    public function test_monthly_prices_match_the_published_plans(): void
    {
        foreach (SubscriptionPlan::cases() as $plan) {
            $this->assertSame(self::EXPECTED_PRICES[$plan->value], $plan->monthlyPrice());
        }
    }

    /**
     * 上位プランは下位プランの機能をすべて含む。
     * これが崩れると Feature::minimumPlan() の「最も安いプラン」が意味を失う。
     */
    public function test_plans_are_strictly_cumulative_in_declaration_order(): void
    {
        $previous = [];

        foreach (SubscriptionPlan::cases() as $plan) {
            $current = $plan->features();

            foreach ($previous as $feature) {
                $this->assertContains(
                    $feature,
                    $current,
                    "{$plan->value} が下位プランの {$feature->value} を含んでいません。",
                );
            }

            $previous = $current;
        }
    }

    /**
     * どの Feature も、いずれかのプランには含まれている（宙に浮いた機能を作らない）。
     */
    public function test_every_feature_belongs_to_at_least_one_plan(): void
    {
        foreach (Feature::cases() as $feature) {
            $this->assertNotNull(
                $feature->minimumPlan(),
                "{$feature->value} がどのプランにも含まれていません。",
            );
        }
    }
}
