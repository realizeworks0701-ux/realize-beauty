<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import EmptyState from '@/components/common/EmptyState.vue'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import ReservationFormDialog from '@/components/reservation/ReservationFormDialog.vue'
import { businessHourService } from '@/services/businessHourService'
import { googleCalendarService } from '@/services/googleCalendarService'
import { menuService } from '@/services/menuService'
import { reservationService } from '@/services/reservationService'
import { userService } from '@/services/userService'
import { extractErrorMessage } from '@/utils/apiError'
import { formatDateJa, formatTime, toDateString } from '@/utils/format'
import {
  SLOT_MINUTES,
  busyBlocksForStaff,
  computeDisplayRange,
  hhmmToMinutes,
  layoutBusyBlocks,
  layoutReservations,
  minutesToHHMM,
} from '@/utils/reservationCalendar'
import type { BusinessHour, BusyBlock, Menu, Reservation, StaffUser } from '@/types'

const SLOT_PX = 44

const toast = useToast()

const selectedDate = ref<Date>(new Date())
const staff = ref<StaffUser[]>([])
const businessHours = ref<BusinessHour[]>([])
const menus = ref<Menu[]>([])
const reservations = ref<Reservation[]>([])
const busyBlocks = ref<BusyBlock[]>([])

const initialized = ref(false)
const loading = ref(false)
const loadError = ref(false)

const dialogVisible = ref(false)
const editingReservation = ref<Reservation | null>(null)
const presetUserId = ref<number | null>(null)
const presetStartAt = ref<Date | null>(null)

let fetchSeq = 0

/**
 * 外部予定を予約一覧と同じ from/to で取得する。取得失敗は予約業務を止めないため隔離し、
 * 外部予定を非表示にして Toast 通知するのみとする（docs/ui/reservation.md エラー時挙動）。
 * 未連携サロンでは空配列が返るため何も描画されない。
 */
async function loadBusyBlocks(dateStr: string, seq: number): Promise<void> {
  try {
    const blocks = await googleCalendarService.listBusyBlocks(dateStr, dateStr)
    if (seq !== fetchSeq) return
    busyBlocks.value = blocks
  } catch (error) {
    if (seq !== fetchSeq) return
    busyBlocks.value = []
    toast.add({
      severity: 'warn',
      summary: extractErrorMessage(error, '外部予定の取得に失敗しました'),
      life: 3000,
    })
  }
}

async function fetchAll(): Promise<void> {
  const seq = ++fetchSeq
  loading.value = true
  loadError.value = false
  const dateStr = toDateString(selectedDate.value)
  try {
    const [users, hours, menuList, reservationList] = await Promise.all([
      userService.list(),
      businessHourService.list(),
      menuService.list({ is_active: true }),
      reservationService.list({ from: dateStr, to: dateStr }),
      loadBusyBlocks(dateStr, seq),
    ])
    if (seq !== fetchSeq) return
    staff.value = users
    businessHours.value = hours
    menus.value = menuList
    reservations.value = reservationList
  } catch (error) {
    if (seq !== fetchSeq) return
    loadError.value = true
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '予約の取得に失敗しました'),
      life: 3000,
    })
  } finally {
    if (seq === fetchSeq) {
      loading.value = false
      initialized.value = true
    }
  }
}

async function fetchReservations(): Promise<void> {
  const seq = ++fetchSeq
  loading.value = true
  loadError.value = false
  const dateStr = toDateString(selectedDate.value)
  try {
    const [list] = await Promise.all([
      reservationService.list({ from: dateStr, to: dateStr }),
      loadBusyBlocks(dateStr, seq),
    ])
    if (seq !== fetchSeq) return
    reservations.value = list
  } catch (error) {
    if (seq !== fetchSeq) return
    loadError.value = true
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '予約の取得に失敗しました'),
      life: 3000,
    })
  } finally {
    if (seq === fetchSeq) loading.value = false
  }
}

onMounted(() => {
  void fetchAll()
})

watch(selectedDate, () => {
  // 初期ロード完了前の日付変更は fetchAll をやり直す
  // （fetchSeq が進んで初回 fetchAll の結果が破棄され、スケルトンのまま固まるため）
  void (initialized.value ? fetchReservations() : fetchAll())
})

// ---- 日付移動 ----

function moveDay(delta: number): void {
  const next = new Date(selectedDate.value)
  next.setDate(next.getDate() + delta)
  selectedDate.value = next
}

function goToday(): void {
  selectedDate.value = new Date()
}

function onDatePicked(value: Date | Date[] | (Date | null)[] | null | undefined): void {
  if (value instanceof Date) selectedDate.value = value
}

// ---- 表示レンジ・グリッド ----

const businessHourOfDay = computed(() =>
  businessHours.value.find((hour) => hour.day_of_week === selectedDate.value.getDay()),
)

const displayRange = computed(() =>
  computeDisplayRange(businessHourOfDay.value, reservations.value, selectedDate.value),
)

const slots = computed(() => {
  const list: { min: number; isHour: boolean }[] = []
  for (
    let min = displayRange.value.startMin;
    min < displayRange.value.endMin;
    min += SLOT_MINUTES
  ) {
    list.push({ min, isHour: min % 60 === 0 })
  }
  return list
})

function isOutsideBusinessHours(slotMin: number): boolean {
  const hour = businessHourOfDay.value
  if (!hour || hour.is_closed) return true
  return slotMin < hhmmToMinutes(hour.open_time) || slotMin >= hhmmToMinutes(hour.close_time)
}

const gridStyle = computed(() => ({
  gridTemplateColumns: `72px repeat(${staff.value.length}, minmax(150px, 1fr))`,
}))

interface PositionedBlock {
  reservation: Reservation
  top: number
  height: number
  leftPct: number
  widthPct: number
  compact: boolean
}

const blocksByStaff = computed<Record<number, PositionedBlock[]>>(() => {
  const map: Record<number, PositionedBlock[]> = {}
  const range = displayRange.value
  for (const user of staff.value) {
    const own = reservations.value.filter((reservation) => reservation.user.id === user.id)
    map[user.id] = layoutReservations(own, selectedDate.value).map((block) => {
      const height = Math.max(((block.endMin - block.startMin) / SLOT_MINUTES) * SLOT_PX - 2, 20)
      const width = 100 / block.laneCount
      return {
        reservation: block.reservation,
        top: ((block.startMin - range.startMin) / SLOT_MINUTES) * SLOT_PX + 1,
        height,
        leftPct: block.lane * width,
        widthPct: width,
        compact: height < SLOT_PX,
      }
    })
  }
  return map
})

interface PositionedBusyBlock {
  block: BusyBlock
  top: number
  height: number
  leftPct: number
  widthPct: number
  compact: boolean
}

// 外部予定ブロック。shared（user_id=null）は全スタッフ列、per_staff は担当列のみに描画する。
const busyBlocksByStaff = computed<Record<number, PositionedBusyBlock[]>>(() => {
  const map: Record<number, PositionedBusyBlock[]> = {}
  const range = displayRange.value
  for (const user of staff.value) {
    const own = busyBlocksForStaff(busyBlocks.value, user.id)
    map[user.id] = layoutBusyBlocks(own, selectedDate.value, range).map((block) => {
      const height = Math.max(((block.endMin - block.startMin) / SLOT_MINUTES) * SLOT_PX - 2, 20)
      const width = 100 / block.laneCount
      return {
        block: block.block,
        top: ((block.startMin - range.startMin) / SLOT_MINUTES) * SLOT_PX + 1,
        height,
        leftPct: block.lane * width,
        widthPct: width,
        compact: height < SLOT_PX,
      }
    })
  }
  return map
})

// ---- ダイアログ ----

function openCreate(userId: number, slotMin: number): void {
  editingReservation.value = null
  presetUserId.value = userId
  const start = new Date(selectedDate.value)
  start.setHours(Math.floor(slotMin / 60), slotMin % 60, 0, 0)
  presetStartAt.value = start
  dialogVisible.value = true
}

function openEdit(reservation: Reservation): void {
  editingReservation.value = reservation
  presetUserId.value = null
  presetStartAt.value = null
  dialogVisible.value = true
}

function onSaved(): void {
  toast.add({
    severity: 'success',
    summary: editingReservation.value ? '予約を更新しました' : '予約を登録しました',
    life: 3000,
  })
  void fetchReservations()
}

function onDeleted(): void {
  toast.add({ severity: 'success', summary: '予約を削除しました', life: 3000 })
  void fetchReservations()
}
</script>

<template>
  <div class="rb-page">
    <PageHeader
      title="予約カレンダー"
      icon="pi pi-calendar"
      subtitle="スタッフ別の予約を確認・登録できます"
    />

    <GlassCard>
      <div class="calendar-toolbar">
        <div class="nav-buttons">
          <Button
            icon="pi pi-chevron-left"
            severity="secondary"
            outlined
            rounded
            aria-label="前日"
            @click="moveDay(-1)"
          />
          <Button label="今日" severity="secondary" outlined @click="goToday" />
          <Button
            icon="pi pi-chevron-right"
            severity="secondary"
            outlined
            rounded
            aria-label="翌日"
            @click="moveDay(1)"
          />
        </div>
        <DatePicker
          :model-value="selectedDate"
          date-format="yy/mm/dd"
          show-icon
          icon-display="input"
          :manual-input="false"
          class="toolbar-datepicker"
          aria-label="表示日を選択"
          @update:model-value="onDatePicked"
        />
        <span class="current-date">{{ formatDateJa(selectedDate) }}</span>
      </div>

      <div v-if="!initialized" class="skeleton-grid">
        <Skeleton height="480px" border-radius="14px" />
      </div>

      <div v-else-if="loadError" class="load-error">
        <i class="pi pi-exclamation-triangle" />
        <p>予約情報を読み込めませんでした</p>
        <Button label="再読み込み" icon="pi pi-refresh" outlined @click="fetchAll" />
      </div>

      <EmptyState
        v-else-if="staff.length === 0"
        icon="pi pi-users"
        title="スタッフが登録されていません"
        description="予約カレンダーを利用するには、在籍スタッフが必要です"
      />

      <div v-else class="calendar-scroll" :class="{ 'is-loading': loading }">
        <div class="calendar-grid" :style="gridStyle">
          <div class="head-cell corner-cell" />
          <div v-for="user in staff" :key="`head-${user.id}`" class="head-cell staff-head">
            <i class="pi pi-user" />
            {{ user.name }}
          </div>

          <div class="time-col">
            <div
              v-for="slot in slots"
              :key="slot.min"
              class="time-cell"
              :class="{ hour: slot.isHour }"
            >
              <span v-if="slot.isHour">{{ minutesToHHMM(slot.min) }}</span>
            </div>
          </div>

          <div v-for="user in staff" :key="user.id" class="staff-col">
            <div
              v-for="slot in slots"
              :key="slot.min"
              class="slot-cell"
              :class="{ outside: isOutsideBusinessHours(slot.min), hour: slot.isHour }"
              role="button"
              :aria-label="`${user.name} ${minutesToHHMM(slot.min)} に予約を登録`"
              @click="openCreate(user.id, slot.min)"
            />
            <!-- 外部予定: グレー・時刻のみ。pointer-events:none で空セルのクリックを吸収せず、
                 覆われた時間帯からも新規登録できる（管理側は busy でも登録可能） -->
            <div
              v-for="block in busyBlocksByStaff[user.id] ?? []"
              :key="`busy-${block.block.id}`"
              class="external-block"
              :style="{
                top: `${block.top}px`,
                height: `${block.height}px`,
                left: `calc(${block.leftPct}% + 2px)`,
                width: `calc(${block.widthPct}% - 4px)`,
              }"
            >
              <span class="external-label">外部予定</span>
              <span v-if="!block.compact" class="external-time">
                {{ formatTime(block.block.start_at) }}〜{{ formatTime(block.block.end_at) }}
              </span>
            </div>
            <button
              v-for="block in blocksByStaff[user.id] ?? []"
              :key="block.reservation.id"
              type="button"
              class="reservation-block"
              :class="block.reservation.status"
              :style="{
                top: `${block.top}px`,
                height: `${block.height}px`,
                left: `calc(${block.leftPct}% + 2px)`,
                width: `calc(${block.widthPct}% - 4px)`,
              }"
              @click.stop="openEdit(block.reservation)"
            >
              <span class="block-head">
                <span class="block-customer">{{ block.reservation.customer.name }}</span>
                <span
                  v-if="block.reservation.source === 'web'"
                  class="block-source"
                  title="Web予約から登録された予約"
                >
                  WEB
                </span>
              </span>
              <template v-if="!block.compact">
                <span class="block-menu">{{ block.reservation.menu.name }}</span>
                <span class="block-time">
                  {{ formatTime(block.reservation.start_at) }}〜{{
                    formatTime(block.reservation.end_at)
                  }}
                </span>
              </template>
            </button>
          </div>
        </div>
      </div>
    </GlassCard>

    <ReservationFormDialog
      v-model:visible="dialogVisible"
      :reservation="editingReservation"
      :menus="menus"
      :staff="staff"
      :preset-user-id="presetUserId"
      :preset-start-at="presetStartAt"
      @saved="onSaved"
      @deleted="onDeleted"
    />
  </div>
</template>

<style scoped>
.calendar-toolbar {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1.1rem;
}

.nav-buttons {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.toolbar-datepicker {
  width: 170px;
}

.current-date {
  font-family: var(--rb-font-display);
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--rb-text);
}

.skeleton-grid {
  display: flex;
  flex-direction: column;
}

.load-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.6rem;
  padding: 2.4rem 1rem;
  color: var(--rb-text-muted);
}

.load-error i {
  font-size: 1.6rem;
  color: var(--rb-pink);
}

.load-error p {
  margin: 0;
  font-weight: 600;
  color: var(--rb-text);
}

.calendar-scroll {
  overflow: auto;
  max-height: 72vh;
  border: 1px solid var(--rb-border);
  border-radius: var(--rb-radius-md);
  transition: opacity 0.15s ease;
}

.calendar-scroll.is-loading {
  opacity: 0.6;
  pointer-events: none;
}

.calendar-grid {
  display: grid;
  grid-template-rows: auto 1fr;
  min-width: 100%;
}

.head-cell {
  position: sticky;
  top: 0;
  z-index: 5;
  padding: 0.6rem 0.5rem;
  background: #fdf5f7;
  border-bottom: 1px solid var(--rb-border);
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--rb-text);
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.staff-head i {
  margin-right: 0.35rem;
  font-size: 0.78rem;
  color: var(--rb-pink);
}

.corner-cell {
  left: 0;
  z-index: 6;
}

.time-col {
  position: sticky;
  left: 0;
  z-index: 4;
  background: #fffdfc;
}

.time-cell {
  height: 44px;
  padding: 0.15rem 0.5rem 0 0;
  border-right: 1px solid var(--rb-border);
  border-top: 1px dashed transparent;
  font-size: 0.74rem;
  color: var(--rb-text-muted);
  text-align: right;
}

.time-cell.hour {
  border-top: 1px solid var(--rb-border);
}

.staff-col {
  position: relative;
  border-right: 1px solid var(--rb-border);
}

.staff-col:last-child {
  border-right: none;
}

.slot-cell {
  height: 44px;
  border-top: 1px dashed var(--rb-border);
  cursor: pointer;
  transition: background-color 0.12s ease;
}

.slot-cell.hour {
  border-top: 1px solid var(--rb-border);
}

.slot-cell:hover {
  background: var(--rb-pink-faint);
}

/* 営業時間外（クリックは可能） */
.slot-cell.outside {
  background: rgba(240, 228, 232, 0.55);
}

.slot-cell.outside:hover {
  background: rgba(240, 228, 232, 0.9);
}

.reservation-block {
  position: absolute;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.05rem;
  z-index: 2;
  padding: 0.25rem 0.45rem;
  border: none;
  border-left: 3px solid rgba(255, 255, 255, 0.75);
  border-radius: 8px;
  overflow: hidden;
  font-family: var(--rb-font);
  text-align: left;
  color: #fff;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(216, 108, 138, 0.25);
  transition: filter 0.12s ease;
}

.reservation-block:hover {
  filter: brightness(1.06);
}

/* 外部予定（Googleカレンダーの RB 以外の予定）。グレー・時刻のみ。
   pointer-events:none で下の空セルへクリックを透過させる（新規登録を塞がない）。 */
.external-block {
  position: absolute;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.05rem;
  z-index: 1;
  padding: 0.22rem 0.4rem;
  border-left: 3px solid rgba(154, 141, 145, 0.6);
  border-radius: 8px;
  overflow: hidden;
  background: repeating-linear-gradient(
    45deg,
    rgba(154, 141, 145, 0.2),
    rgba(154, 141, 145, 0.2) 6px,
    rgba(154, 141, 145, 0.32) 6px,
    rgba(154, 141, 145, 0.32) 12px
  );
  font-family: var(--rb-font);
  color: #63585c;
  text-align: left;
  pointer-events: none;
}

.external-label {
  font-size: 0.72rem;
  font-weight: 700;
  white-space: nowrap;
}

.external-time {
  font-size: 0.68rem;
  opacity: 0.85;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.reservation-block.reserved {
  background: #d86c8a;
}

.reservation-block.visited {
  background: #b98aa6;
}

.reservation-block.cancelled {
  background: rgba(154, 141, 145, 0.55);
  text-decoration: line-through;
}

.reservation-block.no_show {
  background: rgba(203, 169, 109, 0.65);
}

.block-head {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  width: 100%;
}

.block-customer {
  font-size: 0.78rem;
  font-weight: 700;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.block-source {
  flex-shrink: 0;
  padding: 0 0.28rem;
  border-radius: 4px;
  background: rgba(255, 255, 255, 0.9);
  color: var(--rb-pink-deep);
  font-size: 0.58rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  line-height: 1.5;
}

.block-menu,
.block-time {
  font-size: 0.7rem;
  opacity: 0.92;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
