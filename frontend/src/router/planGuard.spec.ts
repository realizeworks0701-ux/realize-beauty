import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { buildTestUser } from '@/test-support/user'
import { TOKEN_STORAGE_KEY } from '@/services/apiClient'
import type { PlanCode } from '@/types'

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

/**
 * 実ルータを読み込むと全ページを import してしまうため、
 * ガードの挙動だけを本体と同じ条件式で確かめる。
 */
async function resolve(path: string, plan: PlanCode | null) {
  vi.stubGlobal('localStorage', memoryStorage())
  localStorage.setItem(TOKEN_STORAGE_KEY, 'test-token')
  setActivePinia(createPinia())
  useAuthStore().user = buildTestUser(plan)

  const { default: router } = await import('./index')
  await router.push(path)
  await router.isReady()
  return router.currentRoute.value
}

describe('プラン制限のルータガード', () => {
  beforeEach(() => {
    vi.resetModules()
  })

  it('Lite で予約カレンダーへ入るとアップグレード導線へ振り替える', async () => {
    const route = await resolve('/reservations', 'lite')

    expect(route.name).toBe('feature-locked')
    expect(route.params.feature).toBe('reservation')
  })

  it('Lite でLINE設定へ入るとアップグレード導線へ振り替える', async () => {
    const route = await resolve('/settings/line', 'lite')

    expect(route.name).toBe('feature-locked')
    expect(route.params.feature).toBe('line')
  })

  it('Standard なら予約カレンダーへ入れる', async () => {
    const route = await resolve('/reservations', 'standard')

    expect(route.name).toBe('reservation-calendar')
  })

  it('契約が無いと顧客一覧にも入れない', async () => {
    const route = await resolve('/customers', null)

    expect(route.name).toBe('feature-locked')
    expect(route.params.feature).toBe('customer')
  })

  it('プラン画面は契約が無くても到達できる（再契約の導線を塞がない）', async () => {
    const route = await resolve('/settings/plan', null)

    expect(route.name).toBe('settings-plan')
  })

  /**
   * 課金導入前に保存されたユーザー情報には features が無い。
   * ここで閉じると、デプロイ直後の既存ログインが全画面から締め出される。
   */
  it('機能フラグを持たない古いセッションは振り替えない', async () => {
    vi.stubGlobal('localStorage', memoryStorage())
    localStorage.setItem(TOKEN_STORAGE_KEY, 'test-token')
    setActivePinia(createPinia())
    const legacyUser = buildTestUser('pro') as unknown as Record<string, unknown>
    delete legacyUser.features
    delete legacyUser.plan
    useAuthStore().user = legacyUser as never

    const { default: router } = await import('./index')
    await router.push('/reservations')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('reservation-calendar')
  })

  it('設定トップとダッシュボードは常に到達できる', async () => {
    expect((await resolve('/settings', null)).name).toBe('settings')
    expect((await resolve('/dashboard', null)).name).toBe('dashboard')
  })
})
