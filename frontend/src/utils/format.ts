import type { Gender, RecordStatus, ReservationStatus } from '@/types'

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return `${date.getFullYear()}/${String(date.getMonth() + 1).padStart(2, '0')}/${String(date.getDate()).padStart(2, '0')}`
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return `${formatDate(value)} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

export function formatNumber(value: number): string {
  return value.toLocaleString('ja-JP')
}

/** 前期比の増減率（%・小数1桁）。previous が 0 のときは null を返し表示しない */
export function calcDeltaPercent(current: number, previous: number): number | null {
  if (previous === 0) return null
  return Math.round(((current - previous) / previous) * 1000) / 10
}

const GENDER_LABELS: Record<Gender, string> = {
  0: '未設定',
  1: '男性',
  2: '女性',
  9: 'その他',
}

export function genderLabel(gender: Gender | null | undefined): string {
  return gender == null ? '未設定' : (GENDER_LABELS[gender] ?? '未設定')
}

export const RECORD_STATUS_LABELS: Record<RecordStatus, string> = {
  draft: '下書き',
  completed: '完了',
}

export function recordStatusLabel(status: RecordStatus): string {
  return RECORD_STATUS_LABELS[status]
}

export const RESERVATION_STATUS_LABELS: Record<ReservationStatus, string> = {
  reserved: '予約済み',
  visited: '来店済み',
  cancelled: 'キャンセル',
  no_show: '無断キャンセル',
}

export function reservationStatusLabel(status: ReservationStatus): string {
  return RESERVATION_STATUS_LABELS[status]
}

export function formatTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

const WEEKDAY_LABELS = ['日', '月', '火', '水', '木', '金', '土'] as const

export function weekdayLabel(dayOfWeek: number): string {
  return WEEKDAY_LABELS[dayOfWeek] ?? ''
}

/** Date → "2026年7月14日（火）" */
export function formatDateJa(date: Date): string {
  return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日（${weekdayLabel(date.getDay())}）`
}

/** Date → API クエリ用の "YYYY-MM-DD"（ローカル日付） */
export function toDateString(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

/** ローカルタイムゾーン付き ISO 8601 文字列に変換する */
export function toIsoWithOffset(date: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0')
  const offset = -date.getTimezoneOffset()
  const sign = offset >= 0 ? '+' : '-'
  const abs = Math.abs(offset)
  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}:00` +
    `${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`
  )
}

/** "2026-07-08T14:00:00+09:00" → input[type=date] 用 "2026-07-08" */
export function toDateInputValue(value: string | null | undefined): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

export function calcAge(birthday: string | null | undefined): number | null {
  if (!birthday) return null
  const birth = new Date(birthday)
  if (Number.isNaN(birth.getTime())) return null
  const today = new Date()
  let age = today.getFullYear() - birth.getFullYear()
  const beforeBirthday =
    today.getMonth() < birth.getMonth() ||
    (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())
  if (beforeBirthday) age -= 1
  return age
}
