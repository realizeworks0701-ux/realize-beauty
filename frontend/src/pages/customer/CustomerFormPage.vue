<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import Textarea from 'primevue/textarea'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { customerService } from '@/services/customerService'
import { extractErrorMessage, extractFieldErrors } from '@/utils/apiError'
import { genderLabel } from '@/utils/format'
import type { CustomerInput, Gender } from '@/types'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const isEdit = computed(() => route.name === 'customer-edit')
const customerId = computed(() => Number(route.params.id))

const loading = ref(isEdit.value)
const saving = ref(false)
const fieldErrors = ref<Record<string, string>>({})

const form = reactive<{
  name: string
  kana: string
  gender: Gender
  birthday: Date | null
  phone: string
  email: string
  memo: string
}>({
  name: '',
  kana: '',
  gender: 0,
  birthday: null,
  phone: '',
  email: '',
  memo: '',
})

const genderOptions: { label: string; value: Gender }[] = [
  { label: genderLabel(0), value: 0 },
  { label: genderLabel(1), value: 1 },
  { label: genderLabel(2), value: 2 },
  { label: genderLabel(9), value: 9 },
]

onMounted(async () => {
  if (!isEdit.value) return
  try {
    const customer = await customerService.get(customerId.value)
    form.name = customer.name
    form.kana = customer.kana
    form.gender = customer.gender ?? 0
    // 日付のみの文字列を UTC でなくローカル日付として解釈させる（タイムゾーンによる前日ズレ防止）
    form.birthday = customer.birthday ? new Date(`${customer.birthday}T00:00:00`) : null
    form.phone = customer.phone ?? ''
    form.email = customer.email ?? ''
    form.memo = customer.memo ?? ''
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '顧客情報の取得に失敗しました'),
      life: 4000,
    })
    router.push({ name: 'customer-list' })
  } finally {
    loading.value = false
  }
})

function onBirthdayChange(value: Date | Date[] | (Date | null)[] | null | undefined): void {
  form.birthday = value instanceof Date ? value : null
}

function toBirthdayString(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function validate(): boolean {
  const errors: Record<string, string> = {}
  if (form.name.trim() === '') errors.name = '氏名を入力してください'
  if (form.kana.trim() === '') errors.kana = 'フリガナを入力してください'
  fieldErrors.value = errors
  return Object.keys(errors).length === 0
}

async function onSubmit(): Promise<void> {
  if (!validate()) return

  saving.value = true
  try {
    const input: CustomerInput = {
      name: form.name.trim(),
      kana: form.kana.trim(),
      gender: form.gender,
      birthday: form.birthday ? toBirthdayString(form.birthday) : null,
      phone: form.phone.trim() !== '' ? form.phone.trim() : null,
      email: form.email.trim() !== '' ? form.email.trim() : null,
      memo: form.memo.trim() !== '' ? form.memo.trim() : null,
    }
    const saved = isEdit.value
      ? await customerService.update(customerId.value, input)
      : await customerService.create(input)

    toast.add({
      severity: 'success',
      summary: isEdit.value ? '顧客情報を更新しました' : '顧客を登録しました',
      life: 3000,
    })
    router.push({ name: 'customer-detail', params: { id: saved.id } })
  } catch (error) {
    const errors = extractFieldErrors(error)
    if (Object.keys(errors).length > 0) {
      fieldErrors.value = errors
      toast.add({
        severity: 'error',
        summary: '入力内容を確認してください',
        life: 4000,
      })
    } else {
      toast.add({
        severity: 'error',
        summary: extractErrorMessage(error, '保存に失敗しました'),
        life: 4000,
      })
    }
  } finally {
    saving.value = false
  }
}

function onCancel(): void {
  router.back()
}
</script>

<template>
  <div class="rb-page">
    <PageHeader
      :title="isEdit ? '顧客編集' : '顧客登録'"
      :icon="isEdit ? 'pi pi-user-edit' : 'pi pi-user-plus'"
      :subtitle="isEdit ? '顧客情報を編集して保存できます' : '新しい顧客情報を登録します'"
    />

    <GlassCard class="form-card" title="基本情報" icon="pi pi-id-card">
      <template v-if="loading">
        <div class="form-grid">
          <div v-for="n in 6" :key="n" class="field">
            <Skeleton width="35%" height="0.95rem" border-radius="6px" />
            <Skeleton height="2.6rem" border-radius="12px" />
          </div>
          <div class="field field-full">
            <Skeleton width="20%" height="0.95rem" border-radius="6px" />
            <Skeleton height="6.5rem" border-radius="12px" />
          </div>
        </div>
      </template>

      <form v-else novalidate @submit.prevent="onSubmit">
        <div class="form-grid">
          <div class="field">
            <label class="field-label" for="customer-name">
              <i class="pi pi-user" />
              氏名
              <span class="required-badge">必須</span>
            </label>
            <InputText
              id="customer-name"
              v-model="form.name"
              fluid
              :invalid="!!fieldErrors.name"
              placeholder="例）山田 花子"
              autocomplete="off"
            />
            <small v-if="fieldErrors.name" class="field-error">
              <i class="pi pi-exclamation-circle" /> {{ fieldErrors.name }}
            </small>
          </div>

          <div class="field">
            <label class="field-label" for="customer-kana">
              <i class="pi pi-language" />
              フリガナ
              <span class="required-badge">必須</span>
            </label>
            <InputText
              id="customer-kana"
              v-model="form.kana"
              fluid
              :invalid="!!fieldErrors.kana"
              placeholder="例）ヤマダ ハナコ"
              autocomplete="off"
            />
            <small v-if="fieldErrors.kana" class="field-error">
              <i class="pi pi-exclamation-circle" /> {{ fieldErrors.kana }}
            </small>
          </div>

          <div class="field">
            <label class="field-label" for="customer-gender">
              <i class="pi pi-heart" />
              性別
            </label>
            <Select
              id="customer-gender"
              v-model="form.gender"
              :options="genderOptions"
              option-label="label"
              option-value="value"
              fluid
              :invalid="!!fieldErrors.gender"
            />
            <small v-if="fieldErrors.gender" class="field-error">
              <i class="pi pi-exclamation-circle" /> {{ fieldErrors.gender }}
            </small>
          </div>

          <div class="field">
            <label class="field-label" for="customer-birthday">
              <i class="pi pi-gift" />
              生年月日
            </label>
            <DatePicker
              input-id="customer-birthday"
              :model-value="form.birthday"
              date-format="yy/mm/dd"
              show-icon
              icon-display="input"
              fluid
              :invalid="!!fieldErrors.birthday"
              placeholder="例）1990/01/01"
              @update:model-value="onBirthdayChange"
            />
            <small v-if="fieldErrors.birthday" class="field-error">
              <i class="pi pi-exclamation-circle" /> {{ fieldErrors.birthday }}
            </small>
          </div>

          <div class="field">
            <label class="field-label" for="customer-phone">
              <i class="pi pi-phone" />
              電話番号
            </label>
            <InputText
              id="customer-phone"
              v-model="form.phone"
              fluid
              :invalid="!!fieldErrors.phone"
              placeholder="例）090-1234-5678"
              autocomplete="off"
            />
            <small v-if="fieldErrors.phone" class="field-error">
              <i class="pi pi-exclamation-circle" /> {{ fieldErrors.phone }}
            </small>
          </div>

          <div class="field">
            <label class="field-label" for="customer-email">
              <i class="pi pi-envelope" />
              メールアドレス
            </label>
            <InputText
              id="customer-email"
              v-model="form.email"
              type="email"
              fluid
              :invalid="!!fieldErrors.email"
              placeholder="例）hanako@example.com"
              autocomplete="off"
            />
            <small v-if="fieldErrors.email" class="field-error">
              <i class="pi pi-exclamation-circle" /> {{ fieldErrors.email }}
            </small>
          </div>

          <div class="field field-full">
            <label class="field-label" for="customer-memo">
              <i class="pi pi-comment" />
              メモ
            </label>
            <Textarea
              id="customer-memo"
              v-model="form.memo"
              auto-resize
              :rows="4"
              fluid
              :invalid="!!fieldErrors.memo"
              placeholder="施術の好みやアレルギーなど、気づいたことを記録できます"
            />
            <small v-if="fieldErrors.memo" class="field-error">
              <i class="pi pi-exclamation-circle" /> {{ fieldErrors.memo }}
            </small>
          </div>
        </div>

        <div class="form-actions">
          <Button
            type="button"
            label="キャンセル"
            icon="pi pi-times"
            severity="secondary"
            outlined
            :disabled="saving"
            @click="onCancel"
          />
          <Button type="submit" label="保存" icon="pi pi-check" :loading="saving" />
        </div>
      </form>
    </GlassCard>
  </div>
</template>

<style scoped>
.form-card {
  max-width: 720px;
  width: 100%;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.1rem 1.25rem;
}

@media (min-width: 640px) {
  .form-grid {
    grid-template-columns: 1fr 1fr;
  }
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  min-width: 0;
}

.field-full {
  grid-column: 1 / -1;
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
  color: var(--rb-pink-strong);
  font-size: 0.78rem;
}

.field-error i {
  font-size: 0.75rem;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
  margin-top: 1.5rem;
  padding-top: 1.2rem;
  border-top: 1px solid var(--rb-border);
}
</style>
