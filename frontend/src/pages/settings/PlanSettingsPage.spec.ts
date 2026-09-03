import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'
import PlanSettingsPage from './PlanSettingsPage.vue'
import { useAuthStore } from '@/stores/auth'
import { buildTestUser } from '@/test-support/user'
import type { PlanCode, Subscription, SubscriptionOverview } from '@/types'

const getMock = vi.hoisted(() => vi.fn())
const checkoutMock = vi.hoisted(() => vi.fn())
const portalMock = vi.hoisted(() => vi.fn())
const cancelMock = vi.hoisted(() => vi.fn())
const syncCheckoutMock = vi.hoisted(() => vi.fn())

// 確認ダイアログ本体は App.vue 側に置かれ、この単体テストでは描画されない。
// require に渡る内容を直接検査する。
const requireMock = vi.hoisted(() => vi.fn())
vi.mock('primevue/useconfirm', () => ({ useConfirm: () => ({ require: requireMock }) }))

// 契約変更後にユーザー情報を取り直すため、認証サービスも差し替える
vi.mock('@/services/authService', () => ({
  authService: {
    me: vi.fn().mockResolvedValue(null),
    login: vi.fn(),
    logout: vi.fn(),
  },
}))

vi.mock('@/services/subscriptionService', () => ({
  subscriptionService: {
    get: getMock,
    checkout: checkoutMock,
    portal: portalMock,
    cancel: cancelMock,
    changePlan: vi.fn(),
    resume: vi.fn(),
    syncCheckout: syncCheckoutMock,
  },
}))

function memoryStorage(): Storage {
  const map = new Map<string, string>()
  return {
    get length() {
      return map.size
    },
    key: (i: number) => [...map.keys()][i] ?? null,
    getItem: (k: string) => map.get(k) ?? null,
    setItem: (k: string, v: string) => void map.set(k, v),
    removeItem: (k: string) => void map.delete(k),
    clear: () => map.clear(),
  }
}

function buildSubscription(overrides: Partial<Subscription> = {}): Subscription {
  return {
    plan: 'standard',
    plan_label: 'Standard',
    monthly_price: 1980,
    status: 'active',
    status_label: '利用中',
    is_active: true,
    needs_payment_attention: false,
    cancel_at_period_end: false,
    current_period_start: '2026-09-01T00:00:00+00:00',
    current_period_end: '2026-10-01T00:00:00+00:00',
    canceled_at: null,
    ended_at: null,
    trial_ends_at: null,
    has_payment_method: true,
    is_subscribed: true,
    ...overrides,
  }
}

function buildOverview(overrides: Partial<SubscriptionOverview> = {}): SubscriptionOverview {
  return {
    subscription: buildSubscription(),
    plan: 'standard',
    features: buildTestUser('standard').features,
    plans: [
      {
        code: 'lite',
        label: 'Lite',
        monthly_price: 980,
        features: ['customer', 'medical_record', 'photo'],
        is_purchasable: true,
      },
      {
        code: 'standard',
        label: 'Standard',
        monthly_price: 1980,
        features: ['customer', 'medical_record', 'photo', 'reservation', 'google_calendar', 'line'],
        is_purchasable: true,
      },
      {
        code: 'pro',
        label: 'Pro',
        monthly_price: 3980,
        features: [
          'customer',
          'medical_record',
          'photo',
          'reservation',
          'google_calendar',
          'line',
          'ai_summary',
          'analytics',
        ],
        is_purchasable: true,
      },
    ],
    ...overrides,
  }
}

async function mountPage(plan: PlanCode | null = 'standard') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/settings/plan', component: { template: '<div />' } }],
  })
  await router.push('/settings/plan')
  await router.isReady()
  useAuthStore().user = buildTestUser(plan)

  const wrapper = mount(PlanSettingsPage, {
    global: { plugins: [router, PrimeVue, ToastService, ConfirmationService] },
  })
  await flushPromises()
  return wrapper
}

describe('PlanSettingsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.stubGlobal('localStorage', memoryStorage())
    setActivePinia(createPinia())
    getMock.mockResolvedValue(buildOverview())
    checkoutMock.mockResolvedValue('https://checkout.stripe.com/c/pay/cs_1')
    syncCheckoutMock.mockResolvedValue(null)
    portalMock.mockResolvedValue('https://billing.stripe.com/p/session_1')
  })

  it('現在のプランと料金と次回更新日を表示する', async () => {
    const wrapper = await mountPage()
    const text = wrapper.text()

    expect(text).toContain('Standard')
    expect(text).toContain('1,980円')
    expect(text).toContain('利用中')
    expect(text).toContain('次回更新日')
  })

  it('3プランを料金と機能つきで並べ、契約中のプランを示す', async () => {
    const wrapper = await mountPage()
    const text = wrapper.text()

    expect(text).toContain('980')
    expect(text).toContain('1,980')
    expect(text).toContain('3,980')
    expect(text).toContain('AI要約')
    expect(text).toContain('ご契約中')
  })

  it('契約が無い場合は開始を促し、Checkoutへ送る', async () => {
    getMock.mockResolvedValue(buildOverview({ subscription: null, plan: null }))
    const assign = vi.fn()
    vi.stubGlobal('location', { assign, pathname: '/settings/plan' })

    const wrapper = await mountPage(null)
    expect(wrapper.text()).toContain('ご契約がありません')

    const startButtons = wrapper
      .findAll('button')
      .filter((b) => b.text().includes('このプランで開始'))
    expect(startButtons).toHaveLength(3)

    await startButtons[1]!.trigger('click')
    await flushPromises()

    expect(checkoutMock).toHaveBeenCalledWith('standard')
    expect(assign).toHaveBeenCalledWith('https://checkout.stripe.com/c/pay/cs_1')
  })

  it('支払い失敗時は警告を表示する', async () => {
    getMock.mockResolvedValue(
      buildOverview({
        subscription: buildSubscription({
          status: 'past_due',
          status_label: 'お支払い確認中',
          needs_payment_attention: true,
        }),
      }),
    )

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('お支払いに失敗しました')
    expect(wrapper.text()).toContain('カード情報をご確認ください')
  })

  it('解約申請中は期限と取り消し導線を出す', async () => {
    getMock.mockResolvedValue(
      buildOverview({
        subscription: buildSubscription({ cancel_at_period_end: true }),
      }),
    )

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('解約を受け付けています')
    expect(wrapper.findAll('button').some((b) => b.text().includes('解約を取り消す'))).toBe(true)
    expect(wrapper.findAll('button').some((b) => b.text().includes('解約する'))).toBe(false)
  })

  it('カスタマーポータルへ遷移する', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { assign, pathname: '/settings/plan' })

    const wrapper = await mountPage()
    const button = wrapper.findAll('button').find((b) => b.text().includes('お支払い情報の管理'))
    await button!.trigger('click')
    await flushPromises()

    expect(portalMock).toHaveBeenCalled()
    expect(assign).toHaveBeenCalledWith('https://billing.stripe.com/p/session_1')
  })

  it('解約が完了した契約からは、現在のプランも含めて再契約できる', async () => {
    // 解約後も stripe_subscription_id は残る。それだけで判定すると再契約できなくなる
    getMock.mockResolvedValue(
      buildOverview({
        subscription: buildSubscription({
          status: 'canceled',
          status_label: '解約済み',
          is_active: false,
          is_subscribed: true,
        }),
        plan: null,
      }),
    )
    const assign = vi.fn()
    vi.stubGlobal('location', { assign, pathname: '/settings/plan' })

    const wrapper = await mountPage(null)
    const startButtons = wrapper
      .findAll('button')
      .filter((b) => b.text().includes('このプランで開始'))

    expect(startButtons).toHaveLength(3)
    expect(wrapper.findAll('button').some((b) => b.text().includes('解約する'))).toBe(false)
    expect(wrapper.text()).toContain('データは保持されています')

    await startButtons[0]!.trigger('click')
    await flushPromises()
    expect(checkoutMock).toHaveBeenCalledWith('lite')
  })

  /**
   * バックフィルやプロビジョニングでプランだけ入っている（未課金の）サロン。
   */
  it('Stripe 未接続なら現在のプランでも購入導線を出す', async () => {
    getMock.mockResolvedValue(
      buildOverview({
        subscription: buildSubscription({ is_subscribed: false, has_payment_method: false }),
      }),
    )

    const wrapper = await mountPage()

    expect(
      wrapper.findAll('button').filter((b) => b.text().includes('このプランで開始')),
    ).toHaveLength(3)
    expect(wrapper.findAll('button').some((b) => b.text().includes('現在ご利用中'))).toBe(false)
  })

  it('利用停止（unpaid）は支払い失敗とは別の文言で案内する', async () => {
    getMock.mockResolvedValue(
      buildOverview({
        subscription: buildSubscription({
          status: 'unpaid',
          status_label: '利用停止中',
          is_active: false,
          needs_payment_attention: true,
        }),
        plan: null,
      }),
    )

    const wrapper = await mountPage(null)

    expect(wrapper.text()).toContain('ご利用を停止しています')
    expect(wrapper.text()).not.toContain('いまはそのままご利用いただけます')
  })

  it('ダウングレードでは失う機能を確認ダイアログで知らせる', async () => {
    const wrapper = await mountPage()
    // Standard 契約なので Lite への変更がダウングレードになる
    const downgrade = wrapper.findAll('button').find((b) => b.text().includes('このプランに変更'))

    await downgrade!.trigger('click')
    await flushPromises()

    const message = String(requireMock.mock.calls[0]?.[0]?.message ?? '')
    expect(message).toContain('ご利用いただけなくなります')
    expect(message).toContain('予約管理')
    expect(message).toContain('LINE連携')
  })

  it('アップグレードでは失う機能の警告を出さない', async () => {
    getMock.mockResolvedValue(
      buildOverview({
        subscription: buildSubscription({ plan: 'lite', plan_label: 'Lite', monthly_price: 980 }),
        plan: 'lite',
      }),
    )
    const wrapper = await mountPage('lite')
    const upgrade = wrapper.findAll('button').find((b) => b.text().includes('このプランに変更'))

    await upgrade!.trigger('click')
    await flushPromises()

    expect(String(requireMock.mock.calls[0]?.[0]?.message ?? '')).not.toContain(
      'ご利用いただけなくなります',
    )
  })

  /**
   * バックフィル済み・プロビジョニング済みのサロンは status=active だが Stripe 未連携。
   * 実際には全機能が使えているので、利用できない旨の案内を出してはいけない。
   */
  it('Stripe 未接続でも利用中なら「ご利用いただけない」と案内しない', async () => {
    getMock.mockResolvedValue(
      buildOverview({
        subscription: buildSubscription({ is_subscribed: false, has_payment_method: false }),
      }),
    )

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('利用中')
    expect(wrapper.text()).not.toContain('現在ご利用いただけない状態です')
  })

  it('失効している場合は利用できない旨を案内する', async () => {
    getMock.mockResolvedValue(
      buildOverview({
        subscription: buildSubscription({
          status: 'canceled',
          status_label: '解約済み',
          is_active: false,
        }),
        plan: null,
      }),
    )

    const wrapper = await mountPage(null)

    expect(wrapper.text()).toContain('現在ご利用いただけない状態です')
  })

  it('読み込みに失敗したら再読み込み導線を出す', async () => {
    getMock.mockRejectedValue(new Error('boom'))

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('契約情報を読み込めませんでした')
    expect(wrapper.findAll('button').some((b) => b.text().includes('再読み込み'))).toBe(true)
  })
})
