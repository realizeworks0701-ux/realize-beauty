import type { AxiosInstance, AxiosResponse, InternalAxiosRequestConfig } from 'axios'
import { AxiosError } from 'axios'
import type {
  BusinessHour,
  BusinessHoursUpdateInput,
  Customer,
  CustomerInput,
  Menu,
  MenuCreateInput,
  MenuUpdateInput,
  Photo,
  RecordCreateInput,
  RecordUpdateInput,
  Reservation,
  ReservationCreateInput,
  ReservationUpdateInput,
  TreatmentRecord,
} from '@/types'
import { toIsoWithOffset } from '@/utils/format'
import {
  buildMockReservations,
  mockBusinessHours,
  mockCustomers,
  mockMenus,
  mockRecords,
  mockStaffUsers,
  mockUser,
} from './fixtures'

/**
 * 開発用モックアダプタ（VITE_USE_MOCK=true のときのみ apiClient へ装着される）。
 * バックエンド無しでUIを確認するための最小実装。
 */

const DELAY_MS = 250

let customers: Customer[] = structuredClone(mockCustomers)
let records: TreatmentRecord[] = structuredClone(mockRecords)
let menus: Menu[] = structuredClone(mockMenus)
let businessHours: BusinessHour[] = structuredClone(mockBusinessHours)
let reservations: Reservation[] = buildMockReservations()
let nextCustomerId = customers.length + 1
let nextRecordId = records.length + 1
let nextBlockId = 100
let nextPhotoId = 100
let nextMenuId = menus.length + 1
let nextReservationId = reservations.length + 1

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

const validationError = (
  config: InternalAxiosRequestConfig,
  errors: Record<string, string[]>,
): never => {
  throw new AxiosError(
    'Unprocessable Entity',
    'ERR_BAD_REQUEST',
    config,
    null,
    respond(
      config,
      { message: Object.values(errors)[0]?.[0] ?? 'Validation failed.', errors },
      422,
    ),
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

const toLocalDateString = (value: string | Date): string => {
  const date = value instanceof Date ? value : new Date(value)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

const DOUBLE_BOOKING_MESSAGE = '指定した時間帯は既に予約が入っています。'

const hasDoubleBooking = (
  userId: number,
  startMs: number,
  endMs: number,
  exceptId: number | null,
): boolean =>
  reservations.some(
    (reservation) =>
      reservation.user.id === userId &&
      reservation.id !== exceptId &&
      (reservation.status === 'reserved' || reservation.status === 'visited') &&
      new Date(reservation.start_at).getTime() < endMs &&
      startMs < new Date(reservation.end_at).getTime(),
  )

const sortedMenus = (): Menu[] =>
  [...menus].sort((a, b) => a.display_order - b.display_order || a.id - b.id)

const sortedBusinessHours = (): BusinessHour[] =>
  [...businessHours].sort((a, b) => a.day_of_week - b.day_of_week)

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
      const today = toLocalDateString(new Date())
      const todayReservations = reservations.filter(
        (reservation) =>
          toLocalDateString(reservation.start_at) === today &&
          (reservation.status === 'reserved' || reservation.status === 'visited'),
      ).length
      return respond(config, {
        data: {
          today_customers: 8,
          new_customers: 2,
          total_customers: customers.length + 144,
          records_this_month: 94,
          today_reservations: todayReservations,
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

    // ---- Users ----
    if (method === 'get' && url === '/users') {
      return respond(config, { data: mockStaffUsers })
    }

    // ---- Menus ----
    if (url === '/menus') {
      if (method === 'get') {
        const isActive = config.params?.is_active
        const onlyActive = isActive === true || isActive === 'true'
        const list = sortedMenus().filter((menu) => (onlyActive ? menu.is_active : true))
        return respond(config, { data: list })
      }
      if (method === 'post') {
        const input = parseBody<MenuCreateInput>(config)
        const now = new Date().toISOString()
        const menu: Menu = {
          id: nextMenuId++,
          name: input.name,
          price: input.price,
          duration_minutes: input.duration_minutes,
          display_order:
            input.display_order ?? Math.max(0, ...menus.map((m) => m.display_order)) + 1,
          is_active: input.is_active ?? true,
          created_at: now,
          updated_at: now,
        }
        menus = [...menus, menu]
        return respond(config, { data: menu }, 201)
      }
    }

    const menuMatch = url.match(/^\/menus\/(\d+)$/)
    if (menuMatch) {
      const id = Number(menuMatch[1])
      const menu = menus.find((m) => m.id === id)
      if (!menu) return notFound(config)
      if (method === 'get') {
        return respond(config, { data: menu })
      }
      if (method === 'put' || method === 'patch') {
        const input = parseBody<MenuUpdateInput>(config)
        Object.assign(menu, input, { updated_at: new Date().toISOString() })
        return respond(config, { data: menu })
      }
      if (method === 'delete') {
        menus = menus.filter((m) => m.id !== id)
        return respond(config, null, 204)
      }
    }

    // ---- Business Hours ----
    if (url === '/business-hours') {
      if (method === 'get') {
        return respond(config, { data: sortedBusinessHours() })
      }
      if (method === 'put') {
        const input = parseBody<BusinessHoursUpdateInput>(config)
        const invalidIndex = input.business_hours.findIndex(
          (hour) => hour.close_time <= hour.open_time,
        )
        if (invalidIndex !== -1) {
          return validationError(config, {
            [`business_hours.${invalidIndex}.close_time`]: [
              '閉店時刻は開店時刻より後にしてください。',
            ],
          })
        }
        businessHours = input.business_hours.map((hour) => ({ ...hour }))
        return respond(config, { data: sortedBusinessHours() })
      }
    }

    // ---- Reservations ----
    if (url === '/reservations') {
      if (method === 'get') {
        const from: string = config.params?.from ?? toLocalDateString(new Date())
        const to: string = config.params?.to ?? from
        const userId = config.params?.user_id != null ? Number(config.params.user_id) : null
        const status: string | null = config.params?.status ?? null
        const list = reservations
          .filter((reservation) => {
            const date = toLocalDateString(reservation.start_at)
            if (date < from || date > to) return false
            if (userId !== null && reservation.user.id !== userId) return false
            if (status !== null && reservation.status !== status) return false
            return true
          })
          .sort((a, b) => new Date(a.start_at).getTime() - new Date(b.start_at).getTime())
        return respond(config, { data: list })
      }
      if (method === 'post') {
        const input = parseBody<ReservationCreateInput>(config)
        const customer = customers.find((c) => c.id === input.customer_id)
        const menu = menus.find((m) => m.id === input.menu_id && m.is_active)
        const user = mockStaffUsers.find((u) => u.id === input.user_id)
        if (!customer) {
          return validationError(config, { customer_id: ['指定した顧客が見つかりません。'] })
        }
        if (!menu) {
          return validationError(config, { menu_id: ['指定したメニューは利用できません。'] })
        }
        if (!user) {
          return validationError(config, { user_id: ['指定したスタッフが見つかりません。'] })
        }
        const startMs = new Date(input.start_at).getTime()
        const endMs = startMs + menu.duration_minutes * 60000
        if (hasDoubleBooking(user.id, startMs, endMs, null)) {
          return validationError(config, { start_at: [DOUBLE_BOOKING_MESSAGE] })
        }
        const now = toIsoWithOffset(new Date())
        const reservation: Reservation = {
          id: nextReservationId++,
          customer: {
            id: customer.id,
            name: customer.name,
            kana: customer.kana,
            phone: customer.phone,
          },
          menu: {
            id: menu.id,
            name: menu.name,
            price: menu.price,
            duration_minutes: menu.duration_minutes,
            is_active: menu.is_active,
          },
          user: { id: user.id, name: user.name },
          start_at: input.start_at,
          end_at: toIsoWithOffset(new Date(endMs)),
          status: 'reserved',
          note: input.note ?? null,
          created_at: now,
          updated_at: now,
        }
        reservations = [...reservations, reservation]
        return respond(config, { data: reservation }, 201)
      }
    }

    const reservationMatch = url.match(/^\/reservations\/(\d+)$/)
    if (reservationMatch) {
      const id = Number(reservationMatch[1])
      const reservation = reservations.find((r) => r.id === id)
      if (!reservation) return notFound(config)
      if (method === 'get') {
        return respond(config, { data: reservation })
      }
      if (method === 'patch') {
        const input = parseBody<ReservationUpdateInput>(config)
        const customer =
          input.customer_id !== undefined
            ? customers.find((c) => c.id === input.customer_id)
            : undefined
        if (input.customer_id !== undefined && !customer) {
          return validationError(config, { customer_id: ['指定した顧客が見つかりません。'] })
        }
        const menu =
          input.menu_id !== undefined ? menus.find((m) => m.id === input.menu_id) : undefined
        if (input.menu_id !== undefined && (!menu || !menu.is_active)) {
          return validationError(config, { menu_id: ['指定したメニューは利用できません。'] })
        }
        const user =
          input.user_id !== undefined
            ? mockStaffUsers.find((u) => u.id === input.user_id)
            : undefined
        if (input.user_id !== undefined && !user) {
          return validationError(config, { user_id: ['指定したスタッフが見つかりません。'] })
        }
        const nextStatus = input.status ?? reservation.status
        const nextUserId = user?.id ?? reservation.user.id
        const startMs = new Date(input.start_at ?? reservation.start_at).getTime()
        const shouldRecalcEnd = input.start_at !== undefined || input.menu_id !== undefined
        const durationMinutes = menu?.duration_minutes ?? reservation.menu.duration_minutes
        const endMs = shouldRecalcEnd
          ? startMs + durationMinutes * 60000
          : new Date(reservation.end_at).getTime()
        if (
          (nextStatus === 'reserved' || nextStatus === 'visited') &&
          hasDoubleBooking(nextUserId, startMs, endMs, id)
        ) {
          return validationError(config, { start_at: [DOUBLE_BOOKING_MESSAGE] })
        }
        if (customer) {
          reservation.customer = {
            id: customer.id,
            name: customer.name,
            kana: customer.kana,
            phone: customer.phone,
          }
        }
        if (menu) {
          reservation.menu = {
            id: menu.id,
            name: menu.name,
            price: menu.price,
            duration_minutes: menu.duration_minutes,
            is_active: menu.is_active,
          }
        }
        if (user) {
          reservation.user = { id: user.id, name: user.name }
        }
        if (input.start_at !== undefined) reservation.start_at = input.start_at
        if (shouldRecalcEnd) reservation.end_at = toIsoWithOffset(new Date(endMs))
        if (input.status !== undefined) reservation.status = input.status
        if (input.note !== undefined) reservation.note = input.note
        reservation.updated_at = toIsoWithOffset(new Date())
        return respond(config, { data: reservation })
      }
      if (method === 'delete') {
        reservations = reservations.filter((r) => r.id !== id)
        return respond(config, null, 204)
      }
    }

    return notFound(config)
  }
}
