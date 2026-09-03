import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { GoogleCalendarSettings, StaffUser } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { buildTestUser } from '@/test-support/user'
import GoogleCalendarSettingsPage from './GoogleCalendarSettingsPage.vue'

const settingsMock = vi.hoisted(() => vi.fn())
const staffMock = vi.hoisted(() => vi.fn())

vi.mock('@/services/googleCalendarService', () => ({
  googleCalendarService: { get: settingsMock },
}))

vi.mock('@/services/userService', () => ({
  userService: { list: staffMock },
}))

const staff: StaffUser[] = [
  { id: 1, name: '山田 太郎', role: 'owner' },
  { id: 2, name: '田中 美咲', role: 'staff' },
  { id: 3, name: '佐藤 恵', role: 'staff' },
]

async function mountPage(settings: GoogleCalendarSettings) {
  settingsMock.mockResolvedValue(settings)
  staffMock.mockResolvedValue(staff)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/settings/google-calendar', component: GoogleCalendarSettingsPage }],
  })
  await router.push('/settings/google-calendar')
  await router.isReady()

  const wrapper = mount(GoogleCalendarSettingsPage, {
    global: { plugins: [router, PrimeVue, ToastService, ConfirmationService] },
  })
  await vi.waitUntil(() => !wrapper.text().includes('読み込め') && wrapper.text() !== '')
  await new Promise((resolve) => setTimeout(resolve, 0))
  await wrapper.vm.$nextTick()
  return wrapper
}

/** テスト環境の jsdom は localStorage を提供しないため、auth ストア用に最小実装を差し込む */
function memoryStorage(): Storage {
  const map = new Map<string, string>()
  return {
    get length() {
      return map.size
    },
    key: (index: number) => [...map.keys()][index] ?? null,
    getItem: (key: string) => map.get(key) ?? null,
    setItem: (key: string, value: string) => void map.set(key, value),
    removeItem: (key: string) => void map.delete(key),
    clear: () => map.clear(),
  }
}

describe('GoogleCalendarSettingsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.stubGlobal('localStorage', memoryStorage())
    setActivePinia(createPinia())
    useAuthStore().user = buildTestUser()
  })

  it('モード未設定では接続カードを出さず、接続単位の選択を促す', async () => {
    const wrapper = await mountPage({ mode: null, connections: [] })
    expect(wrapper.text()).toContain('接続単位を選んでください')
    expect(wrapper.text()).toContain('接続単位を選ぶと接続できます')
    expect(wrapper.text()).not.toContain('山田 太郎（あなた）')
  })

  it('per_staff では全スタッフの行に接続状態を出し、本人以外は接続導線を出さない', async () => {
    const wrapper = await mountPage({
      mode: 'per_staff',
      connections: [
        {
          id: 1,
          user: { id: 1, name: '山田 太郎' },
          google_account_email: 'owner.mock@gmail.com',
          calendar_id: 'primary',
          status: 'active',
          last_synced_at: null,
        },
        {
          id: 2,
          user: { id: 3, name: '佐藤 恵' },
          google_account_email: 'megumi@gmail.com',
          calendar_id: 'work@group.calendar.google.com',
          status: 'needs_reconnect',
          last_synced_at: '2026-07-17T12:34:00+09:00',
        },
      ],
    })
    const text = wrapper.text()

    // 本人（接続済み・同期待ち）
    expect(text).toContain('山田 太郎（あなた）')
    expect(text).toContain('メインカレンダー（owner.mock@gmail.com）')
    expect(text).toContain('同期待ち')
    // 他スタッフ（要再接続）は状態と注記のみ
    expect(text).toContain('佐藤 恵さんの再接続が必要です')
    // 未接続の他スタッフ
    expect(text).toContain('本人がログインして接続してください')
    // 接続導線は本人の行だけ（本人は接続済みのため0件）
    expect(wrapper.findAll('button').filter((b) => b.text().includes('Googleと接続'))).toHaveLength(
      0,
    )
    expect(text).toContain('カレンダーを変更')
    expect(text).toContain('接続を解除')
  })

  it('shared では共有接続1件のみを表示する', async () => {
    const wrapper = await mountPage({ mode: 'shared', connections: [] })
    const text = wrapper.text()
    expect(text).toContain('サロン共有カレンダー')
    expect(text).not.toContain('田中 美咲')
    expect(wrapper.findAll('button').filter((b) => b.text().includes('Googleと接続'))).toHaveLength(
      1,
    )
  })
})
