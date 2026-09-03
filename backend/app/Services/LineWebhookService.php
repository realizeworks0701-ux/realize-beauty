<?php

namespace App\Services;

use App\Enums\Feature;
use App\Jobs\ProcessLineEventJob;
use App\Repositories\LineSettingRepository;
use App\Services\Billing\EntitlementService;
use Illuminate\Support\Facades\Log;

class LineWebhookService
{
    /**
     * 処理対象のイベント種別（それ以外は無視する）。
     */
    private const HANDLED_EVENT_TYPES = ['follow', 'message', 'unfollow'];

    public function __construct(
        private readonly LineSettingRepository $lineSettingRepository,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * webhook を受理する。LINE のリトライ暴走を防ぐため、
     * 未知の destination・署名検証失敗でも例外にせずログのみ残す（レスポンスは常に 200）。
     */
    public function handle(string $rawBody, ?string $signature): void
    {
        $payload = json_decode($rawBody, true);
        $destination = is_array($payload) ? ($payload['destination'] ?? null) : null;

        if (! is_string($destination) || $destination === '') {
            Log::warning('LINE webhook: destination がありません。');

            return;
        }

        // 未知の destination は署名計算前に即終了する（DB照会1回のみ）
        $setting = $this->lineSettingRepository->findByBotUserId($destination);

        if ($setting === null) {
            Log::warning('LINE webhook: 未知の destination を受信しました。', ['destination' => $destination]);

            return;
        }

        // プラン対象外のサロン宛は受理だけして何もしない（LINE に再送させないため 200 のまま）
        if (! $this->entitlements->can($setting->salon_id, Feature::Line)) {
            Log::info('LINE webhook: プラン対象外のため無視しました。', ['salon_id' => $setting->salon_id]);

            return;
        }

        if (! $this->verifySignature($rawBody, $signature, $setting->channel_secret)) {
            Log::warning('LINE webhook: 署名検証に失敗しました。', ['salon_id' => $setting->salon_id]);

            return;
        }

        // 署名検証成功時のみ更新（channel_secret が正しいことの確認手段）
        $this->lineSettingRepository->touchLastWebhookAt($setting);

        $events = $payload['events'] ?? [];

        foreach (is_array($events) ? $events : [] as $event) {
            if (! is_array($event) || ! in_array($event['type'] ?? null, self::HANDLED_EVENT_TYPES, true)) {
                continue;
            }

            ProcessLineEventJob::dispatch($setting->id, $event);
        }
    }

    /**
     * x-line-signature を raw body に対する HMAC-SHA256（Base64）で検証する。
     */
    private function verifySignature(string $rawBody, ?string $signature, string $channelSecret): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $channelSecret, true));

        return hash_equals($expected, $signature);
    }
}
