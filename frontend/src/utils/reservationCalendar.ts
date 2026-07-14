import type { BusinessHour, Reservation } from '@/types'

/** 分単位の表示レンジ（0 = 表示日の 00:00） */
export interface DisplayRange {
  startMin: number
  endMin: number
}

export const SLOT_MINUTES = 30
export const DEFAULT_OPEN_MIN = 9 * 60
export const DEFAULT_CLOSE_MIN = 19 * 60

const DAY_MINUTES = 24 * 60
const PADDING_MINUTES = 60

export function hhmmToMinutes(hhmm: string): number {
  const [hours = 0, minutes = 0] = hhmm.split(':').map(Number)
  return hours * 60 + minutes
}

export function minutesToHHMM(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
}

export function startOfDay(date: Date): Date {
  const d = new Date(date)
  d.setHours(0, 0, 0, 0)
  return d
}

/** ISO日時 → 表示日 00:00 からの経過分（表示日外は 0〜1440 にクランプ） */
export function minutesIntoDay(iso: string, day: Date): number {
  const diff = (new Date(iso).getTime() - startOfDay(day).getTime()) / 60000
  return Math.min(Math.max(diff, 0), DAY_MINUTES)
}

const floorToHour = (minutes: number): number => Math.floor(minutes / 60) * 60
const ceilToHour = (minutes: number): number => Math.ceil(minutes / 60) * 60

/**
 * 表示レンジを導出する（docs/ui/reservation.md）。
 * 営業時間 ±1h を時間単位に丸め、レンジ外の予約があれば包含するよう拡張する。
 * 定休日はデフォルト（09:00〜19:00 ±1h）を基準に同様の拡張を適用する。
 */
export function computeDisplayRange(
  businessHour: BusinessHour | undefined,
  reservations: Reservation[],
  day: Date,
): DisplayRange {
  const openMin =
    businessHour && !businessHour.is_closed
      ? hhmmToMinutes(businessHour.open_time)
      : DEFAULT_OPEN_MIN
  const closeMin =
    businessHour && !businessHour.is_closed
      ? hhmmToMinutes(businessHour.close_time)
      : DEFAULT_CLOSE_MIN

  let startMin = Math.max(floorToHour(openMin - PADDING_MINUTES), 0)
  let endMin = Math.min(ceilToHour(closeMin + PADDING_MINUTES), DAY_MINUTES)

  for (const reservation of reservations) {
    startMin = Math.min(startMin, floorToHour(minutesIntoDay(reservation.start_at, day)))
    endMin = Math.max(endMin, ceilToHour(minutesIntoDay(reservation.end_at, day)))
  }

  return { startMin, endMin }
}

/** 同一列内で時間帯が重なるブロックのレーン割り当て結果 */
export interface LaidOutBlock {
  reservation: Reservation
  startMin: number
  endMin: number
  lane: number
  laneCount: number
}

/**
 * 同一スタッフ列の予約をレーンに割り当てる。
 * 重なり合うブロック群（クラスタ）ごとに列幅を等分して横並びに表示するための情報を返す。
 */
export function layoutReservations(reservations: Reservation[], day: Date): LaidOutBlock[] {
  const items = reservations
    .map((reservation) => {
      const startMin = minutesIntoDay(reservation.start_at, day)
      return {
        reservation,
        startMin,
        endMin: Math.max(minutesIntoDay(reservation.end_at, day), startMin + 5),
      }
    })
    .sort((a, b) => a.startMin - b.startMin || a.endMin - b.endMin)

  const blocks: LaidOutBlock[] = []
  let cluster: { item: (typeof items)[number]; lane: number }[] = []
  let laneEnds: number[] = []
  let clusterEnd = -1

  const flushCluster = (): void => {
    for (const { item, lane } of cluster) {
      blocks.push({
        reservation: item.reservation,
        startMin: item.startMin,
        endMin: item.endMin,
        lane,
        laneCount: laneEnds.length,
      })
    }
    cluster = []
    laneEnds = []
    clusterEnd = -1
  }

  for (const item of items) {
    if (cluster.length > 0 && item.startMin >= clusterEnd) {
      flushCluster()
    }
    let lane = laneEnds.findIndex((end) => end <= item.startMin)
    if (lane === -1) {
      lane = laneEnds.length
      laneEnds.push(item.endMin)
    } else {
      laneEnds[lane] = item.endMin
    }
    cluster.push({ item, lane })
    clusterEnd = Math.max(clusterEnd, item.endMin)
  }
  flushCluster()

  return blocks
}
