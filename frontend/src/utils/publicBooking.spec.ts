import { describe, expect, it } from 'vitest'
import type { BusinessHour } from '@/types'
import { toIsoWithOffset } from './format'
import {
  bookingSelectableRange,
  buildCancelUrl,
  calcEndAtIso,
  formatDateTimeRange,
  isWithinBookingWindow,
  listSlotStartMinutes,
  slotToIso,
} from './publicBooking'

const businessHour = (overrides: Partial<BusinessHour> = {}): BusinessHour => ({
  day_of_week: 1,
  is_closed: false,
  open_time: '09:00',
  close_time: '19:00',
  ...overrides,
})

describe('bookingSelectableRange', () => {
  it('今日 00:00 〜 60日先 00:00 を返す', () => {
    const now = new Date(2026, 6, 14, 12, 34, 56)
    const { minDate, maxDate } = bookingSelectableRange(now)
    expect(minDate).toEqual(new Date(2026, 6, 14, 0, 0, 0, 0))
    expect(maxDate).toEqual(new Date(2026, 8, 12, 0, 0, 0, 0))
  })
})

describe('listSlotStartMinutes', () => {
  it('open 起点の30分刻みで start + duration <= close の開始時刻を列挙する', () => {
    const list = listSlotStartMinutes(businessHour(), 60)
    expect(list[0]).toBe(9 * 60)
    expect(list.at(-1)).toBe(18 * 60)
    expect(list).toHaveLength(19)
  })

  it('半端な開店時刻でも open_time を起点にする（09:15 → 09:15, 09:45, …）', () => {
    const list = listSlotStartMinutes(businessHour({ open_time: '09:15' }), 60)
    expect(list.slice(0, 2)).toEqual([9 * 60 + 15, 9 * 60 + 45])
    expect(list.at(-1)).toBe(17 * 60 + 45)
  })

  it('所要時間が収まらない枠は含めない（close ちょうどは可）', () => {
    const list = listSlotStartMinutes(businessHour({ close_time: '10:00' }), 60)
    expect(list).toEqual([9 * 60])
  })

  it('休業日は空配列を返す', () => {
    expect(listSlotStartMinutes(businessHour({ is_closed: true }), 60)).toEqual([])
  })

  it('営業時間より所要時間が長い場合は空配列を返す', () => {
    const list = listSlotStartMinutes(businessHour({ close_time: '09:30' }), 60)
    expect(list).toEqual([])
  })
})

describe('slotToIso', () => {
  it('日付と 0:00 からの分をオフセット付き ISO 8601 に変換する', () => {
    const iso = slotToIso(new Date(2026, 6, 20), 10 * 60 + 30)
    expect(iso).toBe(toIsoWithOffset(new Date(2026, 6, 20, 10, 30)))
  })
})

describe('isWithinBookingWindow', () => {
  const now = new Date(2026, 6, 14, 12, 0)

  it('現在時刻+30分ちょうどから予約可能', () => {
    expect(isWithinBookingWindow(new Date(2026, 6, 14, 12, 30), now)).toBe(true)
    expect(isWithinBookingWindow(new Date(2026, 6, 14, 12, 29), now)).toBe(false)
  })

  it('本日+60日後の終日まで予約可能（61日後は不可）', () => {
    expect(isWithinBookingWindow(new Date(2026, 8, 12, 23, 30), now)).toBe(true)
    expect(isWithinBookingWindow(new Date(2026, 8, 13, 0, 0), now)).toBe(false)
  })
})

describe('calcEndAtIso', () => {
  it('開始日時 + 所要分の終了日時を返す', () => {
    const start = toIsoWithOffset(new Date(2026, 6, 20, 10, 0))
    expect(calcEndAtIso(start, 90)).toBe(toIsoWithOffset(new Date(2026, 6, 20, 11, 30)))
  })
})

describe('formatDateTimeRange', () => {
  it('"YYYY/MM/DD（曜）HH:MM〜HH:MM" 形式で整形する', () => {
    const start = toIsoWithOffset(new Date(2026, 6, 20, 10, 0))
    const end = toIsoWithOffset(new Date(2026, 6, 20, 11, 0))
    expect(formatDateTimeRange(start, end)).toBe('2026/07/20（月）10:00〜11:00')
  })

  it('不正な日時は "—" を返す', () => {
    expect(formatDateTimeRange('invalid', 'invalid')).toBe('—')
  })
})

describe('buildCancelUrl', () => {
  it('オリジンとトークンからキャンセルURLを組み立てる', () => {
    expect(buildCancelUrl('https://example.com', 'abcDEF123')).toBe(
      'https://example.com/booking/cancel/abcDEF123',
    )
  })
})
