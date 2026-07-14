<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Skeleton from 'primevue/skeleton'
import ToggleSwitch from 'primevue/toggleswitch'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { businessHourService } from '@/services/businessHourService'
import { extractErrorMessage, extractFieldErrors } from '@/utils/apiError'
import { weekdayLabel } from '@/utils/format'
import { hhmmToMinutes } from '@/utils/reservationCalendar'
import type { BusinessHour } from '@/types'

interface RowForm {
  day_of_week: number
  is_closed: boolean
  open: Date | null
  close: Date | null
}

const toast = useToast()

const rows = ref<RowForm[]>([])
const initialSnapshot = ref('')
const initialized = ref(false)
const loadError = ref(false)
const saving = ref(false)
const fieldErrors = ref<Record<string, string>>({})

function hhmmToDate(hhmm: string): Date {
  const date = new Date()
  const minutes = hhmmToMinutes(hhmm)
  date.setHours(Math.floor(minutes / 60), minutes % 60, 0, 0)
  return date
}

function dateToHHMM(date: Date | null): string {
  if (!date) return ''
  return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

function serialize(list: RowForm[]): BusinessHour[] {
  return [...list]
    .sort((a, b) => a.day_of_week - b.day_of_week)
    .map((row) => ({
      day_of_week: row.day_of_week,
      is_closed: row.is_closed,
      open_time: dateToHHMM(row.open),
      close_time: dateToHHMM(row.close),
    }))
}

async function fetchHours(): Promise<void> {
  loadError.value = false
  initialized.value = false
  try {
    const hours = await businessHourService.list()
    rows.value = [...hours]
      .sort((a, b) => a.day_of_week - b.day_of_week)
      .map((hour) => ({
        day_of_week: hour.day_of_week,
        is_closed: hour.is_closed,
        open: hhmmToDate(hour.open_time),
        close: hhmmToDate(hour.close_time),
      }))
    initialSnapshot.value = JSON.stringify(serialize(rows.value))
    fieldErrors.value = {}
  } catch (error) {
    loadError.value = true
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '営業時間の取得に失敗しました'),
      life: 3000,
    })
  } finally {
    initialized.value = true
  }
}

onMounted(() => {
  void fetchHours()
})

const dirty = computed(() => JSON.stringify(serialize(rows.value)) !== initialSnapshot.value)

function validate(): boolean {
  const errors: Record<string, string> = {}
  rows.value.forEach((row, index) => {
    if (!row.open) {
      errors[`business_hours.${index}.open_time`] = '開店時刻を入力してください'
      return
    }
    if (!row.close) {
      errors[`business_hours.${index}.close_time`] = '閉店時刻を入力してください'
      return
    }
    if (hhmmToMinutes(dateToHHMM(row.close)) <= hhmmToMinutes(dateToHHMM(row.open))) {
      errors[`business_hours.${index}.close_time`] = '閉店時刻は開店時刻より後にしてください'
    }
  })
  fieldErrors.value = errors
  return Object.keys(errors).length === 0
}

async function save(): Promise<void> {
  if (saving.value || !validate()) return
  saving.value = true
  try {
    const hours = await businessHourService.updateAll({ business_hours: serialize(rows.value) })
    rows.value = [...hours]
      .sort((a, b) => a.day_of_week - b.day_of_week)
      .map((hour) => ({
        day_of_week: hour.day_of_week,
        is_closed: hour.is_closed,
        open: hhmmToDate(hour.open_time),
        close: hhmmToDate(hour.close_time),
      }))
    initialSnapshot.value = JSON.stringify(serialize(rows.value))
    fieldErrors.value = {}
    toast.add({ severity: 'success', summary: '営業時間を保存しました', life: 3000 })
  } catch (error) {
    const errors = extractFieldErrors(error)
    if (Object.keys(errors).length > 0) {
      fieldErrors.value = errors
      toast.add({ severity: 'error', summary: '入力内容をご確認ください', life: 3000 })
    } else {
      toast.add({
        severity: 'error',
        summary: extractErrorMessage(error, '営業時間の保存に失敗しました'),
        life: 3000,
      })
    }
  } finally {
    saving.value = false
  }
}

function rowError(index: number): string {
  return (
    fieldErrors.value[`business_hours.${index}.open_time`] ??
    fieldErrors.value[`business_hours.${index}.close_time`] ??
    fieldErrors.value[`business_hours.${index}.is_closed`] ??
    fieldErrors.value[`business_hours.${index}.day_of_week`] ??
    ''
  )
}
</script>

<template>
  <div class="rb-page">
    <PageHeader
      title="営業時間設定"
      icon="pi pi-clock"
      subtitle="曜日ごとの営業時間・定休日を設定できます"
    />

    <GlassCard class="hours-card">
      <div v-if="!initialized" class="skeleton-list">
        <Skeleton v-for="n in 7" :key="n" height="52px" border-radius="14px" />
      </div>

      <div v-else-if="loadError" class="load-error">
        <i class="pi pi-exclamation-triangle" />
        <p>営業時間を読み込めませんでした</p>
        <Button label="再読み込み" icon="pi pi-refresh" outlined @click="fetchHours" />
      </div>

      <template v-else>
        <div class="hours-list">
          <div
            v-for="(row, index) in rows"
            :key="row.day_of_week"
            class="hours-row"
            :class="{ closed: row.is_closed, invalid: !!rowError(index) }"
          >
            <span class="day-label" :class="{ sunday: row.day_of_week === 0, saturday: row.day_of_week === 6 }">
              {{ weekdayLabel(row.day_of_week) }}
            </span>

            <label class="closed-toggle">
              <ToggleSwitch v-model="row.is_closed" :disabled="saving" />
              <span>定休日</span>
            </label>

            <div class="time-inputs">
              <DatePicker
                v-model="row.open"
                time-only
                hour-format="24"
                fluid
                :disabled="row.is_closed || saving"
                :invalid="!!fieldErrors[`business_hours.${index}.open_time`]"
                :aria-label="`${weekdayLabel(row.day_of_week)}曜日の開店時刻`"
              />
              <span class="time-separator">〜</span>
              <DatePicker
                v-model="row.close"
                time-only
                hour-format="24"
                fluid
                :disabled="row.is_closed || saving"
                :invalid="!!fieldErrors[`business_hours.${index}.close_time`]"
                :aria-label="`${weekdayLabel(row.day_of_week)}曜日の閉店時刻`"
              />
            </div>

            <small v-if="rowError(index)" class="field-error">
              <i class="pi pi-exclamation-circle" /> {{ rowError(index) }}
            </small>
          </div>
        </div>

        <div class="form-actions">
          <Button
            label="保存"
            icon="pi pi-check"
            :loading="saving"
            :disabled="!dirty"
            @click="save"
          />
        </div>
      </template>
    </GlassCard>
  </div>
</template>

<style scoped>
.hours-card {
  max-width: 720px;
  width: 100%;
}

.skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
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

.hours-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.hours-row {
  display: grid;
  grid-template-columns: 2.4rem auto 1fr;
  align-items: center;
  gap: 0.9rem;
  padding: 0.6rem 0.8rem;
  border-radius: var(--rb-radius-md);
  border: 1px solid var(--rb-border);
  background: rgba(255, 255, 255, 0.55);
}

.hours-row.closed {
  background: var(--rb-beige-soft);
}

.hours-row.invalid {
  border-color: var(--rb-pink);
}

.day-label {
  display: grid;
  place-items: center;
  width: 2.2rem;
  height: 2.2rem;
  border-radius: 50%;
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
  font-weight: 700;
  font-size: 0.9rem;
}

.day-label.sunday {
  background: var(--rb-pink-soft);
}

.day-label.saturday {
  background: var(--rb-beige);
  color: #8a7566;
}

.closed-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
  color: var(--rb-text);
  cursor: pointer;
  white-space: nowrap;
}

.time-inputs {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  justify-content: flex-end;
}

.time-inputs :deep(.p-datepicker) {
  max-width: 110px;
}

.time-separator {
  color: var(--rb-text-muted);
}

.field-error {
  grid-column: 1 / -1;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--rb-pink-strong);
  font-size: 0.78rem;
}

.field-error i {
  font-size: 0.75rem;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 1.2rem;
  padding-top: 1.1rem;
  border-top: 1px solid var(--rb-border);
}

@media (max-width: 560px) {
  .hours-row {
    grid-template-columns: 2.4rem auto;
  }

  .time-inputs {
    grid-column: 1 / -1;
    justify-content: flex-start;
  }
}
</style>
