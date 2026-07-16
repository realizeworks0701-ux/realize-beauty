import type { BusinessHour } from '@/types'
import { formatDate, formatTime, toIsoWithOffset, weekdayLabel } from './format'
import { hhmmToMinutes } from './reservationCalendar'

/** 公開予約の枠刻み（分） */
export const PUBLIC_SLOT_MINUTES = 30
/** 直近予約の猶予（現在時刻+30分以降のみ予約可） */
export const MIN_LEAD_MINUTES = 30
/** 予約可能な最大日数（本日+60日後の終日まで） */
export const MAX_BOOKING_DAYS = 60

/** DatePicker の選択可能範囲（今日〜60日先。いずれも 00:00） */
export function bookingSelectableRange(now: Date = new Date()): { minDate: Date; maxDate: Date } {
  const minDate = new Date(now)
  minDate.setHours(0, 0, 0, 0)
  const maxDate = new Date(minDate)
  maxDate.setDate(maxDate.getDate() + MAX_BOOKING_DAYS)
  return { minDate, maxDate }
}

/**
 * open_time 起点の30分グリッドで「start + duration <= close」を満たす
 * 開始時刻（0:00 からの分）を列挙する。休業日は空配列。
 */
export function listSlotStartMinutes(
  businessHour: BusinessHour,
  durationMinutes: number,
): number[] {
  if (businessHour.is_closed) return []
  const open = hhmmToMinutes(businessHour.open_time)
  const close = hhmmToMinutes(businessHour.close_time)
  const list: number[] = []
  for (let min = open; min + durationMinutes <= close; min += PUBLIC_SLOT_MINUTES) {
    list.push(min)
  }
  return list
}

/** 日付（ローカル）と 0:00 からの分から ISO 8601（オフセット付き）を生成する */
export function slotToIso(date: Date, minutes: number): string {
  const slot = new Date(date)
  slot.setHours(Math.floor(minutes / 60), minutes % 60, 0, 0)
  return toIsoWithOffset(slot)
}

/** 予約可能範囲内か（現在時刻+30分以降、かつ本日+60日後の終日まで。日付境界で判定） */
export function isWithinBookingWindow(slotStart: Date, now: Date = new Date()): boolean {
  const earliest = now.getTime() + MIN_LEAD_MINUTES * 60000
  if (slotStart.getTime() < earliest) return false
  const limit = new Date(bookingSelectableRange(now).maxDate)
  limit.setDate(limit.getDate() + 1)
  return slotStart.getTime() < limit.getTime()
}

/** 開始日時 + 所要分から終了日時（ISO 8601 オフセット付き）を導出する */
export function calcEndAtIso(startAtIso: string, durationMinutes: number): string {
  return toIsoWithOffset(new Date(new Date(startAtIso).getTime() + durationMinutes * 60000))
}

/** "2026/07/20（月）10:00〜11:00" 形式（確認・完了・キャンセルページ用） */
export function formatDateTimeRange(startAtIso: string, endAtIso: string): string {
  const start = new Date(startAtIso)
  if (Number.isNaN(start.getTime())) return '—'
  return `${formatDate(startAtIso)}（${weekdayLabel(start.getDay())}）${formatTime(startAtIso)}〜${formatTime(endAtIso)}`
}

/** キャンセルページの完全URL */
export function buildCancelUrl(origin: string, bookingToken: string): string {
  return `${origin}/booking/cancel/${bookingToken}`
}

/** Web予約ページの完全URL（SPA と API は別オリジンのため、フロントエンドの origin から組み立てる） */
export function buildBookingPageUrl(origin: string, bookingSlug: string): string {
  return `${origin}/booking/${bookingSlug}`
}
