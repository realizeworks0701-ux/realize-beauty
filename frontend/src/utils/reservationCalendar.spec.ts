import { describe, expect, it } from 'vitest'
import type { Reservation } from '@/types'
import {
  computeDisplayRange,
  hhmmToMinutes,
  layoutReservations,
  minutesIntoDay,
  minutesToHHMM,
} from './reservationCalendar'

const day = new Date(2026, 6, 14) // 2026-07-14（火）

function reservation(overrides: Partial<Reservation> & { start_at: string; end_at: string }): Reservation {
  return {
    id: 1,
    customer: { id: 1, name: '山田 花子', kana: 'ヤマダ ハナコ', phone: null },
    menu: { id: 1, name: 'カット', price: 5500, duration_minutes: 60, is_active: true },
    user: { id: 1, name: '田中 美咲' },
    status: 'reserved',
    note: null,
    created_at: '2026-07-13T18:00:00+09:00',
    updated_at: '2026-07-13T18:00:00+09:00',
    ...overrides,
  }
}

describe('hhmmToMinutes / minutesToHHMM', () => {
  it('HH:MM と分を相互変換する', () => {
    expect(hhmmToMinutes('09:00')).toBe(540)
    expect(hhmmToMinutes('19:30')).toBe(1170)
    expect(minutesToHHMM(540)).toBe('09:00')
    expect(minutesToHHMM(1170)).toBe('19:30')
  })
})

describe('minutesIntoDay', () => {
  it('表示日 00:00 からの経過分を返す', () => {
    expect(minutesIntoDay('2026-07-14T10:30:00', day)).toBe(630)
  })

  it('表示日の範囲外は 0〜1440 にクランプする', () => {
    expect(minutesIntoDay('2026-07-13T23:00:00', day)).toBe(0)
    expect(minutesIntoDay('2026-07-15T01:00:00', day)).toBe(1440)
  })
})

describe('computeDisplayRange', () => {
  const open = { day_of_week: 2, is_closed: false, open_time: '09:00', close_time: '19:00' }

  it('営業時間 ±1h を表示する（09:00〜19:00 → 08:00〜20:00）', () => {
    expect(computeDisplayRange(open, [], day)).toEqual({ startMin: 480, endMin: 1200 })
  })

  it('半端な営業時間は時間単位に丸める', () => {
    const hour = { ...open, open_time: '09:30', close_time: '19:30' }
    expect(computeDisplayRange(hour, [], day)).toEqual({ startMin: 480, endMin: 1260 })
  })

  it('レンジ外の予約を包含するよう拡張する（21:30〜22:30 → 〜23:00）', () => {
    const late = reservation({
      start_at: '2026-07-14T21:30:00',
      end_at: '2026-07-14T22:30:00',
    })
    expect(computeDisplayRange(open, [late], day)).toEqual({ startMin: 480, endMin: 1380 })
  })

  it('定休日はデフォルト 09:00〜19:00 ±1h を基準にする', () => {
    const closed = { day_of_week: 0, is_closed: true, open_time: '10:00', close_time: '18:00' }
    expect(computeDisplayRange(closed, [], day)).toEqual({ startMin: 480, endMin: 1200 })
  })

  it('営業時間が未取得でもデフォルトを返す', () => {
    expect(computeDisplayRange(undefined, [], day)).toEqual({ startMin: 480, endMin: 1200 })
  })
})

describe('layoutReservations', () => {
  it('重ならない予約はレーン1で全幅にする', () => {
    const blocks = layoutReservations(
      [
        reservation({ id: 1, start_at: '2026-07-14T10:00:00', end_at: '2026-07-14T11:00:00' }),
        reservation({ id: 2, start_at: '2026-07-14T11:00:00', end_at: '2026-07-14T12:00:00' }),
      ],
      day,
    )
    expect(blocks).toHaveLength(2)
    expect(blocks.every((b) => b.laneCount === 1 && b.lane === 0)).toBe(true)
  })

  it('時間帯が重なる予約は列幅を等分する', () => {
    const blocks = layoutReservations(
      [
        reservation({ id: 1, start_at: '2026-07-14T10:00:00', end_at: '2026-07-14T11:00:00' }),
        reservation({ id: 2, start_at: '2026-07-14T10:30:00', end_at: '2026-07-14T11:30:00' }),
        reservation({ id: 3, start_at: '2026-07-14T13:00:00', end_at: '2026-07-14T14:00:00' }),
      ],
      day,
    )
    const byId = new Map(blocks.map((b) => [b.reservation.id, b]))
    expect(byId.get(1)?.laneCount).toBe(2)
    expect(byId.get(2)?.laneCount).toBe(2)
    expect(byId.get(1)?.lane).not.toBe(byId.get(2)?.lane)
    expect(byId.get(3)?.laneCount).toBe(1)
  })
})
