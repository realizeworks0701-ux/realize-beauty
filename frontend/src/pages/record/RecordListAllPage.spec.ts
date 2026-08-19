import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import Select from 'primevue/select'
import type { Paginated, TreatmentRecord } from '@/types'
import { formatDate } from '@/utils/format'
import RecordListAllPage from './RecordListAllPage.vue'

const listMock = vi.hoisted(() => vi.fn())

vi.mock('@/services/recordService', () => ({
  recordService: { list: listMock },
}))

const records: TreatmentRecord[] = [
  {
    id: 11,
    customer: { id: 1, name: '田中 美咲', kana: 'タナカ ミサキ', phone: '09011112222' },
    user: { id: 2, name: '山田 太郎' },
    status: 'completed',
    visited_at: '2026-08-10T10:00:00+09:00',
    ai_summary: null,
    created_at: '2026-08-10T10:00:00+09:00',
    updated_at: '2026-08-10T10:00:00+09:00',
  },
  {
    id: 12,
    customer: { id: 2, name: '佐藤 恵', kana: 'サトウ メグミ', phone: null },
    user: { id: 3, name: '鈴木 花子' },
    status: 'draft',
    visited_at: '2026-07-01T13:30:00+09:00',
    ai_summary: null,
    created_at: '2026-07-01T13:30:00+09:00',
    updated_at: '2026-07-01T13:30:00+09:00',
  },
]

function paginated(data: TreatmentRecord[], total = data.length): Paginated<TreatmentRecord> {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: data.length > 0 ? 1 : null,
      last_page: Math.max(1, Math.ceil(total / 20)),
      path: '/api/v1/records',
      per_page: 20,
      to: data.length > 0 ? data.length : null,
      total,
    },
  }
}

async function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/records', name: 'record-list', component: RecordListAllPage },
      { path: '/records/:id', name: 'record-detail', component: { template: '<div />' } },
    ],
  })
  await router.push('/records')
  await router.isReady()

  const wrapper = mount(RecordListAllPage, {
    global: { plugins: [router, PrimeVue, ToastService, ConfirmationService] },
  })
  await flushPromises()
  return wrapper
}

/** テスト環境の jsdom は matchMedia を提供しないため、PrimeVue Select 用に最小実装を差し込む */
function stubMatchMedia(): void {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => {},
    removeEventListener: () => {},
    addListener: () => {},
    removeListener: () => {},
    dispatchEvent: () => false,
  }))
}

describe('RecordListAllPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    stubMatchMedia()
    listMock.mockResolvedValue(paginated(records))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('カルテ一覧を描画する', async () => {
    const wrapper = await mountPage()
    const text = wrapper.text()

    expect(listMock).toHaveBeenCalledWith({ status: undefined, keyword: undefined, page: 1 })
    expect(text).toContain('田中 美咲')
    expect(text).toContain('タナカ ミサキ')
    expect(text).toContain('山田 太郎')
    expect(text).toContain(formatDate(records[0]!.visited_at))
    expect(text).toContain('完了')
    expect(text).toContain('佐藤 恵')
    expect(text).toContain('鈴木 花子')
    expect(text).toContain(formatDate(records[1]!.visited_at))
    expect(text).toContain('下書き')
    expect(text).toContain('全 2 件')
  })

  it('ステータスを絞り込むと status 付きで再取得する', async () => {
    const wrapper = await mountPage()
    listMock.mockClear()

    await wrapper.findComponent(Select).setValue('completed')
    await flushPromises()

    expect(listMock).toHaveBeenCalledWith({ status: 'completed', keyword: undefined, page: 1 })
  })

  it('ステータスの選択ラベルが常に表示される', async () => {
    const wrapper = await mountPage()
    const label = () => wrapper.find('.p-select-label').text()

    expect(label()).toBe('すべて')

    await wrapper.findComponent(Select).setValue('draft')
    await flushPromises()
    expect(label()).toBe('下書き')

    await wrapper.findComponent(Select).setValue('all')
    await flushPromises()
    expect(label()).toBe('すべて')
  })

  it('絞り込みを解除すると status を送らない', async () => {
    const wrapper = await mountPage()
    await wrapper.findComponent(Select).setValue('completed')
    await flushPromises()
    listMock.mockClear()

    await wrapper.findComponent(Select).setValue('all')
    await flushPromises()

    expect(listMock).toHaveBeenCalledWith({ status: undefined, keyword: undefined, page: 1 })
  })

  it('デバウンス中にステータスを変えても取得は1回だけ', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const wrapper = await mountPage()
    listMock.mockClear()

    await wrapper.find('input[placeholder="顧客の氏名・フリガナで検索"]').setValue('田')
    await wrapper.findComponent(Select).setValue('completed')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(400)
    await flushPromises()

    expect(listMock).toHaveBeenCalledTimes(1)
    expect(listMock).toHaveBeenCalledWith({ status: 'completed', keyword: '田', page: 1 })
  })

  it('キーワード入力はデバウンスされ、ページが1に戻る', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    listMock.mockResolvedValue(paginated(records, 45))
    const wrapper = await mountPage()

    await wrapper.find('.p-paginator-next').trigger('click')
    await flushPromises()
    expect(listMock).toHaveBeenLastCalledWith({ status: undefined, keyword: undefined, page: 2 })

    listMock.mockClear()
    await wrapper.find('input[placeholder="顧客の氏名・フリガナで検索"]').setValue('田中')
    await vi.advanceTimersByTimeAsync(299)
    expect(listMock).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()
    expect(listMock).toHaveBeenCalledTimes(1)
    expect(listMock).toHaveBeenCalledWith({ status: undefined, keyword: '田中', page: 1 })
  })

  it('カルテが0件なら空状態を表示する', async () => {
    listMock.mockResolvedValue(paginated([], 0))
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('カルテはまだありません')
    expect(wrapper.find('.p-datatable').exists()).toBe(false)
  })
})
