import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import App from './App.vue'

const Blank = { template: '<div />' }

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
