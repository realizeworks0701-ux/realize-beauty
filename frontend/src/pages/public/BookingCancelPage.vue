<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { AxiosError } from 'axios'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import EmptyState from '@/components/common/EmptyState.vue'
import PublicLayout from '@/layouts/PublicLayout.vue'
import { publicBookingService } from '@/services/publicBookingService'
import { extractErrorMessage } from '@/utils/apiError'
import { formatDateTimeRange } from '@/utils/publicBooking'
import type { PublicBooking } from '@/types'

const THROTTLE_MESSAGE = 'アクセスが集中しています。しばらく時間をおいて再度お試しください'

const route = useRoute()
const toast = useToast()
const confirm = useConfirm()

const bookingToken = computed(() => String(route.params.token ?? ''))

type LoadState = 'loading' | 'ready' | 'notFound' | 'error'
const loadState = ref<LoadState>('loading')
const loadErrorMessage = ref('')
const booking = ref<PublicBooking | null>(null)
const cancelled = ref(false)
const cancelling = ref(false)
const cancelError = ref('')

async function fetchBooking(): Promise<void> {
  loadState.value = 'loading'
  try {
    booking.value = await publicBookingService.getBooking(bookingToken.value)
    loadState.value = 'ready'
  } catch (error) {
    const status = error instanceof AxiosError ? error.response?.status : undefined
    if (status === 404) {
      loadState.value = 'notFound'
      return
    }
    loadErrorMessage.value = status === 429 ? THROTTLE_MESSAGE : 'ページを読み込めませんでした'
    loadState.value = 'error'
  }
}

onMounted(() => {
  void fetchBooking()
})

/** キャンセル不可時の案内文（キャンセル済み or 開始時刻を過ぎている） */
const cannotCancelMessage = computed(() => {
  if (!booking.value || booking.value.can_cancel) return ''
  if (booking.value.status === 'cancelled') {
    return 'この予約はすでにキャンセルされています'
  }
  return '開始時刻を過ぎた予約はキャンセルできません。サロンへ直接ご連絡ください'
})

function confirmCancel(): void {
  confirm.require({
    message: 'この予約をキャンセルしますか？',
    header: '予約のキャンセル',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: 'キャンセルする',
    rejectLabel: '戻る',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: () => {
      void executeCancel()
    },
  })
}

async function executeCancel(): Promise<void> {
  if (cancelling.value) return
  cancelling.value = true
  cancelError.value = ''
  try {
    booking.value = await publicBookingService.cancelBooking(bookingToken.value)
    cancelled.value = true
  } catch (error) {
    const status = error instanceof AxiosError ? error.response?.status : undefined
    if (status === 409) {
      // 同時実行等でキャンセル不可になった場合は予約概要を再取得して状態表示を更新する
      cancelError.value = 'この予約はキャンセルできません'
      await fetchBooking()
    } else if (status === 404) {
      loadState.value = 'notFound'
    } else {
      const summary =
        status === 429 ? THROTTLE_MESSAGE : extractErrorMessage(error, 'キャンセルに失敗しました')
      toast.add({ severity: 'error', summary, life: 4000 })
    }
  } finally {
    cancelling.value = false
  }
}
</script>

<template>
  <PublicLayout :salon-name="booking?.salon_name ?? null">
    <template v-if="loadState === 'loading'">
      <Skeleton height="54px" border-radius="20px" />
      <Skeleton height="260px" border-radius="20px" />
    </template>

    <div v-else-if="loadState === 'notFound'" class="glass-card state-card">
      <EmptyState
        icon="pi pi-search"
        title="予約が見つかりません"
        description="URLをお確かめのうえ、再度アクセスしてください"
      />
    </div>

    <div v-else-if="loadState === 'error'" class="glass-card state-card load-error">
      <i class="pi pi-exclamation-triangle" />
      <p>{{ loadErrorMessage }}</p>
      <Button label="再読み込み" icon="pi pi-refresh" outlined @click="fetchBooking" />
    </div>

    <div v-else-if="booking" class="glass-card booking-card">
      <template v-if="cancelled">
        <div class="result-head">
          <span class="result-icon"><i class="pi pi-check" /></span>
          <h2 class="result-title">予約をキャンセルしました</h2>
        </div>
      </template>
      <h2 v-else class="card-title">ご予約内容</h2>

      <dl class="summary">
        <div class="summary-row">
          <dt>サロン</dt>
          <dd>{{ booking.salon_name }}</dd>
        </div>
        <div class="summary-row">
          <dt>メニュー</dt>
          <dd>{{ booking.menu_name }}</dd>
        </div>
        <div class="summary-row">
          <dt>担当</dt>
          <dd>{{ booking.staff_name }}</dd>
        </div>
        <div class="summary-row">
          <dt>日時</dt>
          <dd>{{ formatDateTimeRange(booking.start_at, booking.end_at) }}</dd>
        </div>
      </dl>

      <template v-if="!cancelled">
        <p v-if="cancelError" class="cancel-error" role="alert">
          <i class="pi pi-exclamation-circle" />
          {{ cancelError }}
        </p>
        <Button
          v-if="booking.can_cancel"
          label="予約をキャンセルする"
          icon="pi pi-times"
          severity="danger"
          fluid
          :loading="cancelling"
          @click="confirmCancel"
        />
        <p v-else class="cannot-cancel">{{ cannotCancelMessage }}</p>
      </template>
    </div>
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

.booking-card {
  padding: 1.5rem 1.3rem;
}

.card-title {
  margin: 0 0 1.1rem;
  font-size: 1.05rem;
}

.result-head {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  margin-bottom: 1.1rem;
}

.result-icon {
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

.result-title {
  margin: 0;
  font-size: 1.1rem;
}

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

.cancel-error {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
  margin: 0 0 0.9rem;
  padding: 0.7rem 0.9rem;
  border-radius: var(--rb-radius-sm);
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
  font-size: 0.85rem;
  font-weight: 600;
}

.cancel-error i {
  margin-top: 0.15rem;
}

.cannot-cancel {
  margin: 0;
  padding: 0.8rem 0.9rem;
  border-radius: var(--rb-radius-sm);
  background: var(--rb-beige-soft);
  color: var(--rb-text);
  font-size: 0.88rem;
}
</style>
