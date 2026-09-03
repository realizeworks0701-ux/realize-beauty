<?php

namespace App\Jobs;

use App\Enums\Feature;
use App\Repositories\LineSettingRepository;
use App\Services\Billing\EntitlementService;
use App\Services\LineEventService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 署名検証済みの LINE webhook イベントを1件処理する。
 */
class ProcessLineEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $lineSettingId,
        public readonly array $event,
    ) {}

    public function handle(
        LineSettingRepository $lineSettingRepository,
        LineEventService $lineEventService,
        EntitlementService $entitlements,
    ): void {
        $setting = $lineSettingRepository->find($this->lineSettingId);

        // 処理前に連携解除された、またはプラン対象外になった場合はスキップ
        if ($setting === null || ! $entitlements->can($setting->salon_id, Feature::Line)) {
            return;
        }

        $lineEventService->process($setting, $this->event);
    }
}
