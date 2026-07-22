import type { BusinessHour, BusyBlock, Reservation } from '@/types'

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

const clamp = (value: number, min: number, max: number): number =>
  Math.min(Math.max(value, min), max)

interface LaneInput<T> {
  item: T
  startMin: number
  endMin: number
}

type LaidOut<T> = LaneInput<T> & { lane: number; laneCount: number }

/**
 * 時間帯が重なる区間をレーンに割り当てる（予約・外部予定で共通）。
 * 重なり合う区間群（クラスタ）ごとに列幅を等分して横並びにするための lane / laneCount を返す。
 */
function assignLanes<T>(inputs: LaneInput<T>[]): LaidOut<T>[] {
  const items = [...inputs].sort((a, b) => a.startMin - b.startMin || a.endMin - b.endMin)

  const result: LaidOut<T>[] = []
  let cluster: { item: LaneInput<T>; lane: number }[] = []
  let laneEnds: number[] = []
  let clusterEnd = -1

  const flushCluster = (): void => {
    for (const { item, lane } of cluster) {
      result.push({ ...item, lane, laneCount: laneEnds.length })
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

  return result
}

/** 同一列内で時間帯が重なるブロックのレーン割り当て結果 */
export interface LaidOutBlock {
  reservation: Reservation
  startMin: number
  endMin: number
  lane: number
  laneCount: number
}

/** 予約ブロックの表示日 00:00 起点の分区間（下限5分で高さを確保） */
function reservationBounds(
  reservation: Reservation,
  day: Date,
): { startMin: number; endMin: number } {
  const startMin = minutesIntoDay(reservation.start_at, day)
  return { startMin, endMin: Math.max(minutesIntoDay(reservation.end_at, day), startMin + 5) }
}

/** 外部予定を表示レンジ内にクランプした分区間（潰れる予定は呼び出し側で除外する） */
function busyBounds(
  block: BusyBlock,
  day: Date,
  range: DisplayRange,
): { startMin: number; endMin: number } {
  const rawStart = minutesIntoDay(block.start_at, day)
  const rawEnd = Math.max(minutesIntoDay(block.end_at, day), rawStart + 5)
  return {
    startMin: clamp(rawStart, range.startMin, range.endMin),
    endMin: clamp(rawEnd, range.startMin, range.endMin),
  }
}

/**
 * 同一スタッフ列の予約をレーンに割り当てる。
 * 重なり合うブロック群（クラスタ）ごとに列幅を等分して横並びに表示するための情報を返す。
 */
export function layoutReservations(reservations: Reservation[], day: Date): LaidOutBlock[] {
  const inputs = reservations.map((reservation) => ({
    item: reservation,
    ...reservationBounds(reservation, day),
  }))
  return assignLanes(inputs).map((laid) => ({
    reservation: laid.item,
    startMin: laid.startMin,
    endMin: laid.endMin,
    lane: laid.lane,
    laneCount: laid.laneCount,
  }))
}

/** 外部予定（busy ブロック）のレーン割り当て結果 */
export interface LaidOutBusyBlock {
  block: BusyBlock
  startMin: number
  endMin: number
  lane: number
  laneCount: number
}

/**
 * 指定スタッフ列に効く外部予定を返す。
 * per_staff の外部予定は担当スタッフ（user_id 一致）にのみ、
 * shared の外部予定（user_id=null）は全スタッフ列に効く（サロン全体を塞ぐ意味論）。
 */
export function busyBlocksForStaff(blocks: BusyBlock[], staffId: number): BusyBlock[] {
  return blocks.filter((block) => block.user_id === null || block.user_id === staffId)
}

/**
 * 外部予定を表示レンジ内にクランプしてレーン割り当てする（docs/ui/reservation.md 外部予定ブロック）。
 * 終日予定はレンジ全体を覆うブロックにし、レンジ外の予定は表示しない（レンジは予約のみで拡張する）。
 */
export function layoutBusyBlocks(
  blocks: BusyBlock[],
  day: Date,
  range: DisplayRange,
): LaidOutBusyBlock[] {
  const inputs = blocks
    .map((block) => ({ item: block, ...busyBounds(block, day, range) }))
    .filter((input) => input.endMin > input.startMin)
  return assignLanes(inputs).map((laid) => ({
    block: laid.item,
    startMin: laid.startMin,
    endMin: laid.endMin,
    lane: laid.lane,
    laneCount: laid.laneCount,
  }))
}

/** 同一スタッフ列の予約・外部予定を種別をまたいでレーン割り当てした結果 */
export interface StaffColumnLayout {
  reservations: LaidOutBlock[]
  busyBlocks: LaidOutBusyBlock[]
}

type ColumnItem =
  | { kind: 'reservation'; reservation: Reservation }
  | { kind: 'busy'; block: BusyBlock }

/**
 * 同一スタッフ列の予約と外部予定を、種別をまたいで1つの重なりクラスタとしてレーン割り当てする
 * （docs/ui/reservation.md）。予約と外部予定が重なる場合も列幅を等分して横並びに表示するため、
 * 両者を同じ assignLanes に投入して lane / laneCount を共有する。
 */
export function layoutStaffColumn(
  reservations: Reservation[],
  busyBlocks: BusyBlock[],
  day: Date,
  range: DisplayRange,
): StaffColumnLayout {
  const reservationInputs: LaneInput<ColumnItem>[] = reservations.map((reservation) => ({
    item: { kind: 'reservation' as const, reservation },
    ...reservationBounds(reservation, day),
  }))
  const busyInputs: LaneInput<ColumnItem>[] = busyBlocks
    .map((block) => ({ item: { kind: 'busy' as const, block }, ...busyBounds(block, day, range) }))
    .filter((input) => input.endMin > input.startMin)

  const result: StaffColumnLayout = { reservations: [], busyBlocks: [] }
  for (const laid of assignLanes([...reservationInputs, ...busyInputs])) {
    if (laid.item.kind === 'reservation') {
      result.reservations.push({
        reservation: laid.item.reservation,
        startMin: laid.startMin,
        endMin: laid.endMin,
        lane: laid.lane,
        laneCount: laid.laneCount,
      })
    } else {
      result.busyBlocks.push({
        block: laid.item.block,
        startMin: laid.startMin,
        endMin: laid.endMin,
        lane: laid.lane,
        laneCount: laid.laneCount,
      })
    }
  }
  return result
}
