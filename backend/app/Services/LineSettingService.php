<?php

namespace App\Services;

use App\Models\LineSetting;
use App\Repositories\CustomerRepository;
use App\Repositories\LineSettingRepository;
use App\Services\Line\LineApiException;
use App\Services\Line\LineClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LineSettingService
{
    public function __construct(
        private readonly LineSettingRepository $lineSettingRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly LineClient $lineClient,
    ) {}

    public function get(int $salonId): ?LineSetting
    {
        return $this->lineSettingRepository->findBySalon($salonId);
    }

    /**
     * 1サロン1設定の upsert。secret / token を変更した場合は
     * is_active を false に戻し、再度の接続確認を要求する。
     */
    public function upsert(int $salonId, array $data): LineSetting
    {
        $setting = $this->lineSettingRepository->findBySalon($salonId);

        if ($setting === null) {
            return $this->lineSettingRepository->create($salonId, $data);
        }

        $credentialsChanged = $data['channel_secret'] !== $setting->channel_secret
            || $data['channel_access_token'] !== $setting->channel_access_token;

        if ($credentialsChanged) {
            $data['is_active'] = false;
        }

        return $this->lineSettingRepository->update($setting, $data);
    }

    /**
     * LINE の bot 情報取得APIで接続を確認し、成功時に連携を有効化する。
     * 検証できるのは channel_access_token のみ（channel_secret は webhook 受信で確認する）。
     */
    public function verify(int $salonId): LineSetting
    {
        $setting = $this->lineSettingRepository->findBySalonOrFail($salonId);

        try {
            $botInfo = $this->lineClient->getBotInfo($setting->channel_access_token);
        } catch (LineApiException) {
            throw ValidationException::withMessages([
                'channel_access_token' => ['LINEとの接続確認に失敗しました。チャネルアクセストークンを確認してください。'],
            ]);
        }

        return $this->lineSettingRepository->update($setting, [
            'bot_user_id' => $botInfo['userId'] ?? null,
            'bot_basic_id' => $botInfo['basicId'] ?? null,
            'bot_display_name' => $botInfo['displayName'] ?? null,
            'is_active' => true,
            'connected_at' => now(),
        ]);
    }

    /**
     * 連携解除（物理削除）。LINE の userId はチャネルのプロバイダー単位スコープのため、
     * 当該サロン顧客のLINE系カラムも一括クリアする（ADR-024）。
     */
    public function disconnect(int $salonId): void
    {
        $setting = $this->lineSettingRepository->findBySalonOrFail($salonId);

        DB::transaction(function () use ($salonId, $setting) {
            $this->lineSettingRepository->delete($setting);
            $this->customerRepository->clearLineColumnsBySalon($salonId);
        });
    }
}
