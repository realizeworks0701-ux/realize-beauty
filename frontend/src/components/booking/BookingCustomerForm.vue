<script setup lang="ts">
import { watch } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { genderLabel } from '@/utils/format'
import { BOOKING_NOTE_MAX_LENGTH } from '@/utils/publicBooking'
import type { BookingCustomerErrors, BookingCustomerFormState } from '@/utils/publicBooking'
import type { Gender } from '@/types'

defineProps<{
  errors: BookingCustomerErrors
  submitting: boolean
}>()

const emit = defineEmits<{ submit: [] }>()

const form = defineModel<BookingCustomerFormState>({ required: true })

const genderOptions: { label: string; value: Gender }[] = [
  { label: genderLabel(0), value: 0 },
  { label: genderLabel(1), value: 1 },
  { label: genderLabel(2), value: 2 },
  { label: genderLabel(9), value: 9 },
]

const today = new Date()

// チェックを外したら追加項目を破棄する（確認画面と送信内容の不一致を防ぐ）
watch(
  () => form.value.isFirstVisit,
  (isFirstVisit) => {
    if (isFirstVisit) return
    form.value.birthday = null
    form.value.gender = null
    form.value.email = ''
  },
)
</script>

<template>
  <form class="customer-form" novalidate @submit.prevent="emit('submit')">
    <div class="field">
      <label class="field-label" for="booking-name">お名前</label>
      <InputText
        id="booking-name"
        v-model="form.name"
        autocomplete="name"
        placeholder="山田 花子"
        maxlength="100"
        fluid
        :invalid="errors.name !== ''"
      />
      <small v-if="errors.name" class="field-error">
        <i class="pi pi-exclamation-circle" />
        {{ errors.name }}
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
        :invalid="errors.kana !== ''"
      />
      <small v-if="errors.kana" class="field-error">
        <i class="pi pi-exclamation-circle" />
        {{ errors.kana }}
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
        :invalid="errors.phone !== ''"
      />
      <small v-if="errors.phone" class="field-error">
        <i class="pi pi-exclamation-circle" />
        {{ errors.phone }}
      </small>
    </div>

    <div class="first-visit">
      <div class="first-visit-head">
        <Checkbox v-model="form.isFirstVisit" input-id="booking-first-visit" binary />
        <label for="booking-first-visit">
          <span class="first-visit-title">新規ご来店</span>
          <span class="first-visit-note">当サロンのご利用が初めての方</span>
        </label>
      </div>

      <div v-if="form.isFirstVisit" class="first-visit-body">
        <div class="field">
          <label class="field-label" for="booking-birthday">生年月日</label>
          <DatePicker
            v-model="form.birthday"
            input-id="booking-birthday"
            date-format="yy/mm/dd"
            :max-date="today"
            show-icon
            icon-display="input"
            fluid
            placeholder="1995/04/01"
            :invalid="errors.birthday !== ''"
          />
          <small v-if="errors.birthday" class="field-error">
            <i class="pi pi-exclamation-circle" />
            {{ errors.birthday }}
          </small>
        </div>

        <div class="field">
          <label class="field-label" for="booking-gender">性別</label>
          <!-- id はルート要素の div に落ちるため label の for が結び付かない。
               生年月日の DatePicker と同じく、内部要素に付く label-id を使う -->
          <Select
            label-id="booking-gender"
            v-model="form.gender"
            :options="genderOptions"
            option-label="label"
            option-value="value"
            placeholder="未設定"
            show-clear
            fluid
            :invalid="errors.gender !== ''"
          />
          <small v-if="errors.gender" class="field-error">
            <i class="pi pi-exclamation-circle" />
            {{ errors.gender }}
          </small>
        </div>

        <div class="field">
          <label class="field-label" for="booking-email">メールアドレス</label>
          <InputText
            id="booking-email"
            v-model="form.email"
            type="email"
            autocomplete="email"
            placeholder="hanako@example.com"
            maxlength="255"
            fluid
            :invalid="errors.email !== ''"
          />
          <small v-if="errors.email" class="field-error">
            <i class="pi pi-exclamation-circle" />
            {{ errors.email }}
          </small>
        </div>
      </div>
    </div>

    <div class="field">
      <label class="field-label" for="booking-note">ご要望・気になること</label>
      <Textarea
        id="booking-note"
        v-model="form.note"
        auto-resize
        :rows="3"
        :maxlength="BOOKING_NOTE_MAX_LENGTH"
        fluid
        placeholder="施術のご希望やお困りごとがあればご記入ください"
        :invalid="errors.note !== ''"
      />
      <small v-if="errors.note" class="field-error">
        <i class="pi pi-exclamation-circle" />
        {{ errors.note }}
      </small>
    </div>

    <Button
      type="submit"
      label="次へ"
      icon="pi pi-arrow-right"
      icon-pos="right"
      fluid
      :disabled="submitting"
    />
  </form>
</template>

<style scoped>
.customer-form {
  display: flex;
  flex-direction: column;
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
  margin: 0;
}

.field-error {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.76rem;
  color: var(--rb-pink-strong);
}

.first-visit {
  margin-bottom: 1rem;
  padding: 0.9rem 1rem;
  border-radius: var(--rb-radius-md);
  border: 1px dashed var(--rb-pink-soft);
  background: var(--rb-pink-faint);
}

.first-visit-head {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
}

.first-visit-head label {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  cursor: pointer;
}

.first-visit-title {
  font-size: 0.9rem;
  font-weight: 700;
  color: var(--rb-text);
}

.first-visit-note {
  font-size: 0.76rem;
  color: var(--rb-text-muted);
}

.first-visit-body {
  margin-top: 0.9rem;
}

.first-visit-body .field:last-child {
  margin-bottom: 0;
}
</style>
