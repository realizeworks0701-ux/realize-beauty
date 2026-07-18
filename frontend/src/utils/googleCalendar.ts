import type { GoogleCalendarConnection, GoogleCalendarListEntry, GoogleCalendarMode } from '@/types'

/** メインカレンダーを指すエイリアス（calendarList.list はこの id を返さない） */
export const PRIMARY_CALENDAR_ID = 'primary'

export const MODE_LABELS: Record<GoogleCalendarMode, string> = {
  per_staff: 'スタッフ別',
  shared: 'サロン共有',
}

export const MODE_DESCRIPTIONS: Record<GoogleCalendarMode, string> = {
  per_staff:
    'スタッフがそれぞれ自分のGoogleアカウントを接続します。各スタッフの予約はそのスタッフのカレンダーへ書き込まれ、カレンダー上のRB以外の予定はそのスタッフの空き枠を塞ぎます',
  shared:
    'Googleアカウントを1つだけ接続します。全スタッフの予約が1つのカレンダーへ書き込まれ（予定の題名に担当スタッフ名を含む）、カレンダー上のRB以外の予定はサロン全体の空き枠を塞ぎます。店休・全体研修・イベントの登録に向いています',
}

/**
 * 接続カードの対象カレンダー表示。
 * カレンダー名は保持しないため、`primary` 以外は calendar_id をそのまま出す（D10）。
 */
export function calendarLabel(connection: GoogleCalendarConnection): string {
  return connection.calendar_id === PRIMARY_CALENDAR_ID
    ? `メインカレンダー（${connection.google_account_email}）`
    : connection.calendar_id
}

/**
 * 現在の calendar_id を選択UIの初期値へ解決する。
 * `primary` はエイリアスで一覧に現れないため、primary フラグの立つエントリの実IDへ読み替える
 * （文字列一致で照合すると初期値が未選択になる）。
 */
export function resolveSelectedCalendarId(
  calendarId: string,
  entries: GoogleCalendarListEntry[],
): string | null {
  if (calendarId !== PRIMARY_CALENDAR_ID) return calendarId
  return entries.find((entry) => entry.primary)?.id ?? null
}

const CONNECT_ERROR_MESSAGES: Record<string, string> = {
  invalid_state: '接続の有効期限が切れました。もう一度「Googleと接続」からやり直してください',
  access_denied:
    'Googleの同意画面で許可されなかったため接続できませんでした。カレンダーへのアクセスを許可してください',
  exchange_failed: 'Googleとの認証に失敗しました。もう一度お試しください',
  connect_failed: '接続の保存に失敗しました。もう一度お試しください',
}

const GENERIC_CONNECT_ERROR = 'Googleとの接続に失敗しました。もう一度お試しください'

/** 復帰時の `?error=` の文言。未知の値は値を画面に出さず汎用文言へフォールバックする */
export function connectErrorMessage(code: string): string {
  return CONNECT_ERROR_MESSAGES[code] ?? GENERIC_CONNECT_ERROR
}
