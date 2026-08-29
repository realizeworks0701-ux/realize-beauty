import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { DashboardSummary } from '@/types'
import DashboardPage from './DashboardPage.vue'

const getSummaryMock = vi.hoisted(() => vi.fn())

vi.mock('@/services/dashboardService', () => ({
  dashboardService: { getSummary: getSummaryMock },
}))

function buildSummary(overrides: Partial<DashboardSummary> = {}): DashboardSummary {
  return {
    kpis: {
      new_customers: { current: 12, previous: 10 },
      reservations: { current: 28, previous: 25 },
      sales: { current: 324000, previous: 300000 },
      repeat_rate: { current: 78, previous: 73 },
    },
    sales_trend: [
      { month: '2026-03', sales: 182000 },
      { month: '2026-04', sales: 210000 },
      { month: '2026-05', sales: 198000 },
      { month: '2026-06', sales: 246000 },
      { month: '2026-07', sales: 289000 },
      { month: '2026-08', sales: 324000 },
    ],
    today_reservations: [
      {
        id: 1,
        customer: { id: 1, name: '山田 ひとみ', kana: 'ヤマダ ヒトミ', phone: null },
        menu: {
          id: 1,
          name: 'フェイシャルケア',
          price: 12000,
          duration_minutes: 60,
          is_active: true,
        },
        user: { id: 1, name: '佐藤 恵' },
        start_at: '2026-08-29T10:00:00+09:00',
        end_at: '2026-08-29T11:00:00+09:00',
        status: 'reserved',
        source: 'staff',
        note: null,
        created_at: '2026-08-01T10:00:00+09:00',
        updated_at: '2026-08-01T10:00:00+09:00',
      },
    ],
    popular_menus: [{ menu_id: 1, name: 'プレミアムフェイシャル', price: 12000, count: 14 }],
    customer_segments: { new: 28, repeat: 42, dormant: 6, other: 4 },
    ...overrides,
  }
}

async function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/reservations', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()

  const wrapper = mount(DashboardPage, {
    global: {
      plugins: [router, PrimeVue, ToastService],
      stubs: { SalesTrendChart: true },
    },
  })
  await flushPromises()
  return wrapper
}

describe('DashboardPage', () => {
  beforeEach(() => {
    getSummaryMock.mockReset()
    getSummaryMock.mockResolvedValue(buildSummary())
  })

  it('KPIカード4枚を前月比付きで表示する', async () => {
    const wrapper = await mountPage()
    const text = wrapper.text()
    expect(text).toContain('新規顧客数')
    expect(text).toContain('予約数')
    expect(text).toContain('売上')
    expect(text).toContain('リピート率')
    expect(text).toContain('+20%')
    expect(text).toContain('+12%')
    expect(text).toContain('+8%')
    expect(text).toContain('+5pt')
    expect(text).toContain('324,000')
  })

  it('本日の来店予約と人気メニューとセグメントを表示する', async () => {
    const wrapper = await mountPage()
    const text = wrapper.text()
    expect(text).toContain('山田 ひとみ')
    expect(text).toContain('フェイシャルケア')
    expect(text).toContain('プレミアムフェイシャル')
    expect(text).toContain('14件')
    expect(text).toContain('リピーター')
    expect(text).toContain('42')
  })

  it('本日の予約が0件ならEmptyStateを表示する', async () => {
    getSummaryMock.mockResolvedValue(buildSummary({ today_reservations: [] }))
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('本日の予約はありません')
  })
})
