import type { Gender, RecordStatus } from '@/types'

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
