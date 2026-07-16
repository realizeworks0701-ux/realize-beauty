/**
 * GET/PUT /line-settings・POST /line-settings/verify のレスポンス。
 * channel_secret / channel_access_token は末尾4文字のマスク値で、平文は返らない。
 * 認証情報が未登録の場合は configured=false（認証情報系フィールドは null）。
 */
export interface LineSetting {
  configured: boolean
  channel_id: string | null
  channel_secret: string | null
  channel_access_token: string | null
  bot_user_id: string | null
  bot_basic_id: string | null
  bot_display_name: string | null
  is_active: boolean
  connected_at: string | null
  last_webhook_at: string | null
  webhook_url: string
}

/** PUT /line-settings リクエスト（部分更新はせず3項目すべてを平文で送る） */
export interface LineSettingUpdateInput {
  channel_id: string
  channel_secret: string
  channel_access_token: string
}

/** GET /booking-page — 公開予約ページのslugとURL */
export interface BookingPage {
  booking_slug: string
  booking_page_url: string
}
