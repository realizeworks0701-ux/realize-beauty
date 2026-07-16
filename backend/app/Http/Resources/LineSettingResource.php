<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LINE連携設定。未登録（resource が null）でも configured=false + webhook_url を返す。
 */
class LineSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $webhookUrl = rtrim(config('app.url'), '/').'/api/line/webhook';

        if ($this->resource === null) {
            return [
                'configured' => false,
                'channel_id' => null,
                'channel_secret' => null,
                'channel_access_token' => null,
                'bot_user_id' => null,
                'bot_basic_id' => null,
                'bot_display_name' => null,
                'is_active' => false,
                'connected_at' => null,
                'last_webhook_at' => null,
                'webhook_url' => $webhookUrl,
            ];
        }

        return [
            'configured' => true,
            'channel_id' => $this->channel_id,
            'channel_secret' => $this->mask($this->channel_secret),
            'channel_access_token' => $this->mask($this->channel_access_token),
            'bot_user_id' => $this->bot_user_id,
            'bot_basic_id' => $this->bot_basic_id,
            'bot_display_name' => $this->bot_display_name,
            'is_active' => $this->is_active,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'last_webhook_at' => $this->last_webhook_at?->toIso8601String(),
            'webhook_url' => $webhookUrl,
        ];
    }

    /**
     * 平文は返さず末尾4文字のみ表示する。
     */
    private function mask(string $value): string
    {
        return '****'.substr($value, -4);
    }
}
