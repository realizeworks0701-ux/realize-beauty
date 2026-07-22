/** 接続単位のモード。未設定は null（接続開始前にモード設定が必要） */
export type GoogleCalendarMode = 'per_staff' | 'shared'

/** active = 同期稼働中 / needs_reconnect = refresh_token 失効・取り消しで再接続が必要 */
export type GoogleCalendarConnectionStatus = 'active' | 'needs_reconnect'

/** 接続の所有スタッフ（UserSummary）。shared モードの接続では null */
export interface GoogleCalendarConnectionUser {
  id: number
  name: string
}

/**
 * Google カレンダー接続（1接続 = 1 Google アカウントの1カレンダー）。
 * トークン・sync_token 等の認証情報と同期内部状態はレスポンスに含まれない。
 */
export interface GoogleCalendarConnection {
  id: number
  user: GoogleCalendarConnectionUser | null
  /** 接続した Google アカウントのメールアドレス（表示用） */
  google_account_email: string
  /** 同期対象カレンダーのID。リテラル `primary` はメインカレンダーのエイリアス */
  calendar_id: string
  status: GoogleCalendarConnectionStatus
  /** 最終同期日時。一度も同期していない場合は null（＝同期待ち） */
  last_synced_at: string | null
}

/** GET /google-calendar・PUT /google-calendar/mode のレスポンス */
export interface GoogleCalendarSettings {
  mode: GoogleCalendarMode | null
  connections: GoogleCalendarConnection[]
}

/** GET /google-calendar/connections/{id}/calendars の1件（calendarList.list） */
export interface GoogleCalendarListEntry {
  /** カレンダーID（メインカレンダーはアカウントのメールアドレス。リテラル `primary` は現れない） */
  id: string
  /** カレンダー名。選択のためだけに使い、保存も保持もしない */
  summary: string
  primary: boolean
}

/** POST /google-calendar/auth-url のレスポンス */
export interface GoogleCalendarAuthUrl {
  auth_url: string
}

/**
 * 外部予定（Google カレンダー上の RB 由来でない予定）の busy ブロック。
 * タイトル等の内容は保持せず、時刻のみ返る（プライバシー配慮）。
 * user_id が null は shared モード＝サロン全体を塞ぐ外部予定を表す。
 */
export interface BusyBlock {
  id: number
  start_at: string
  end_at: string
  user_id: number | null
}

/** PUT /google-calendar/mode リクエスト */
export interface GoogleCalendarModeUpdateInput {
  mode: GoogleCalendarMode
}

/** PUT /google-calendar/connections/{id} リクエスト */
export interface GoogleCalendarConnectionUpdateInput {
  /** リテラル `primary`、またはカレンダー一覧が返した id */
  calendar_id: string
}
