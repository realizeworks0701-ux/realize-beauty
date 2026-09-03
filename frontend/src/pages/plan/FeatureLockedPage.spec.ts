import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import FeatureLockedPage from './FeatureLockedPage.vue'
import { useAuthStore } from '@/stores/auth'
import { buildTestUser } from '@/test-support/user'

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

async function mountFor(feature: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/plan-required/:feature', component: { template: '<div />' } },
      { path: '/settings/plan', component: { template: '<div />' } },
      { path: '/dashboard', component: { template: '<div />' } },
    ],
  })
  await router.push(`/plan-required/${feature}`)
  await router.isReady()
  useAuthStore().user = buildTestUser('lite')

  return mount(FeatureLockedPage, { global: { plugins: [router, PrimeVue] } })
}

describe('FeatureLockedPage', () => {
  beforeEach(() => {
    vi.stubGlobal('localStorage', memoryStorage())
    setActivePinia(createPinia())
  })

  it('既知の機能キーならアップグレード導線を出す', async () => {
    const wrapper = await mountFor('reservation')

    expect(wrapper.text()).toContain('予約管理')
    expect(wrapper.text()).toContain('Standardプランを見る')
  })

  it('未知の機能キーはフォールバックする', async () => {
    const wrapper = await mountFor('nope')

    expect(wrapper.text()).toContain('指定された機能が見つかりませんでした')
  })

  /**
   * `in` 演算子はプロトタイプのキーも通すため、URL 直打ちで
   * Object.prototype のメソッドがそのまま描画されてしまう。
   */
  it.each(['constructor', 'toString', 'valueOf', 'hasOwnProperty'])(
    'プロトタイプのキー（%s）を機能として扱わない',
    async (key) => {
      const wrapper = await mountFor(key)

      expect(wrapper.text()).toContain('指定された機能が見つかりませんでした')
      expect(wrapper.text()).not.toContain('native code')
    },
  )
})
