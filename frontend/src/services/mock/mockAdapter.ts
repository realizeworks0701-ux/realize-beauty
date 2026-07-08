import type { AxiosInstance, AxiosResponse, InternalAxiosRequestConfig } from 'axios'
import { AxiosError } from 'axios'
import type {
  Customer,
  CustomerInput,
  Photo,
  RecordCreateInput,
  RecordUpdateInput,
  TreatmentRecord,
} from '@/types'
import { mockCustomers, mockRecords, mockUser } from './fixtures'

/**
 * 開発用モックアダプタ（VITE_USE_MOCK=true のときのみ apiClient へ装着される）。
 * バックエンド無しでUIを確認するための最小実装。
 */

const DELAY_MS = 250

let customers: Customer[] = structuredClone(mockCustomers)
let records: TreatmentRecord[] = structuredClone(mockRecords)
let nextCustomerId = customers.length + 1
let nextRecordId = records.length + 1
let nextBlockId = 100
let nextPhotoId = 100

const delay = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, DELAY_MS))

const respond = (
  config: InternalAxiosRequestConfig,
  data: unknown,
  status = 200,
): AxiosResponse => ({
  data,
  status,
  statusText: 'OK',
  headers: {},
  config,
})

const notFound = (config: InternalAxiosRequestConfig): never => {
  throw new AxiosError(
    'Not Found',
    'ERR_BAD_REQUEST',
    config,
    null,
    respond(config, { message: 'Not Found' }, 404),
  )
}

const paginate = <T>(items: T[], config: InternalAxiosRequestConfig) => {
  const page = Number(config.params?.page ?? 1)
  const perPage = Number(config.params?.per_page ?? 15)
  const total = items.length
  const lastPage = Math.max(1, Math.ceil(total / perPage))
  const from = (page - 1) * perPage
  const slice = items.slice(from, from + perPage)
  return {
    data: slice,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: page,
      from: slice.length > 0 ? from + 1 : null,
      last_page: lastPage,
      path: '',
      per_page: perPage,
      to: slice.length > 0 ? from + slice.length : null,
      total,
    },
  }
}

const parseBody = <T>(config: InternalAxiosRequestConfig): T =>
  typeof config.data === 'string' ? (JSON.parse(config.data) as T) : (config.data as T)

const recordSummary = (record: TreatmentRecord): TreatmentRecord => {
  const { blocks: _blocks, photos: _photos, ...rest } = record
  return rest as TreatmentRecord
}

export function installMockAdapter(instance: AxiosInstance): void {
  instance.defaults.adapter = async (config: InternalAxiosRequestConfig) => {
    await delay()
    const method = (config.method ?? 'get').toLowerCase()
    const url = config.url ?? ''

    // ---- Auth ----
    if (method === 'post' && url === '/auth/login') {
      return respond(config, { data: { token: 'mock-token', user: mockUser } })
    }
    if (method === 'post' && url === '/auth/logout') {
      return respond(config, null, 204)
    }
    if (method === 'get' && url === '/auth/me') {
      return respond(config, { data: mockUser })
    }

    // ---- Dashboard ----
    if (method === 'get' && url === '/dashboard') {
      return respond(config, {
        data: {
          today_customers: 8,
          new_customers: 2,
          total_customers: customers.length + 144,
          records_this_month: 94,
          recent_customers: customers.slice(0, 5),
          recent_records: records.slice(0, 5).map(recordSummary),
        },
      })
    }

    // ---- Customers ----
    if (method === 'get' && url === '/customers') {
      const keyword: string = config.params?.keyword ?? ''
      const filtered = keyword
        ? customers.filter((c) =>
            [c.name, c.kana, c.phone ?? '', c.email ?? ''].some((v) => v.includes(keyword)),
          )
        : customers
      return respond(config, paginate(filtered, config))
    }
    if (method === 'post' && url === '/customers') {
      const input = parseBody<CustomerInput>(config)
      const now = new Date().toISOString()
      const customer: Customer = {
        id: nextCustomerId++,
        name: input.name,
        kana: input.kana,
        gender: input.gender ?? null,
        birthday: input.birthday ?? null,
        phone: input.phone ?? null,
        email: input.email ?? null,
        memo: input.memo ?? null,
        first_visit_at: null,
        last_visit_at: null,
        created_at: now,
        updated_at: now,
      }
      customers = [customer, ...customers]
      return respond(config, { data: customer }, 201)
    }

    const customerMatch = url.match(/^\/customers\/(\d+)$/)
    if (customerMatch) {
      const id = Number(customerMatch[1])
      const customer = customers.find((c) => c.id === id)
      if (!customer) return notFound(config)
      if (method === 'get') {
        return respond(config, { data: customer })
      }
      if (method === 'put' || method === 'patch') {
        const input = parseBody<CustomerInput>(config)
        Object.assign(customer, input, { updated_at: new Date().toISOString() })
        return respond(config, { data: customer })
      }
      if (method === 'delete') {
        customers = customers.filter((c) => c.id !== id)
        return respond(config, null, 204)
      }
    }

    // ---- Records ----
    const customerRecordsMatch = url.match(/^\/customers\/(\d+)\/records$/)
    if (customerRecordsMatch) {
      const customerId = Number(customerRecordsMatch[1])
      if (method === 'get') {
        const list = records
          .filter((r) => r.customer.id === customerId)
          .map(recordSummary)
        return respond(config, paginate(list, config))
      }
      if (method === 'post') {
        const customer = customers.find((c) => c.id === customerId)
        if (!customer) return notFound(config)
        const input = parseBody<RecordCreateInput>(config)
        const now = new Date().toISOString()
        const record: TreatmentRecord = {
          id: nextRecordId++,
          customer: {
            id: customer.id,
            name: customer.name,
            kana: customer.kana,
            phone: customer.phone,
          },
          user: { id: mockUser.id, name: mockUser.name },
          status: input.status,
          visited_at: input.visited_at,
          ai_summary: null,
          blocks: input.blocks.map((b) => ({
            id: nextBlockId++,
            label: b.label,
            content: b.content,
            sort_order: b.sort_order,
          })),
          photos: [],
          created_at: now,
          updated_at: now,
        }
        records = [record, ...records]
        return respond(config, { data: record }, 201)
      }
    }

    const recordMatch = url.match(/^\/records\/(\d+)$/)
    if (recordMatch) {
      const id = Number(recordMatch[1])
      const record = records.find((r) => r.id === id)
      if (!record) return notFound(config)
      if (method === 'get') {
        return respond(config, { data: record })
      }
      if (method === 'patch' || method === 'put') {
        const input = parseBody<RecordUpdateInput>(config)
        if (input.visited_at !== undefined) record.visited_at = input.visited_at
        if (input.status !== undefined) record.status = input.status
        if (input.blocks !== undefined) {
          record.blocks = input.blocks.map((b) => ({
            id: b.id ?? nextBlockId++,
            label: b.label,
            content: b.content,
            sort_order: b.sort_order,
          }))
        }
        record.updated_at = new Date().toISOString()
        return respond(config, { data: record })
      }
      if (method === 'delete') {
        records = records.filter((r) => r.id !== id)
        return respond(config, null, 204)
      }
    }

    const summarizeMatch = url.match(/^\/records\/(\d+)\/summarize$/)
    if (method === 'post' && summarizeMatch) {
      const record = records.find((r) => r.id === Number(summarizeMatch[1]))
      if (!record) return notFound(config)
      record.ai_summary =
        (record.blocks ?? [])
          .filter((b) => b.content.trim() !== '')
          .map((b) => `${b.label}: ${b.content}`)
          .join('。')
          .slice(0, 120) + '（モックによる要約）'
      return respond(config, { data: { summary: record.ai_summary } })
    }

    // ---- Photos ----
    const photoUploadMatch = url.match(/^\/records\/(\d+)\/photos$/)
    if (method === 'post' && photoUploadMatch) {
      const record = records.find((r) => r.id === Number(photoUploadMatch[1]))
      if (!record) return notFound(config)
      const body = config.data as FormData
      const image = body.get('image')
      const caption = (body.get('caption') as string | null) ?? null
      const photo: Photo = {
        id: nextPhotoId++,
        url:
          image instanceof File
            ? URL.createObjectURL(image)
            : `https://picsum.photos/seed/rb-new-${nextPhotoId}/600/600`,
        caption,
        sort_order: record.photos?.length ?? 0,
      }
      record.photos = [...(record.photos ?? []), photo]
      return respond(config, { data: photo }, 201)
    }

    const photoDeleteMatch = url.match(/^\/photos\/(\d+)$/)
    if (method === 'delete' && photoDeleteMatch) {
      const photoId = Number(photoDeleteMatch[1])
      for (const record of records) {
        record.photos = (record.photos ?? []).filter((p) => p.id !== photoId)
      }
      return respond(config, null, 204)
    }

    return notFound(config)
  }
}
