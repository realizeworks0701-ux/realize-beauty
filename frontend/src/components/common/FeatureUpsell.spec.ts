import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import FeatureUpsell from './FeatureUpsell.vue'
import { useAuthStore } from '@/stores/auth'
import { buildTestUser } from '@/test-support/user'
import type { FeatureKey, PlanCode } from '@/types'

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

async function mountFor(feature: FeatureKey, plan: PlanCode | null = 'lite') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/settings/plan', component: { template: '<div />' } }],
  })
  await router.push('/settings/plan')
  await router.isReady()
  useAuthStore().user = buildTestUser(plan)

  return mount(FeatureUpsell, {
    props: { feature },
    global: { plugins: [router, PrimeVue] },
  })
}

describe('FeatureUpsell', () => {
  beforeEach(() => {
    vi.stubGlobal('localStorage', memoryStorage())
    setActivePinia(createPinia())
  })

  it('予約にはStandardプランが必要だと案内する', async () => {
    const wrapper = await mountFor('reservation')
    const text = wrapper.text()

    expect(text).toContain('この機能はStandardプラン以上でご利用いただけます')
    expect(text).toContain('Standardプランを見る')
    // そのプランで一緒に使えるようになる機能も示す
    expect(text).toContain('予約管理')
    expect(text).toContain('Googleカレンダー連携')
    expect(text).toContain('LINE連携')
  })

  it('AI要約にはProプランが必要だと案内する', async () => {
    const wrapper = await mountFor('ai_summary')
    const text = wrapper.text()

    expect(text).toContain('この機能はProプラン以上でご利用いただけます')
    expect(text).toContain('AI要約')
    expect(text).toContain('高度な分析')
  })

  /**
   * 契約が切れているときに「上位プランが必要」と言うのは誤案内。
   */
  it('契約が無い場合は再契約を促す', async () => {
    const wrapper = await mountFor('customer', null)
    const text = wrapper.text()

    expect(text).toContain('ご契約が終了しているため')
    expect(text).toContain('プランを選び直す')
    expect(text).not.toContain('プラン以上でご利用いただけます')
  })

  /**
   * 機能フラグを持たない古いセッションでは締め出さない（サーバの403が正）。
   */
  it('プラン画面への導線を持つ', async () => {
    const wrapper = await mountFor('analytics')

    expect(wrapper.find('a[href="/settings/plan"]').exists()).toBe(true)
  })
})
