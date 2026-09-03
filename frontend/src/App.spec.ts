import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import App from './App.vue'

// App は起動時にユーザー情報を取り直すため、認証サービスを差し替える
vi.mock('@/services/authService', () => ({
  authService: { me: vi.fn().mockResolvedValue(null), login: vi.fn(), logout: vi.fn() },
}))

const Blank = { template: '<div />' }

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

function buildRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/dashboard', component: Blank },
      { path: '/login', component: Blank, meta: { public: true } },
      { path: '/booking/:slug', component: Blank, meta: { public: true, legacyTheme: true } },
    ],
  })
}

async function mountAt(path: string) {
  const router = buildRouter()
  await router.push(path)
  await router.isReady()
  const wrapper = mount(App, {
    global: {
      plugins: [router, PrimeVue, ToastService, ConfirmationService],
      stubs: { AppLayout: Blank },
    },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('App のテーマ切り替え', () => {
  beforeEach(() => {
    vi.stubGlobal('localStorage', memoryStorage())
    setActivePinia(createPinia())
  })

  afterEach(() => {
    document.documentElement.classList.remove('rb-legacy-theme')
  })

  it('公開予約ページではレガシーテーマクラスを付ける', async () => {
    await mountAt('/booking/demo-salon')
    expect(document.documentElement.classList.contains('rb-legacy-theme')).toBe(true)
  })

  it('管理画面ではレガシーテーマクラスを付けない', async () => {
    await mountAt('/dashboard')
    expect(document.documentElement.classList.contains('rb-legacy-theme')).toBe(false)
  })

  it('ログイン画面は public だがレガシーテーマにしない', async () => {
    await mountAt('/login')
    expect(document.documentElement.classList.contains('rb-legacy-theme')).toBe(false)
  })

  it('予約ページから管理画面へ遷移するとクラスを外す', async () => {
    const { router } = await mountAt('/booking/demo-salon')
    await router.push('/dashboard')
    await flushPromises()
    expect(document.documentElement.classList.contains('rb-legacy-theme')).toBe(false)
  })
})
