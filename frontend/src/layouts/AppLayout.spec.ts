import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import AppLayout from './AppLayout.vue'
import { useAuthStore } from '@/stores/auth'
import { buildTestUser } from '@/test-support/user'
import type { PlanCode } from '@/types'

/** jsdom は localStorage を提供しないため、auth ストア用に最小実装を差し込む */
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

const Blank = { template: '<div />' }

function buildRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/dashboard', component: Blank },
      { path: '/customers', component: Blank },
      { path: '/records', component: Blank },
      { path: '/reservations', component: Blank },
      { path: '/settings', component: Blank },
    ],
  })
}

async function mountWithPlan(plan: PlanCode | null) {
  const router = buildRouter()
  await router.push('/dashboard')
  await router.isReady()
  useAuthStore().user = buildTestUser(plan)

  return mount(AppLayout, {
    global: { plugins: [router, PrimeVue, ConfirmationService] },
  })
}

describe('AppLayout のプラン別ナビゲーション', () => {
  beforeEach(() => {
    vi.stubGlobal('localStorage', memoryStorage())
    setActivePinia(createPinia())
  })

  it('Lite では予約を表示しない', async () => {
    const wrapper = await mountWithPlan('lite')
    const text = wrapper.text()

    expect(text).toContain('ダッシュボード')
    expect(text).toContain('顧客')
    expect(text).toContain('カルテ')
    expect(text).toContain('設定')
    expect(text).not.toContain('予約')
  })

  it('Standard では予約を表示する', async () => {
    const wrapper = await mountWithPlan('standard')

    expect(wrapper.text()).toContain('予約')
  })

  it('Pro でも項目構成は Standard と同じ（AI要約・分析は専用画面を持たない）', async () => {
    const wrapper = await mountWithPlan('pro')
    const links = wrapper.findAll('.app-sidebar .nav-item').map((n) => n.text())

    expect(links).toEqual(['ダッシュボード', '顧客', 'カルテ', '予約', '設定'])
  })

  it('契約が無い場合はダッシュボードと設定だけを残す', async () => {
    const wrapper = await mountWithPlan(null)
    const links = wrapper.findAll('.app-sidebar .nav-item').map((n) => n.text())

    // 再契約の導線（設定）へは必ず辿り着ける必要がある
    expect(links).toEqual(['ダッシュボード', '設定'])
  })
})
