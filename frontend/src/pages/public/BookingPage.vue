<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { AxiosError } from 'axios'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import EmptyState from '@/components/common/EmptyState.vue'
import PublicLayout from '@/layouts/PublicLayout.vue'
import { publicBookingService } from '@/services/publicBookingService'
import { extractErrorMessage, extractFieldErrors } from '@/utils/apiError'
import { formatNumber, formatTime, toDateString } from '@/utils/format'
import {
  bookingSelectableRange,
  buildCancelUrl,
  calcEndAtIso,
  formatDateTimeRange,
} from '@/utils/publicBooking'
import type {
  AvailabilitySlot,
  PublicMenu,
  PublicReservationResponse,
  PublicSalon,
  PublicStaff,
} from '@/types'

const THROTTLE_MESSAGE = 'アクセスが集中しています。しばらく時間をおいて再度お試しください'
const STEPS = ['メニュー', 'スタッフ', '日時', 'お客様情報', '確認'] as const

const route = useRoute()
const toast = useToast()

const bookingSlug = computed(() => String(route.params.slug ?? ''))

// ---- サロン情報 ----

type LoadState = 'loading' | 'ready' | 'notFound' | 'error'
const loadState = ref<LoadState>('loading')
const salon = ref<PublicSalon | null>(null)

async function fetchSalon(): Promise<void> {
  loadState.value = 'loading'
  try {
    salon.value = await publicBookingService.getSalon(bookingSlug.value)
    loadState.value = 'ready'
  } catch (error) {
    const status = error instanceof AxiosError ? error.response?.status : undefined
    loadState.value = status === 404 ? 'notFound' : 'error'
  }
}

onMounted(() => {
  void fetchSalon()
})

// ---- ステップ・選択状態 ----

const step = ref(1)
const completed = ref<PublicReservationResponse | null>(null)

const selectedMenu = ref<PublicMenu | null>(null)
const selectedStaff = ref<PublicStaff | null>(null) // null = 指名なし

const selectedDate = ref<Date | null>(null)
const slots = ref<AvailabilitySlot[]>([])
const slotsLoading = ref(false)
const slotsErrorMessage = ref('')
const selectedSlot = ref<string | null>(null)
const startAtError = ref('')

const form = reactive({ name: '', kana: '', phone: '' })
const fieldErrors = reactive({ name: '', kana: '', phone: '' })
const submitting = ref(false)

const dateRange = computed(() => bookingSelectableRange())

function resetDateTime(): void {
  selectedDate.value = null
  slots.value = []
  slotsErrorMessage.value = ''
  selectedSlot.value = null
  startAtError.value = ''
}

function selectMenu(menu: PublicMenu): void {
  if (selectedMenu.value?.id !== menu.id) {
    selectedStaff.value = null
    resetDateTime()
  }
  selectedMenu.value = menu
  step.value = 2
}

function selectStaff(staff: PublicStaff | null): void {
  if ((selectedStaff.value?.id ?? null) !== (staff?.id ?? null)) {
    resetDateTime()
  }
  selectedStaff.value = staff
  step.value = 3
}

function selectSlot(startAt: string): void {
  selectedSlot.value = startAt
  startAtError.value = ''
  step.value = 4
}

function goBack(): void {
  if (step.value > 1) step.value -= 1
}

// ---- 空き枠 ----

let slotsFetchSeq = 0

async function fetchSlots(): Promise<void> {
  if (!selectedDate.value || !selectedMenu.value) return
  const seq = ++slotsFetchSeq
  slotsLoading.value = true
  slotsErrorMessage.value = ''
  try {
    const list = await publicBookingService.listAvailability(bookingSlug.value, {
      date: toDateString(selectedDate.value),
      menu_id: selectedMenu.value.id,
      ...(selectedStaff.value ? { user_id: selectedStaff.value.id } : {}),
    })
    if (seq !== slotsFetchSeq) return
    slots.value = list
  } catch (error) {
    if (seq !== slotsFetchSeq) return
    const status = error instanceof AxiosError ? error.response?.status : undefined
    slotsErrorMessage.value = status === 429 ? THROTTLE_MESSAGE : '空き時間を取得できませんでした'
  } finally {
    if (seq === slotsFetchSeq) slotsLoading.value = false
  }
}

function onDatePicked(value: Date | Date[] | (Date | null)[] | null | undefined): void {
  if (!(value instanceof Date)) return
  selectedDate.value = value
  selectedSlot.value = null
  void fetchSlots()
}

// ---- お客様情報・確定 ----

function validateCustomerForm(): boolean {
  fieldErrors.name =
    form.name.trim() === ''
      ? 'お名前を入力してください'
      : form.name.length > 100
        ? 'お名前は100文字以内で入力してください'
        : ''
  fieldErrors.kana =
    form.kana.trim() === ''
      ? 'フリガナを入力してください'
      : form.kana.length > 100
        ? 'フリガナは100文字以内で入力してください'
        : ''
  fieldErrors.phone =
    form.phone.trim() === ''
      ? '電話番号を入力してください'
      : form.phone.length > 20
        ? '電話番号は20文字以内で入力してください'
        : ''
  return fieldErrors.name === '' && fieldErrors.kana === '' && fieldErrors.phone === ''
}

function goConfirm(): void {
  if (validateCustomerForm()) step.value = 5
}

const confirmEndAt = computed(() =>
  selectedSlot.value && selectedMenu.value
    ? calcEndAtIso(selectedSlot.value, selectedMenu.value.duration_minutes)
    : null,
)

async function submitReservation(): Promise<void> {
  if (submitting.value || !selectedMenu.value || !selectedSlot.value) return
  submitting.value = true
  try {
    completed.value = await publicBookingService.createReservation(bookingSlug.value, {
      menu_id: selectedMenu.value.id,
      user_id: selectedStaff.value?.id ?? null,
      start_at: selectedSlot.value,
      name: form.name,
      kana: form.kana,
      phone: form.phone,
    })
  } catch (error) {
    handleSubmitError(error)
  } finally {
    submitting.value = false
  }
}

function handleSubmitError(error: unknown): void {
  const status = error instanceof AxiosError ? error.response?.status : undefined
  if (status === 422) {
    const errors = extractFieldErrors(error)
    fieldErrors.name = errors.name ?? ''
    fieldErrors.kana = errors.kana ?? ''
    fieldErrors.phone = errors.phone ?? ''
    if (errors.start_at) {
      // 時間帯系エラー: サーバメッセージを表示し、空き枠を再取得して日時選択へ戻す
      startAtError.value = errors.start_at
      selectedSlot.value = null
      step.value = 3
      void fetchSlots()
      return
    }
    if (fieldErrors.name || fieldErrors.kana || fieldErrors.phone) {
      step.value = 4
      return
    }
  }
  const summary =
    status === 429 ? THROTTLE_MESSAGE : extractErrorMessage(error, '予約の登録に失敗しました')
  toast.add({ severity: 'error', summary, life: 4000 })
}

// ---- 完了画面 ----

const cancelUrl = computed(() =>
  completed.value ? buildCancelUrl(window.location.origin, completed.value.booking_token) : '',
)

async function copyText(text: string, label: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(text)
    toast.add({ severity: 'success', summary: `${label}をコピーしました`, life: 2500 })
  } catch {
    toast.add({ severity: 'error', summary: 'コピーに失敗しました', life: 2500 })
  }
}
</script>

<template>
  <PublicLayout :salon-name="salon?.name ?? null">
    <template v-if="loadState === 'loading'">
      <Skeleton height="54px" border-radius="20px" />
      <Skeleton height="360px" border-radius="20px" />
    </template>

    <div v-else-if="loadState === 'notFound'" class="glass-card state-card">
      <EmptyState
        icon="pi pi-search"
        title="ページが見つかりません"
        description="URLをお確かめのうえ、サロンから案内されたリンクを開いてください"
      />
    </div>

    <div v-else-if="loadState === 'error'" class="glass-card state-card load-error">
      <i class="pi pi-exclamation-triangle" />
      <p>ページを読み込めませんでした</p>
      <Button label="再読み込み" icon="pi pi-refresh" outlined @click="fetchSalon" />
    </div>

    <template v-else-if="salon">
      <!-- 完了画面 -->
      <template v-if="completed">
        <div class="glass-card complete-card">
          <div class="complete-head">
            <span class="complete-icon"><i class="pi pi-check" /></span>
            <h2 class="complete-title">ご予約を承りました</h2>
          </div>
          <dl class="summary">
            <div class="summary-row">
              <dt>メニュー</dt>
              <dd>{{ completed.menu_name }}</dd>
            </div>
            <div class="summary-row">
              <dt>担当</dt>
              <dd>{{ completed.staff_name }}</dd>
            </div>
            <div class="summary-row">
              <dt>日時</dt>
              <dd>{{ formatDateTimeRange(completed.start_at, completed.end_at) }}</dd>
            </div>
          </dl>
          <div class="copy-block">
            <p class="block-label">キャンセルはこちら</p>
            <div class="copy-row">
              <span class="copy-text">{{ cancelUrl }}</span>
              <Button
                icon="pi pi-copy"
                label="コピー"
                size="small"
                severity="secondary"
                outlined
                @click="copyText(cancelUrl, 'キャンセル用URL')"
              />
            </div>
            <p class="note">
              キャンセルはこのURLからお手続きください。このページを閉じると再表示できません
            </p>
          </div>
        </div>

        <div v-if="completed.line" class="glass-card line-card">
          <p class="line-lead">
            <i class="pi pi-bell" />
            LINEで予約前日にお知らせが届きます
          </p>
          <a
            class="line-add-button"
            :href="completed.line.add_friend_url"
            target="_blank"
            rel="noopener noreferrer"
          >
            <i class="pi pi-comment" />
            LINE友だち追加
          </a>
          <div class="copy-block">
            <p class="block-label">連携コード</p>
            <div class="copy-row">
              <span class="link-code">{{ completed.line.link_code }}</span>
              <Button
                icon="pi pi-copy"
                label="コピー"
                size="small"
                severity="secondary"
                outlined
                @click="copyText(completed.line!.link_code, '連携コード')"
              />
            </div>
          </div>
          <p class="line-guide">
            1. 友だち追加 → 2. トークに連携コードを送信 → 予約前日にLINEでお知らせが届きます
          </p>
          <p class="note">コードの有効期限は72時間です</p>
        </div>
      </template>

      <!-- 予約フロー -->
      <template v-else>
        <ol class="glass-card steps" aria-label="予約ステップ">
          <li
            v-for="(label, index) in STEPS"
            :key="label"
            class="step-item"
            :class="{ active: step === index + 1, done: step > index + 1 }"
            :aria-current="step === index + 1 ? 'step' : undefined"
          >
            <span class="step-no">
              <i v-if="step > index + 1" class="pi pi-check" />
              <template v-else>{{ index + 1 }}</template>
            </span>
            <span class="step-label">{{ label }}</span>
          </li>
        </ol>

        <div class="glass-card step-card">
          <!-- Step 1: メニュー選択 -->
          <template v-if="step === 1">
            <h2 class="step-title">メニューをお選びください</h2>
            <div class="option-list">
              <button
                v-for="menu in salon.menus"
                :key="menu.id"
                type="button"
                class="option-card"
                :class="{ selected: selectedMenu?.id === menu.id }"
                @click="selectMenu(menu)"
              >
                <span class="option-name">{{ menu.name }}</span>
                <span class="option-meta">
                  {{ menu.duration_minutes }}分・¥{{ formatNumber(menu.price) }}
                </span>
              </button>
            </div>
            <EmptyState
              v-if="salon.menus.length === 0"
              icon="pi pi-list"
              title="ご予約いただけるメニューがありません"
              description="サロンへ直接お問い合わせください"
            />
          </template>

          <!-- Step 2: スタッフ選択 -->
          <template v-else-if="step === 2">
            <h2 class="step-title">担当スタッフをお選びください</h2>
            <div class="option-list">
              <button
                type="button"
                class="option-card"
                :class="{ selected: selectedStaff === null }"
                @click="selectStaff(null)"
              >
                <span class="option-name">指名なし</span>
                <span class="option-meta">空いているスタッフが担当します</span>
              </button>
              <button
                v-for="staff in salon.staff"
                :key="staff.id"
                type="button"
                class="option-card"
                :class="{ selected: selectedStaff?.id === staff.id }"
                @click="selectStaff(staff)"
              >
                <span class="option-name">{{ staff.name }}</span>
              </button>
            </div>
          </template>

          <!-- Step 3: 日時選択 -->
          <template v-else-if="step === 3">
            <h2 class="step-title">ご希望の日時をお選びください</h2>
            <p v-if="startAtError" class="start-at-error" role="alert">
              <i class="pi pi-exclamation-circle" />
              {{ startAtError }}
            </p>
            <div class="field">
              <label class="field-label" for="booking-date">日付</label>
              <DatePicker
                :model-value="selectedDate"
                input-id="booking-date"
                date-format="yy/mm/dd"
                show-icon
                icon-display="input"
                :manual-input="false"
                :min-date="dateRange.minDate"
                :max-date="dateRange.maxDate"
                fluid
                @update:model-value="onDatePicked"
              />
            </div>
            <template v-if="selectedDate">
              <p class="field-label">空き時間</p>
              <Skeleton v-if="slotsLoading" height="140px" border-radius="14px" />
              <div v-else-if="slotsErrorMessage" class="slots-error">
                <p>{{ slotsErrorMessage }}</p>
                <Button
                  label="再試行"
                  icon="pi pi-refresh"
                  size="small"
                  outlined
                  @click="fetchSlots"
                />
              </div>
              <p v-else-if="slots.length === 0" class="slots-empty">
                この日はご予約いただけません。別の日をお選びください
              </p>
              <div v-else class="slot-grid">
                <button
                  v-for="slot in slots"
                  :key="slot.start_at"
                  type="button"
                  class="slot-button"
                  :class="{ selected: selectedSlot === slot.start_at }"
                  @click="selectSlot(slot.start_at)"
                >
                  {{ formatTime(slot.start_at) }}
                </button>
              </div>
            </template>
            <p v-else class="slots-empty">日付をお選びください</p>
          </template>

          <!-- Step 4: お客様情報入力 -->
          <template v-else-if="step === 4">
            <h2 class="step-title">お客様情報をご入力ください</h2>
            <form class="customer-form" novalidate @submit.prevent="goConfirm">
              <div class="field">
                <label class="field-label" for="booking-name">お名前</label>
                <InputText
                  id="booking-name"
                  v-model="form.name"
                  autocomplete="name"
                  placeholder="山田 花子"
                  maxlength="100"
                  fluid
                  :invalid="fieldErrors.name !== ''"
                />
                <small v-if="fieldErrors.name" class="field-error">
                  <i class="pi pi-exclamation-circle" />
                  {{ fieldErrors.name }}
                </small>
              </div>
              <div class="field">
                <label class="field-label" for="booking-kana">フリガナ</label>
                <InputText
                  id="booking-kana"
                  v-model="form.kana"
                  placeholder="ヤマダ ハナコ"
                  maxlength="100"
                  fluid
                  :invalid="fieldErrors.kana !== ''"
                />
                <small v-if="fieldErrors.kana" class="field-error">
                  <i class="pi pi-exclamation-circle" />
                  {{ fieldErrors.kana }}
                </small>
              </div>
              <div class="field">
                <label class="field-label" for="booking-phone">電話番号</label>
                <InputText
                  id="booking-phone"
                  v-model="form.phone"
                  type="tel"
                  autocomplete="tel"
                  placeholder="09012345678"
                  maxlength="20"
                  fluid
                  :invalid="fieldErrors.phone !== ''"
                />
                <small v-if="fieldErrors.phone" class="field-error">
                  <i class="pi pi-exclamation-circle" />
                  {{ fieldErrors.phone }}
                </small>
              </div>
              <Button type="submit" label="次へ" icon="pi pi-arrow-right" icon-pos="right" fluid />
            </form>
          </template>

          <!-- Step 5: 確認 -->
          <template v-else-if="step === 5">
            <h2 class="step-title">ご予約内容の確認</h2>
            <dl class="summary">
              <div class="summary-row">
                <dt>メニュー</dt>
                <dd v-if="selectedMenu">
                  {{ selectedMenu.name }}（{{ selectedMenu.duration_minutes }}分・¥{{
                    formatNumber(selectedMenu.price)
                  }}）
                </dd>
              </div>
              <div class="summary-row">
                <dt>担当</dt>
                <dd>{{ selectedStaff?.name ?? '指名なし' }}</dd>
              </div>
              <div class="summary-row">
                <dt>日時</dt>
                <dd v-if="selectedSlot && confirmEndAt">
                  {{ formatDateTimeRange(selectedSlot, confirmEndAt) }}
                </dd>
              </div>
              <div class="summary-row">
                <dt>お名前</dt>
                <dd>{{ form.name }}</dd>
              </div>
              <div class="summary-row">
                <dt>フリガナ</dt>
                <dd>{{ form.kana }}</dd>
              </div>
              <div class="summary-row">
                <dt>電話番号</dt>
                <dd>{{ form.phone }}</dd>
              </div>
            </dl>
            <Button
              label="予約を確定する"
              icon="pi pi-check"
              fluid
              :loading="submitting"
              @click="submitReservation"
            />
          </template>

          <div v-if="step > 1" class="step-footer">
            <Button
              label="戻る"
              icon="pi pi-arrow-left"
              severity="secondary"
              text
              :disabled="submitting"
              @click="goBack"
            />
          </div>
        </div>
      </template>
    </template>
  </PublicLayout>
</template>

<style scoped>
.state-card {
  padding: 1.4rem 1.2rem;
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

/* ---------- ステップインジケータ ---------- */

.steps {
  display: flex;
  justify-content: space-between;
  gap: 0.2rem;
  margin: 0;
  padding: 0.7rem 0.8rem;
  list-style: none;
}

.step-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  flex: 1;
  min-width: 0;
}

.step-no {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--rb-pink-faint);
  border: 1px solid var(--rb-border);
  color: var(--rb-text-muted);
  font-size: 0.78rem;
  font-weight: 700;
}

.step-item.active .step-no {
  background: var(--rb-gradient-brand);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 4px 12px rgba(216, 108, 138, 0.35);
}

.step-item.done .step-no {
  background: var(--rb-pink-tint);
  border-color: transparent;
  color: var(--rb-pink-deep);
}

.step-item.done .step-no i {
  font-size: 0.7rem;
}

.step-label {
  font-size: 0.62rem;
  color: var(--rb-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}

.step-item.active .step-label {
  color: var(--rb-pink-strong);
  font-weight: 700;
}

/* ---------- ステップカード ---------- */

.step-card {
  padding: 1.3rem 1.2rem;
}

.step-title {
  margin: 0 0 1rem;
  font-size: 1rem;
}

.step-footer {
  margin-top: 1rem;
}

/* ---------- 選択カード ---------- */

.option-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.option-card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.2rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--rb-border);
  border-radius: var(--rb-radius-md);
  background: rgba(255, 255, 255, 0.6);
  font-family: var(--rb-font);
  text-align: left;
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease,
    box-shadow 0.15s ease;
}

.option-card:hover {
  background: var(--rb-pink-faint);
}

.option-card.selected {
  border-color: var(--rb-pink);
  background: var(--rb-pink-faint);
  box-shadow: 0 4px 14px rgba(216, 108, 138, 0.18);
}

.option-name {
  font-weight: 700;
  color: var(--rb-text);
}

.option-meta {
  font-size: 0.82rem;
  color: var(--rb-text-muted);
}

/* ---------- 日時選択 ---------- */

.start-at-error {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
  margin: 0 0 1rem;
  padding: 0.7rem 0.9rem;
  border-radius: var(--rb-radius-sm);
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
  font-size: 0.85rem;
  font-weight: 600;
}

.start-at-error i {
  margin-top: 0.15rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-bottom: 1rem;
}

.field-label {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--rb-text);
  margin: 0 0 0.4rem;
}

.field .field-label {
  margin: 0;
}

.field-error {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.76rem;
  color: var(--rb-pink-strong);
}

.slot-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
  gap: 0.5rem;
}

.slot-button {
  padding: 0.6rem 0.4rem;
  border: 1px solid var(--rb-border);
  border-radius: var(--rb-radius-sm);
  background: rgba(255, 255, 255, 0.6);
  font-family: var(--rb-font);
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--rb-text);
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease;
}

.slot-button:hover {
  background: var(--rb-pink-faint);
}

.slot-button.selected {
  border-color: var(--rb-pink);
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
}

.slots-empty {
  margin: 0;
  padding: 1.2rem 0.5rem;
  text-align: center;
  font-size: 0.88rem;
  color: var(--rb-text-muted);
}

.slots-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 1rem 0.5rem;
}

.slots-error p {
  margin: 0;
  font-size: 0.88rem;
  color: var(--rb-text-muted);
}

/* ---------- お客様情報 ---------- */

.customer-form {
  display: flex;
  flex-direction: column;
}

/* ---------- 確認・完了 ---------- */

.summary {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  margin: 0 0 1.2rem;
}

.summary-row {
  display: flex;
  gap: 0.8rem;
}

.summary-row dt {
  flex-shrink: 0;
  width: 5.5em;
  font-size: 0.85rem;
  color: var(--rb-text-muted);
}

.summary-row dd {
  margin: 0;
  font-size: 0.92rem;
  font-weight: 600;
  color: var(--rb-text);
  overflow-wrap: anywhere;
}

.complete-card {
  padding: 1.5rem 1.3rem;
}

.complete-head {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  margin-bottom: 1.1rem;
}

.complete-icon {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--rb-gradient-brand);
  color: #fff;
  font-size: 1rem;
  box-shadow: 0 4px 14px rgba(216, 108, 138, 0.35);
}

.complete-title {
  margin: 0;
  font-size: 1.15rem;
}

.copy-block {
  padding-top: 0.9rem;
  border-top: 1px solid var(--rb-border);
}

.block-label {
  margin: 0 0 0.4rem;
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--rb-text);
}

.copy-row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.copy-text {
  flex: 1;
  min-width: 0;
  font-size: 0.8rem;
  color: var(--rb-text);
  word-break: break-all;
}

.note {
  margin: 0.5rem 0 0;
  font-size: 0.76rem;
  color: var(--rb-text-muted);
}

/* ---------- LINE連携案内 ---------- */

.line-card {
  padding: 1.4rem 1.3rem;
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}

.line-lead {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0;
  font-weight: 700;
  color: var(--rb-text);
}

.line-lead i {
  color: var(--rb-pink);
}

.line-add-button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.8rem 1rem;
  border-radius: var(--rb-radius-sm);
  background: #06c755;
  color: #fff;
  font-weight: 700;
  text-decoration: none;
  box-shadow: 0 4px 14px rgba(6, 199, 85, 0.35);
  transition: filter 0.15s ease;
}

.line-add-button:hover {
  filter: brightness(1.05);
}

.line-card .copy-block {
  border-top: none;
  padding-top: 0;
}

.link-code {
  font-size: 1.6rem;
  font-weight: 700;
  letter-spacing: 0.22em;
  color: var(--rb-text);
}

.line-guide {
  margin: 0;
  font-size: 0.82rem;
  color: var(--rb-text);
}

.line-card .note {
  margin: 0;
}
</style>
