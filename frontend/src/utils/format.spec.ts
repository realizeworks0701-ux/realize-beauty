import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  calcAge,
  formatDate,
  formatNumber,
  genderLabel,
  recordStatusLabel,
  toDateInputValue,
} from './format'

describe('formatDate', () => {
  it('date-only の文字列を YYYY/MM/DD にする', () => {
    // 正午指定でタイムゾーンによる日付ズレを避ける
    expect(formatDate('2026-07-09T12:00:00')).toBe('2026/07/09')
  })

  it('未指定・不正値は — を返す', () => {
    expect(formatDate(null)).toBe('—')
    expect(formatDate(undefined)).toBe('—')
    expect(formatDate('')).toBe('—')
    expect(formatDate('not-a-date')).toBe('—')
  })
})

describe('formatNumber', () => {
  it('3桁区切りにする', () => {
    expect(formatNumber(120000)).toBe('120,000')
    expect(formatNumber(0)).toBe('0')
  })
})

describe('genderLabel', () => {
  it('コードをラベルへ変換する', () => {
    expect(genderLabel(0)).toBe('未設定')
    expect(genderLabel(1)).toBe('男性')
    expect(genderLabel(2)).toBe('女性')
    expect(genderLabel(9)).toBe('その他')
  })

  it('null/undefined は未設定', () => {
    expect(genderLabel(null)).toBe('未設定')
    expect(genderLabel(undefined)).toBe('未設定')
  })
})

describe('recordStatusLabel', () => {
  it('ステータスを日本語にする', () => {
    expect(recordStatusLabel('completed')).toBe('完了')
    expect(recordStatusLabel('draft')).toBe('下書き')
  })
})

describe('toDateInputValue', () => {
  it('input[type=date] 用の YYYY-MM-DD にする', () => {
    expect(toDateInputValue('2026-07-09T12:00:00')).toBe('2026-07-09')
    expect(toDateInputValue(null)).toBe('')
    expect(toDateInputValue('bad')).toBe('')
  })
})

describe('calcAge', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('誕生日から年齢を計算する', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-12T00:00:00'))
    expect(calcAge('1992-04-12')).toBe(34)
    // 誕生日前は1つ下
    expect(calcAge('1992-12-31')).toBe(33)
  })

  it('null は null を返す', () => {
    expect(calcAge(null)).toBeNull()
    expect(calcAge(undefined)).toBeNull()
  })
})
