<?php

namespace App\Repositories;

use App\Models\LineSetting;

class LineSettingRepository
{
    public function findBySalon(int $salonId): ?LineSetting
    {
        return LineSetting::where('salon_id', $salonId)->first();
    }

    public function findBySalonOrFail(int $salonId): LineSetting
    {
        return LineSetting::where('salon_id', $salonId)->firstOrFail();
    }

    public function findByBotUserId(string $botUserId): ?LineSetting
    {
        return LineSetting::where('bot_user_id', $botUserId)->first();
    }

    public function find(int $id): ?LineSetting
    {
        return LineSetting::find($id);
    }

    public function create(int $salonId, array $data): LineSetting
    {
        return LineSetting::create(array_merge($data, [
            'salon_id' => $salonId,
        ]));
    }

    public function update(LineSetting $setting, array $data): LineSetting
    {
        $setting->update($data);

        return $setting->fresh();
    }

    public function touchLastWebhookAt(LineSetting $setting): void
    {
        $setting->update(['last_webhook_at' => now()]);
    }

    public function delete(LineSetting $setting): void
    {
        $setting->delete();
    }
}
