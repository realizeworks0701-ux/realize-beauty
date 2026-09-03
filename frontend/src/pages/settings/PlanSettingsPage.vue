<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { subscriptionService } from '@/services/subscriptionService'
import { useAuthStore } from '@/stores/auth'
import { useFeatures } from '@/composables/useFeatures'
import { extractErrorMessage } from '@/utils/apiError'
import type { PlanCode, SubscriptionOverview } from '@/types'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()
const auth = useAuthStore()
const { featureLabel } = useFeatures()

const overview = ref<SubscriptionOverview | null>(null)
const initialized = ref(false)
const loadError = ref(false)
const submittingPlan = ref<PlanCode | null>(null)
const openingPortal = ref(false)
const updating = ref(false)

const subscription = computed(() => overview.value?.subscription ?? null)
const plans = computed(() => overview.value?.plans ?? [])
const currentPlan = computed(() => overview.value?.plan ?? null)

/**
 * Stripe 上に生きた契約があるか。
 *
 * 「Stripe に紐づいている（is_subscribed）」だけでは足りない。解約が完了した契約も
 * stripe_subscription_id を保持し続けるため、それだけで判定すると解約後に
 * 「プラン変更」しか出せず再契約できなくなる。バックフィルやプロビジョニングで
 * プランだけ入っている（未課金の）状態も Checkout に進ませる必要がある。
 */
const hasLiveContract = computed(
  () => subscription.value?.is_subscribed === true && subscription.value?.is_active === true,
)

/** 契約が無い／終わっている間は、現在のプランも含めて全プランを購入できるようにする */
const canStartCheckout = computed(() => !hasLiveContract.value)

/** 支払いに問題がある状態を、状態ごとに正しい文言で案内する */
const paymentNotice = computed(() => {
  switch (subscription.value?.status) {
    case 'past_due':
      return 'お支払いに失敗しました。登録されているカード情報をご確認ください。再試行中のため、いまはそのままご利用いただけます。'
    case 'unpaid':
      return 'お支払いを確認できなかったため、ご利用を停止しています。お支払い情報を更新すると再開できます。'
    case 'incomplete':
      return 'お支払い手続きが完了していません。カード認証を最後まで進めてください。'
    default:
      return null
  }
})

const busy = computed(() => submittingPlan.value !== null || openingPortal.value || updating.value)

const periodEnd = computed(() => formatDate(subscription.value?.current_period_end ?? null))

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Date(value).toLocaleDateString('ja-JP', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

function formatPrice(value: number): string {
  return `${value.toLocaleString('ja-JP')}円`
}

async function fetchOverview(): Promise<void> {
  loadError.value = false
  try {
    overview.value = await subscriptionService.get()
  } catch {
    loadError.value = true
  } finally {
    initialized.value = true
  }
}

/**
 * Checkout / カスタマーポータルはいずれも Stripe がホストする画面へ遷移する。
 * カード情報の入力は Stripe 側で完結し、アプリには一切届かない。
 */
function redirectTo(url: string): void {
  window.location.assign(url)
}

async function startCheckout(plan: PlanCode): Promise<void> {
  submittingPlan.value = plan
  try {
    redirectTo(await subscriptionService.checkout(plan))
  } catch (error) {
    submittingPlan.value = null
    toast.add({
      severity: 'error',
      summary: 'お手続きを開始できませんでした',
      detail: extractErrorMessage(error, 'しばらくしてからもう一度お試しください'),
      life: 5000,
    })
  }
}

/** 変更後に使えなくなる機能。ダウングレードで何を失うかを事前に伝える */
function featuresLostBy(plan: PlanCode): string[] {
  const next = plans.value.find((item) => item.code === plan)?.features ?? []
  const current = plans.value.find((item) => item.code === currentPlan.value)?.features ?? []
  return current.filter((feature) => !next.includes(feature)).map(featureLabel)
}

function confirmChangePlan(plan: PlanCode, label: string): void {
  const lost = featuresLostBy(plan)
  const warning =
    lost.length > 0 ? `\n\n変更後は${lost.join('・')}をご利用いただけなくなります。` : ''

  confirm.require({
    header: 'プラン変更',
    message: `${label}プランに変更します。差額はStripeが日割りで精算します。${warning}`,
    icon: 'pi pi-arrow-right-arrow-left',
    acceptLabel: '変更する',
    rejectLabel: 'キャンセル',
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      submittingPlan.value = plan
      try {
        await subscriptionService.changePlan(plan)
        await reload()
        toast.add({ severity: 'success', summary: `${label}プランに変更しました`, life: 3000 })
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: 'プランを変更できませんでした',
          detail: extractErrorMessage(error, 'しばらくしてからもう一度お試しください'),
          life: 5000,
        })
      } finally {
        submittingPlan.value = null
      }
    },
  })
}

function confirmCancel(): void {
  confirm.require({
    header: '解約',
    message: `現在の契約期間（${periodEnd.value}まで）はそのままご利用いただけます。期間終了後に利用停止となります。顧客・カルテ・写真のデータは削除されません。`,
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '解約する',
    rejectLabel: 'やめる',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      updating.value = true
      try {
        await subscriptionService.cancel()
        await reload()
        toast.add({ severity: 'success', summary: '解約を受け付けました', life: 3000 })
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: '解約できませんでした',
          detail: extractErrorMessage(error, 'しばらくしてからもう一度お試しください'),
          life: 5000,
        })
      } finally {
        updating.value = false
      }
    },
  })
}

async function resume(): Promise<void> {
  updating.value = true
  try {
    await subscriptionService.resume()
    await reload()
    toast.add({ severity: 'success', summary: '解約を取り消しました', life: 3000 })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: '解約を取り消せませんでした',
      detail: extractErrorMessage(error, 'しばらくしてからもう一度お試しください'),
      life: 5000,
    })
  } finally {
    updating.value = false
  }
}

async function openPortal(): Promise<void> {
  openingPortal.value = true
  try {
    redirectTo(await subscriptionService.portal())
  } catch (error) {
    openingPortal.value = false
    toast.add({
      severity: 'error',
      summary: 'お支払い情報の画面を開けませんでした',
      detail: extractErrorMessage(error, 'しばらくしてからもう一度お試しください'),
      life: 5000,
    })
  }
}

/**
 * 契約が変わるとナビや画面の出し分けも変わるため、ユーザー情報ごと取り直す。
 * 機能フラグの再取得に失敗してもこの画面自体は表示できるので、失敗させない。
 */
async function reload(): Promise<void> {
  await Promise.all([fetchOverview(), auth.refreshUser().catch(() => undefined)])
}

// Stripe へ遷移したままブラウザバックで戻る（bfcache 復元）と、
// 送信中フラグが立ったままボタンが押せなくなる。復帰時に必ず解除する。
function clearPending(): void {
  submittingPlan.value = null
  openingPortal.value = false
}

onMounted(() => {
  window.addEventListener('pageshow', clearPending)
})

onBeforeUnmount(() => {
  window.removeEventListener('pageshow', clearPending)
})

onMounted(async () => {
  // Checkout から戻ったら、Webhook を待たずに結果を取り込む。
  // 待つあいだ「未契約」に見えてしまい、そこで2本目を契約されると二重課金になる。
  const sessionId = route.query.session_id
  if (route.query.checkout === 'success' && typeof sessionId === 'string') {
    try {
      await subscriptionService.syncCheckout(sessionId)
    } catch {
      // 取り込めなくても Webhook 側で収束する。画面は通常どおり読み込む
    }
  }

  if (route.query.checkout === 'success') {
    toast.add({
      severity: 'success',
      summary: 'お手続きが完了しました',
      detail: '反映まで少し時間がかかる場合があります',
      life: 5000,
    })
  } else if (route.query.checkout === 'cancel') {
    toast.add({ severity: 'info', summary: 'お手続きを中断しました', life: 3000 })
  }

  // 戻り先のクエリを落としておく。残したままだとリロードのたびに同じ通知が出る
  if (route.query.checkout !== undefined) {
    void router.replace({ query: {} })
  }

  await reload()
})
</script>

<template>
  <div class="rb-page">
    <PageHeader
      title="プラン・お支払い"
      icon="pi pi-credit-card"
      subtitle="ご契約中のプランの確認と変更ができます"
    />

    <div class="plan-body">
      <template v-if="!initialized">
        <Skeleton height="180px" border-radius="20px" />
        <Skeleton height="320px" border-radius="20px" />
      </template>

      <GlassCard v-else-if="loadError">
        <div class="load-error">
          <p>契約情報を読み込めませんでした。</p>
          <Button label="再読み込み" icon="pi pi-refresh" outlined @click="fetchOverview" />
        </div>
      </GlassCard>

      <template v-else>
        <Message
          v-if="paymentNotice"
          :severity="subscription?.status === 'unpaid' ? 'error' : 'warn'"
          :closable="false"
          class="alert"
        >
          {{ paymentNotice }}
        </Message>

        <Message
          v-if="subscription?.cancel_at_period_end"
          severity="info"
          :closable="false"
          class="alert"
        >
          解約を受け付けています。{{ periodEnd }}までご利用いただけます。
        </Message>

        <GlassCard title="現在のプラン" icon="pi pi-star">
          <div v-if="subscription" class="current">
            <div class="current-head">
              <span class="current-plan">{{ subscription.plan_label }}</span>
              <Tag
                :value="subscription.status_label"
                :severity="subscription.is_active ? 'success' : 'danger'"
              />
            </div>
            <p class="current-price">{{ formatPrice(subscription.monthly_price) }} / 月</p>
            <dl class="current-meta">
              <div>
                <dt>次回更新日</dt>
                <dd>{{ periodEnd }}</dd>
              </div>
              <div v-if="subscription.trial_ends_at">
                <dt>トライアル終了</dt>
                <dd>{{ formatDate(subscription.trial_ends_at) }}</dd>
              </div>
            </dl>
            <div class="current-actions">
              <Button
                v-if="subscription.has_payment_method"
                label="お支払い情報の管理"
                icon="pi pi-external-link"
                severity="secondary"
                outlined
                :loading="openingPortal"
                :disabled="busy"
                @click="openPortal"
              />
              <Button
                v-if="subscription.cancel_at_period_end"
                label="解約を取り消す"
                icon="pi pi-undo"
                outlined
                :loading="updating"
                :disabled="busy"
                @click="resume"
              />
              <Button
                v-else-if="hasLiveContract"
                label="解約する"
                icon="pi pi-times"
                severity="danger"
                text
                :disabled="busy"
                @click="confirmCancel"
              />
            </div>
          </div>
          <p v-else class="no-plan">
            ご契約がありません。下のプランからお選びいただくとご利用を開始できます。
          </p>
          <p v-if="subscription && !subscription.is_active" class="no-plan">
            現在ご利用いただけない状態です。プランを選び直すと再開できます。顧客・カルテ・写真のデータは保持されています。
          </p>
        </GlassCard>

        <GlassCard title="プラン一覧" icon="pi pi-th-large">
          <div class="plan-grid">
            <div
              v-for="item in plans"
              :key="item.code"
              class="plan-card"
              :class="{ active: item.code === currentPlan }"
            >
              <div class="plan-card-head">
                <span class="plan-name">{{ item.label }}</span>
                <Tag v-if="item.code === currentPlan" value="ご契約中" severity="success" />
              </div>
              <p class="plan-price">
                <strong>{{ item.monthly_price.toLocaleString('ja-JP') }}</strong
                >円 / 月
              </p>
              <ul class="plan-features">
                <li v-for="feature in item.features" :key="feature">
                  <i class="pi pi-check" />
                  <span>{{ featureLabel(feature) }}</span>
                </li>
              </ul>
              <Button
                v-if="canStartCheckout"
                label="このプランで開始"
                :disabled="busy || !item.is_purchasable"
                :loading="submittingPlan === item.code"
                @click="startCheckout(item.code)"
              />
              <Button
                v-else-if="item.code !== currentPlan"
                label="このプランに変更"
                :disabled="busy || !item.is_purchasable"
                :loading="submittingPlan === item.code"
                @click="confirmChangePlan(item.code, item.label)"
              />
              <p v-else class="plan-current-note">現在ご利用中のプランです</p>
              <p v-if="!item.is_purchasable" class="plan-unavailable">
                このプランは現在お申し込みいただけません
              </p>
            </div>
          </div>
          <p class="plan-note">
            プラン変更の差額はStripeが日割りで精算します。カード情報はStripeの画面でのみ入力され、
            Realize Beautyでは保持しません。
          </p>
        </GlassCard>
      </template>
    </div>
  </div>
</template>

<style scoped>
.plan-body {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  max-width: 960px;
}

.alert {
  margin: 0;
}

.current {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.current-head {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.current-plan {
  font-family: var(--rb-font-display);
  font-size: 1.6rem;
  font-weight: 700;
  color: var(--rb-text);
}

.current-price {
  margin: 0;
  color: var(--rb-text-muted);
}

.current-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 1.5rem;
  margin: 0;
}

.current-meta dt {
  font-size: 0.8rem;
  color: var(--rb-text-muted);
}

.current-meta dd {
  margin: 0.15rem 0 0;
  font-weight: 600;
}

.current-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.35rem;
}

.no-plan {
  margin: 0;
  color: var(--rb-text-muted);
}

.plan-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
}

.plan-card {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  padding: 1.1rem;
  border: 1px solid var(--rb-border);
  border-radius: var(--rb-radius-md);
  background: var(--rb-surface-subtle);
}

.plan-card.active {
  border-color: var(--rb-primary);
  background: var(--rb-primary-faint);
}

.plan-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.plan-name {
  font-family: var(--rb-font-display);
  font-size: 1.15rem;
  font-weight: 700;
}

.plan-price {
  margin: 0;
  color: var(--rb-text-muted);
  font-size: 0.9rem;
}

.plan-price strong {
  font-size: 1.5rem;
  color: var(--rb-text);
}

.plan-features {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.88rem;
}

.plan-features li {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}

.plan-features i {
  color: var(--rb-primary);
  font-size: 0.75rem;
}

.plan-current-note,
.plan-unavailable {
  margin: 0;
  font-size: 0.82rem;
  color: var(--rb-text-muted);
  text-align: center;
}

.plan-note {
  margin: 1rem 0 0;
  font-size: 0.82rem;
  line-height: 1.7;
  color: var(--rb-text-muted);
}

.load-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 1.5rem 1rem;
  color: var(--rb-text-muted);
}

@media (max-width: 899px) {
  .plan-grid {
    grid-template-columns: 1fr;
  }
}
</style>
