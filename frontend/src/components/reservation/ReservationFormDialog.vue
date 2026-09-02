<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import type { SelectFilterEvent } from 'primevue/select'
import Textarea from 'primevue/textarea'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import { customerService } from '@/services/customerService'
import { reservationService } from '@/services/reservationService'
import { extractErrorMessage, extractFieldErrors } from '@/utils/apiError'
import { formatNumber, reservationStatusLabel, toIsoWithOffset } from '@/utils/format'
import type {
  Menu,
  Reservation,
  ReservationCreateInput,
  ReservationCustomerSummary,
  ReservationStatus,
  ReservationUpdateInput,
  StaffUser,
} from '@/types'

const props = defineProps<{
  /** 編集対象。null の場合は新規登録 */
  reservation?: Reservation | null
  /** is_active=true のメニュー */
  menus: Menu[]
  staff: StaffUser[]
  presetUserId?: number | null
  presetStartAt?: Date | null
}>()

const visible = defineModel<boolean>('visible', { required: true })

const emit = defineEmits<{
  saved: []
  deleted: []
}>()

const toast = useToast()
const confirm = useConfirm()

const isEdit = computed(() => props.reservation != null)

const form = reactive<{
  customerId: number | null
  menuId: number | null
  userId: number | null
  startAt: Date | null
  status: ReservationStatus
  note: string
}>({
  customerId: null,
  menuId: null,
  userId: null,
  startAt: null,
  status: 'reserved',
  note: '',
})

const fieldErrors = ref<Record<string, string>>({})
const saving = ref(false)
const deleting = ref(false)
const busy = computed(() => saving.value || deleting.value)

// ---- 顧客検索 ----

const customerOptions = ref<ReservationCustomerSummary[]>([])
const customerLoading = ref(false)
let customerSearchTimer: ReturnType<typeof setTimeout> | undefined
let customerSearchSeq = 0

async function fetchCustomers(keyword: string): Promise<void> {
  const seq = ++customerSearchSeq
  customerLoading.value = true
  try {
    const result = await customerService.list({
      keyword: keyword.trim() !== '' ? keyword.trim() : undefined,
      per_page: 20,
    })
    if (seq !== customerSearchSeq) return
    const options: ReservationCustomerSummary[] = result.data.map((customer) => ({
      id: customer.id,
      name: customer.name,
      kana: customer.kana,
      phone: customer.phone,
    }))
    // 選択中の顧客が検索結果に含まれない場合もラベル表示を保つ
    const selected =
      customerOptions.value.find((option) => option.id === form.customerId) ??
      props.reservation?.customer
    if (selected && !options.some((option) => option.id === selected.id)) {
      options.unshift(selected)
    }
    customerOptions.value = options
  } catch (error) {
    if (seq !== customerSearchSeq) return
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '顧客の検索に失敗しました'),
      life: 3000,
    })
  } finally {
    if (seq === customerSearchSeq) customerLoading.value = false
  }
}

function onCustomerFilter(event: SelectFilterEvent): void {
  if (customerSearchTimer !== undefined) clearTimeout(customerSearchTimer)
  customerSearchTimer = setTimeout(() => {
    void fetchCustomers(event.value ?? '')
  }, 300)
}

onBeforeUnmount(() => {
  if (customerSearchTimer !== undefined) clearTimeout(customerSearchTimer)
})

// ---- メニュー・スタッフ・ステータス選択肢 ----

function menuLabel(menu: { name: string; duration_minutes: number; price: number }): string {
  return `${menu.name}（${menu.duration_minutes}分・¥${formatNumber(menu.price)}）`
}

const menuOptions = computed(() => {
  const options = props.menus.map((menu) => ({
    label: menuLabel(menu),
    value: menu.id,
    duration_minutes: menu.duration_minutes,
  }))
  const current = props.reservation?.menu
  if (current && !options.some((option) => option.value === current.id)) {
    options.unshift({
      label: `${menuLabel(current)}${current.is_active ? '' : '（無効）'}`,
      value: current.id,
      duration_minutes: current.duration_minutes,
    })
  }
  return options
})

const staffOptions = computed(() =>
  props.staff.map((user) => ({ label: user.name, value: user.id })),
)

const statusOptions = (['reserved', 'visited', 'cancelled', 'no_show'] as ReservationStatus[]).map(
  (status) => ({ label: reservationStatusLabel(status), value: status }),
)

const noMenus = computed(() => !isEdit.value && props.menus.length === 0)

// ---- 終了予定時刻の参考表示 ----

const endPreview = computed(() => {
  const menu = menuOptions.value.find((option) => option.value === form.menuId)
  if (!menu || !form.startAt) return ''
  const end = new Date(form.startAt.getTime() + menu.duration_minutes * 60000)
  return `${String(end.getHours()).padStart(2, '0')}:${String(end.getMinutes()).padStart(2, '0')}`
})

// ---- 初期化 ----

watch(visible, (open) => {
  if (!open) return
  fieldErrors.value = {}
  const reservation = props.reservation
  if (reservation) {
    form.customerId = reservation.customer.id
    form.menuId = reservation.menu.id
    form.userId = reservation.user.id
    form.startAt = new Date(reservation.start_at)
    form.status = reservation.status
    form.note = reservation.note ?? ''
    customerOptions.value = [reservation.customer]
  } else {
    form.customerId = null
    form.menuId = null
    form.userId = props.presetUserId ?? null
    form.startAt = props.presetStartAt ? new Date(props.presetStartAt) : null
    form.status = 'reserved'
    form.note = ''
  }
  void fetchCustomers('')
})

// ---- 保存 ----

function validate(): boolean {
  const errors: Record<string, string> = {}
  if (form.customerId == null) errors['customer_id'] = '顧客を選択してください'
  if (form.menuId == null) errors['menu_id'] = 'メニューを選択してください'
  if (form.userId == null) errors['user_id'] = '担当スタッフを選択してください'
  if (!form.startAt) errors['start_at'] = '開始日時を入力してください'
  if (form.note.length > 2000) errors['note'] = 'メモは2000文字以内で入力してください'
  fieldErrors.value = errors
  return Object.keys(errors).length === 0
}

function buildUpdateInput(reservation: Reservation): ReservationUpdateInput {
  const input: ReservationUpdateInput = {}
  if (form.customerId !== reservation.customer.id && form.customerId != null) {
    input.customer_id = form.customerId
  }
  if (form.menuId !== reservation.menu.id && form.menuId != null) {
    input.menu_id = form.menuId
  }
  if (form.userId !== reservation.user.id && form.userId != null) {
    input.user_id = form.userId
  }
  if (form.startAt && form.startAt.getTime() !== new Date(reservation.start_at).getTime()) {
    input.start_at = toIsoWithOffset(form.startAt)
  }
  if (form.status !== reservation.status) {
    input.status = form.status
  }
  const note = form.note.trim() !== '' ? form.note.trim() : null
  if (note !== (reservation.note ?? null)) {
    input.note = note
  }
  return input
}

async function submit(): Promise<void> {
  if (busy.value || !validate()) return
  saving.value = true
  try {
    const reservation = props.reservation
    if (reservation) {
      const input = buildUpdateInput(reservation)
      if (Object.keys(input).length > 0) {
        await reservationService.update(reservation.id, input)
      }
    } else {
      if (form.customerId == null || form.menuId == null || form.userId == null || !form.startAt) {
        return
      }
      const input: ReservationCreateInput = {
        customer_id: form.customerId,
        menu_id: form.menuId,
        user_id: form.userId,
        start_at: toIsoWithOffset(form.startAt),
        note: form.note.trim() !== '' ? form.note.trim() : null,
      }
      await reservationService.create(input)
    }
    visible.value = false
    emit('saved')
  } catch (error) {
    const errors = extractFieldErrors(error)
    if (Object.keys(errors).length > 0) {
      fieldErrors.value = errors
      toast.add({ severity: 'error', summary: '入力内容をご確認ください', life: 3000 })
    } else {
      toast.add({
        severity: 'error',
        summary: extractErrorMessage(error, '予約の保存に失敗しました'),
        life: 3000,
      })
    }
  } finally {
    saving.value = false
  }
}

function confirmRemove(): void {
  const reservation = props.reservation
  if (!reservation || busy.value) return
  confirm.require({
    message:
      'この予約を削除しますか？誤登録の取り消し用の操作です（キャンセルはステータス変更をご利用ください）。',
    header: '予約の削除',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '削除する',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      deleting.value = true
      try {
        await reservationService.remove(reservation.id)
        visible.value = false
        emit('deleted')
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: extractErrorMessage(error, '予約の削除に失敗しました'),
          life: 3000,
        })
      } finally {
        deleting.value = false
      }
    },
  })
}

function close(): void {
  if (busy.value) return
  visible.value = false
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="isEdit ? '予約の編集' : '新規予約'"
    :style="{ width: '480px', maxWidth: '94vw' }"
    :closable="!busy"
    :draggable="false"
  >
    <div v-if="noMenus" class="no-menu-note">
      <i class="pi pi-info-circle" />
      <div class="no-menu-body">
        <p class="no-menu-text">メニューが登録されていません。先にメニューを登録してください。</p>
        <RouterLink to="/settings/menus" class="no-menu-link">
          <i class="pi pi-arrow-right" />
          メニュー管理へ
        </RouterLink>
      </div>
    </div>

    <div v-else class="dialog-form">
      <div class="field">
        <label class="field-label" for="reservation-customer">
          <i class="pi pi-user" />
          顧客
          <span class="required-badge">必須</span>
        </label>
        <Select
          id="reservation-customer"
          v-model="form.customerId"
          :options="customerOptions"
          option-label="name"
          option-value="id"
          filter
          :filter-fields="['name', 'kana', 'phone']"
          filter-placeholder="氏名・フリガナ・電話番号で検索"
          :loading="customerLoading"
          placeholder="顧客を選択"
          fluid
          :invalid="!!fieldErrors['customer_id']"
          :disabled="busy"
          @filter="onCustomerFilter"
        >
          <template #option="{ option }">
            <div class="customer-option">
              <span class="customer-option-name">{{ option.name }}</span>
              <span class="customer-option-kana">{{ option.kana }}</span>
            </div>
          </template>
        </Select>
        <small v-if="fieldErrors['customer_id']" class="field-error">
          <i class="pi pi-exclamation-circle" /> {{ fieldErrors['customer_id'] }}
        </small>
      </div>

      <div class="field">
        <label class="field-label" for="reservation-menu">
          <i class="pi pi-list"></i>
          メニュー
          <span class="required-badge">必須</span>
        </label>
        <Select
          id="reservation-menu"
          v-model="form.menuId"
          :options="menuOptions"
          option-label="label"
          option-value="value"
          placeholder="メニューを選択"
          fluid
          :invalid="!!fieldErrors['menu_id']"
          :disabled="busy"
        />
        <small v-if="fieldErrors['menu_id']" class="field-error">
          <i class="pi pi-exclamation-circle" /> {{ fieldErrors['menu_id'] }}
        </small>
      </div>

      <div class="field">
        <label class="field-label" for="reservation-user">
          <i class="pi pi-id-card" />
          担当スタッフ
          <span class="required-badge">必須</span>
        </label>
        <Select
          id="reservation-user"
          v-model="form.userId"
          :options="staffOptions"
          option-label="label"
          option-value="value"
          placeholder="担当スタッフを選択"
          fluid
          :invalid="!!fieldErrors['user_id']"
          :disabled="busy"
        />
        <small v-if="fieldErrors['user_id']" class="field-error">
          <i class="pi pi-exclamation-circle" /> {{ fieldErrors['user_id'] }}
        </small>
      </div>

      <div class="field">
        <label class="field-label" for="reservation-start-at">
          <i class="pi pi-clock" />
          開始日時
          <span class="required-badge">必須</span>
        </label>
        <DatePicker
          v-model="form.startAt"
          input-id="reservation-start-at"
          date-format="yy/mm/dd"
          show-time
          hour-format="24"
          :step-minute="5"
          show-icon
          icon-display="input"
          fluid
          placeholder="開始日時を選択"
          :invalid="!!fieldErrors['start_at']"
          :disabled="busy"
        />
        <small v-if="fieldErrors['start_at']" class="field-error">
          <i class="pi pi-exclamation-circle" /> {{ fieldErrors['start_at'] }}
        </small>
        <small v-if="endPreview" class="end-preview">
          <i class="pi pi-flag" />
          終了予定 {{ endPreview }}
        </small>
      </div>

      <div v-if="isEdit" class="field">
        <label class="field-label" for="reservation-status">
          <i class="pi pi-tag" />
          ステータス
        </label>
        <Select
          id="reservation-status"
          v-model="form.status"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          fluid
          :invalid="!!fieldErrors['status']"
          :disabled="busy"
        />
        <small v-if="fieldErrors['status']" class="field-error">
          <i class="pi pi-exclamation-circle" /> {{ fieldErrors['status'] }}
        </small>
      </div>

      <div class="field">
        <label class="field-label" for="reservation-note">
          <i class="pi pi-comment" />
          メモ
        </label>
        <Textarea
          id="reservation-note"
          v-model="form.note"
          auto-resize
          :rows="3"
          :maxlength="2000"
          fluid
          placeholder="施術の希望や連絡事項など"
          :invalid="!!fieldErrors['note']"
          :disabled="busy"
        />
        <small v-if="fieldErrors['note']" class="field-error">
          <i class="pi pi-exclamation-circle" /> {{ fieldErrors['note'] }}
        </small>
      </div>
    </div>

    <template #footer>
      <div class="dialog-footer">
        <Button
          v-if="isEdit"
          label="削除"
          icon="pi pi-trash"
          severity="danger"
          text
          class="footer-delete"
          :loading="deleting"
          :disabled="saving"
          @click="confirmRemove"
        />
        <Button label="キャンセル" severity="secondary" outlined :disabled="busy" @click="close" />
        <Button
          v-if="!noMenus"
          :label="isEdit ? '保存' : '登録'"
          icon="pi pi-check"
          :loading="saving"
          :disabled="deleting"
          @click="submit"
        />
      </div>
    </template>
  </Dialog>
</template>

<style scoped>
.dialog-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.field-label {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--rb-text);
}

.field-label i {
  color: var(--rb-pink);
  font-size: 0.82rem;
}

.required-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.12rem 0.55rem;
  border-radius: 999px;
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
  font-size: 0.68rem;
  font-weight: 700;
  line-height: 1.3;
}

.field-error {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--rb-danger);
  font-size: 0.78rem;
}

.field-error i {
  font-size: 0.75rem;
}

.end-preview {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--rb-text-muted);
  font-size: 0.8rem;
}

.end-preview i {
  font-size: 0.75rem;
  color: var(--rb-beige-deep);
}

.customer-option {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
}

.customer-option-name {
  font-weight: 600;
  font-size: 0.9rem;
}

.customer-option-kana {
  font-size: 0.74rem;
  color: var(--rb-text-muted);
}

.no-menu-note {
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
  padding: 1rem 1.1rem;
  border-radius: var(--rb-radius-md);
  border: 1px dashed var(--rb-pink-soft);
  background: var(--rb-pink-faint);
}

.no-menu-note > i {
  margin-top: 0.15rem;
  color: var(--rb-pink);
}

.no-menu-body {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.no-menu-text {
  margin: 0;
  font-size: 0.9rem;
  color: var(--rb-text);
}

.no-menu-link {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  color: var(--rb-pink-strong);
  font-size: 0.85rem;
  font-weight: 600;
  text-decoration: none;
}

.no-menu-link:hover {
  text-decoration: underline;
}

.dialog-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.6rem;
  width: 100%;
}

.footer-delete {
  margin-right: auto;
}
</style>
