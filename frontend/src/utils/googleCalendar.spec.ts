import { describe, expect, it } from 'vitest'
import type { GoogleCalendarConnection, GoogleCalendarListEntry } from '@/types'
import { calendarLabel, connectErrorMessage, resolveSelectedCalendarId } from './googleCalendar'

const connection = (
  overrides: Partial<GoogleCalendarConnection> = {},
): GoogleCalendarConnection => ({
  id: 1,
  user: { id: 1, name: '田中 美咲' },
  google_account_email: 'misaki@gmail.com',
  calendar_id: 'primary',
  status: 'active',
  last_synced_at: null,
  ...overrides,
})

const entries: GoogleCalendarListEntry[] = [
  { id: 'misaki@gmail.com', summary: '田中 美咲', primary: true },
  { id: 'work@group.calendar.google.com', summary: '仕事用カレンダー', primary: false },
]

describe('calendarLabel', () => {
  it('primary はメインカレンダーとして Google アカウントを添えて表示する', () => {
    expect(calendarLabel(connection())).toBe('メインカレンダー（misaki@gmail.com）')
  })

  it('primary 以外は calendar_id をそのまま表示する（カレンダー名は保持しないため）', () => {
    expect(calendarLabel(connection({ calendar_id: 'work@group.calendar.google.com' }))).toBe(
      'work@group.calendar.google.com',
    )
  })
})

describe('resolveSelectedCalendarId', () => {
  it('primary は一覧の primary エントリの実IDへ解決する', () => {
    expect(resolveSelectedCalendarId('primary', entries)).toBe('misaki@gmail.com')
  })

  it('primary 以外はそのまま返す', () => {
    expect(resolveSelectedCalendarId('work@group.calendar.google.com', entries)).toBe(
      'work@group.calendar.google.com',
    )
  })

  it('primary エントリが無い一覧では null を返す', () => {
    expect(resolveSelectedCalendarId('primary', [entries[1] as GoogleCalendarListEntry])).toBeNull()
  })
})

describe('connectErrorMessage', () => {
  it('既知のコードは専用の文言を返す', () => {
    expect(connectErrorMessage('access_denied')).toContain('同意画面')
    expect(connectErrorMessage('invalid_state')).toContain('有効期限')
    expect(connectErrorMessage('exchange_failed')).toContain('認証に失敗')
    expect(connectErrorMessage('connect_failed')).toContain('保存に失敗')
  })

  it('未知のコードは値を出さず汎用文言へフォールバックする', () => {
    const message = connectErrorMessage('<script>alert(1)</script>')
    expect(message).toBe('Googleとの接続に失敗しました。もう一度お試しください')
    expect(message).not.toContain('script')
  })
})
