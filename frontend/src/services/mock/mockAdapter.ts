import type { AxiosInstance, AxiosResponse, InternalAxiosRequestConfig } from 'axios'
import { AxiosError } from 'axios'
import type {
  BusinessHour,
  BusinessHoursUpdateInput,
  BusyBlock,
  Customer,
  CustomerInput,
  GoogleCalendarConnection,
  GoogleCalendarConnectionUpdateInput,
  GoogleCalendarListEntry,
  GoogleCalendarMode,
  GoogleCalendarModeUpdateInput,
  LineSetting,
  LineSettingUpdateInput,
  Menu,
  MenuCreateInput,
  MenuUpdateInput,
  Photo,
  PublicBooking,
  PublicReservationRequest,
  RecordCreateInput,
  RecordUpdateInput,
  Reservation,
  ReservationCreateInput,
  ReservationUpdateInput,
  TreatmentRecord,
} from '@/types'
import { toIsoWithOffset } from '@/utils/format'
import { PRIMARY_CALENDAR_ID } from '@/utils/googleCalendar'
import { isWithinBookingWindow, listSlotStartMinutes, slotToIso } from '@/utils/publicBooking'
import {
  MOCK_BOOKING_SLUG,
  MOCK_SALON_NAME,
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

/** サロン全体のカルテ一覧の既定件数（バックエンドの既定に合わせる） */
const RECORDS_PER_PAGE = 20

/** LINE連携設定のモック保持値（平文。API 応答時のみマスクする） */
interface MockLineSetting {
  channel_id: string
  channel_secret: string
  channel_access_token: string
  bot_user_id: string | null
  bot_basic_id: string | null
  bot_display_name: string | null
  is_active: boolean
  connected_at: string | null
  last_webhook_at: string | null
}

let customers: Customer[] = structuredClone(mockCustomers)
let records: TreatmentRecord[] = structuredClone(mockRecords)
let menus: Menu[] = structuredClone(mockMenus)
let businessHours: BusinessHour[] = structuredClone(mockBusinessHours)
let reservations: Reservation[] = buildMockReservations()
// モックは未設定状態から開始する（保存 → 接続確認 → 解除の一連の流れを確認できる）
let lineSetting: MockLineSetting | null = null
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

// ---- 公開Web予約用ヘルパー ----

/** booking_token → 予約ID（公開予約で発行したトークンのみ参照可能） */
const publicBookingTokens = new Map<string, number>()

const conflictError = (config: InternalAxiosRequestConfig, message: string): never => {
  throw new AxiosError(
    'Conflict',
    'ERR_BAD_REQUEST',
    config,
    null,
    respond(config, { message }, 409),
  )
}

const randomFrom = (chars: string, length: number): string =>
  Array.from({ length }, () => chars.charAt(Math.floor(Math.random() * chars.length))).join('')

const randomBookingToken = (): string =>
  randomFrom('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 32)

/** 連携コード（A-Z / 2-9 から I・O を除く6文字） */
const randomLinkCode = (): string => randomFrom('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 6)

/** phone の正規化（ハイフン・空白除去、全角→半角） */
const normalizePhone = (phone: string): string =>
  phone
    .replace(/[０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xfee0))
    .replace(/[-\s‐－ー]/g, '')

/** 行が存在しない曜日はデフォルト（09:00〜19:00 営業）で補完する */
const businessHourOf = (dayOfWeek: number): BusinessHour =>
  businessHours.find((hour) => hour.day_of_week === dayOfWeek) ?? {
    day_of_week: dayOfWeek,
    is_closed: false,
    open_time: '09:00',
    close_time: '19:00',
  }

const isStaffFree = (userId: number, startMs: number, endMs: number): boolean =>
  !hasDoubleBooking(userId, startMs, endMs, null)

const toPublicBooking = (reservation: Reservation): PublicBooking => ({
  salon_name: MOCK_SALON_NAME,
  menu_name: reservation.menu.name,
  staff_name: reservation.user.name,
  start_at: reservation.start_at,
  end_at: reservation.end_at,
  status: reservation.status,
  can_cancel:
    reservation.status === 'reserved' && Date.now() < new Date(reservation.start_at).getTime(),
})

// ---- LINE連携設定用ヘルパー ----

const mask = (value: string): string => `****${value.slice(-4)}`

const webhookUrl = (): string => `${window.location.origin}/api/line/webhook`

const toLineSettingResponse = (): LineSetting =>
  lineSetting === null
    ? {
        configured: false,
        channel_id: null,
        channel_secret: null,
        channel_access_token: null,
        bot_user_id: null,
        bot_basic_id: null,
        bot_display_name: null,
        is_active: false,
        connected_at: null,
        last_webhook_at: null,
        webhook_url: webhookUrl(),
      }
    : {
        configured: true,
        channel_id: lineSetting.channel_id,
        channel_secret: mask(lineSetting.channel_secret),
        channel_access_token: mask(lineSetting.channel_access_token),
        bot_user_id: lineSetting.bot_user_id,
        bot_basic_id: lineSetting.bot_basic_id,
        bot_display_name: lineSetting.bot_display_name,
        is_active: lineSetting.is_active,
        connected_at: lineSetting.connected_at,
        last_webhook_at: lineSetting.last_webhook_at,
        webhook_url: webhookUrl(),
      }

// ---- Googleカレンダー連携用ヘルパー ----

/**
 * 認可URLへの遷移でページ全体がリロードされるため、モジュールスコープの状態では接続結果が消える。
 * 連携状態のみ sessionStorage に保持し、コールバック復帰後も接続を引き継ぐ。
 */
const GOOGLE_CALENDAR_STORAGE_KEY = 'rb-mock-google-calendar'

const GOOGLE_SETTINGS_PATH = '/settings/google-calendar'

/** モックの接続先 Google アカウント（RBのログインメールとは別物） */
const GOOGLE_ACCOUNT_EMAIL = 'rb.mock@gmail.com'

interface MockGoogleCalendarState {
  mode: GoogleCalendarMode | null
  connections: GoogleCalendarConnection[]
  nextId: number
}

const defaultGoogleCalendarState = (): MockGoogleCalendarState => ({
  mode: null,
  connections: [],
  nextId: 1,
})

const loadGoogleCalendarState = (): MockGoogleCalendarState => {
  try {
    const stored = sessionStorage.getItem(GOOGLE_CALENDAR_STORAGE_KEY)
    return stored ? (JSON.parse(stored) as MockGoogleCalendarState) : defaultGoogleCalendarState()
  } catch {
    return defaultGoogleCalendarState()
  }
}

// モードは未設定・接続0件から開始する（モード設定 → 接続 → カレンダー変更 → 解除 を辿れる）
const googleCalendar: MockGoogleCalendarState = loadGoogleCalendarState()

const saveGoogleCalendarState = (): void => {
  try {
    sessionStorage.setItem(GOOGLE_CALENDAR_STORAGE_KEY, JSON.stringify(googleCalendar))
  } catch {
    // 保存できない環境では引き継ぎのみ諦め、メモリ上の状態で動作を続ける
  }
}

const mockCalendarEntries = (): GoogleCalendarListEntry[] => [
  { id: GOOGLE_ACCOUNT_EMAIL, summary: mockUser.name, primary: true },
  { id: 'rbmock-work@group.calendar.google.com', summary: '仕事用カレンダー', primary: false },
  { id: 'ja.japanese#holiday@group.v.calendar.google.com', summary: '日本の祝日', primary: false },
]

const googleSettingsResponse = (): {
  mode: GoogleCalendarMode | null
  connections: GoogleCalendarConnection[]
} => ({
  mode: googleCalendar.mode,
  connections: structuredClone([...googleCalendar.connections].sort((a, b) => a.id - b.id)),
})

/**
 * 初回同期はキュー経由のため接続直後の取得では last_synced_at が入らない。
 * 応答を組み立てた後に同期済みへ進め、次回の取得で「同期待ち」→「最終同期」の遷移を再現する。
 */
const completeGoogleInitialSync = (): void => {
  const pending = googleCalendar.connections.filter(
    (connection) => connection.status === 'active' && connection.last_synced_at === null,
  )
  if (pending.length === 0) return
  for (const connection of pending) {
    connection.last_synced_at = toIsoWithOffset(new Date())
  }
  saveGoogleCalendarState()
}

/** 操作できる接続のみ返す（per_staff は本人、shared はオーナー・マネージャー。他は 404） */
const findOperableConnection = (id: number): GoogleCalendarConnection | undefined => {
  const connection = googleCalendar.connections.find((item) => item.id === id)
  if (!connection) return undefined
  if (connection.user === null) {
    return mockUser.role === 'owner' || mockUser.role === 'manager' ? connection : undefined
  }
  return connection.user.id === mockUser.id ? connection : undefined
}

/**
 * Google の同意画面の代わりに、その場で接続を作って設定画面へ戻すURLを返す。
 * 検証用に、遷移前のURLのクエリで結果を切り替える:
 *   ?mock_error={code}            → 接続せず ?error={code} で戻る（未知の値も指定できる）
 *   ?mock_status=needs_reconnect  → 要再接続の接続を作る
 */
const buildMockAuthUrl = (): string => {
  const params = new URLSearchParams(window.location.search)
  const errorCode = params.get('mock_error')
  if (errorCode !== null) {
    return `${window.location.origin}${GOOGLE_SETTINGS_PATH}?error=${encodeURIComponent(errorCode)}`
  }

  const status = params.get('mock_status') === 'needs_reconnect' ? 'needs_reconnect' : 'active'
  const user = googleCalendar.mode === 'per_staff' ? { id: mockUser.id, name: mockUser.name } : null
  const existing = googleCalendar.connections.find(
    (item) => (item.user?.id ?? null) === (user?.id ?? null),
  )
  if (existing) {
    // 同一アカウントでの再接続は既存の接続を更新する
    existing.status = status
    existing.last_synced_at = null
  } else {
    googleCalendar.connections.push({
      id: googleCalendar.nextId++,
      user,
      google_account_email: GOOGLE_ACCOUNT_EMAIL,
      calendar_id: PRIMARY_CALENDAR_ID,
      status,
      last_synced_at: null,
    })
  }
  saveGoogleCalendarState()
  return `${window.location.origin}${GOOGLE_SETTINGS_PATH}?connected=1`
}

/** from..to（YYYY-MM-DD, 両端含む）の各日を返す */
const eachDate = (from: string, to: string): Date[] => {
  const [fy = 0, fm = 0, fd = 0] = from.split('-').map(Number)
  const [ty = 0, tm = 0, td = 0] = to.split('-').map(Number)
  if (!fy || !fm || !fd || !ty || !tm || !td) return []
  const dates: Date[] = []
  const cursor = new Date(fy, fm - 1, fd)
  const end = new Date(ty, tm - 1, td)
  while (cursor <= end && dates.length <= 60) {
    dates.push(new Date(cursor))
    cursor.setDate(cursor.getDate() + 1)
  }
  return dates
}

/**
 * 外部予定（busy ブロック）のモック。接続があるモードでのみ、各日 12:00-13:00 の外部予定を返す。
 * per_staff は接続スタッフの列（user_id）を、shared はサロン全体（user_id=null）を塞ぐ。
 * 未連携（mode=null / 接続0件）では空配列。
 */
const mockBusyBlocks = (from: string, to: string): BusyBlock[] => {
  if (googleCalendar.mode === null || googleCalendar.connections.length === 0) return []
  const targetUserIds: (number | null)[] =
    googleCalendar.mode === 'shared'
      ? [null]
      : googleCalendar.connections.flatMap((connection) =>
          connection.user ? [connection.user.id] : [],
        )
  if (targetUserIds.length === 0) return []

  const blocks: BusyBlock[] = []
  let id = 1
  for (const date of eachDate(from, to)) {
    const start = new Date(date)
    start.setHours(12, 0, 0, 0)
    const end = new Date(date)
    end.setHours(13, 0, 0, 0)
    for (const userId of targetUserIds) {
      blocks.push({
        id: id++,
        start_at: toIsoWithOffset(start),
        end_at: toIsoWithOffset(end),
        user_id: userId,
      })
    }
  }
  return blocks
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
      const now = new Date()
      const today = toLocalDateString(now)
      const todayReservations = reservations
        .filter(
          (reservation) =>
            toLocalDateString(reservation.start_at) === today &&
            (reservation.status === 'reserved' || reservation.status === 'visited'),
        )
        .sort((a, b) => a.start_at.localeCompare(b.start_at))

      const trendSales = [182000, 210000, 198000, 246000, 289000, 324000]
      const salesTrend = trendSales.map((sales, index) => {
        const date = new Date(
          now.getFullYear(),
          now.getMonth() - (trendSales.length - 1 - index),
          1,
        )
        return {
          month: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`,
          sales,
        }
      })

      const popularCounts = [14, 11, 8, 6, 3]
      const popularMenus = menus.slice(0, 5).map((menu, index) => ({
        menu_id: menu.id,
        name: menu.name,
        price: menu.price,
        count: popularCounts[index] ?? 1,
      }))

      return respond(config, {
        data: {
          kpis: {
            new_customers: { current: 12, previous: 10 },
            reservations: { current: 28, previous: 25 },
            sales: { current: 324000, previous: 300000 },
            repeat_rate: { current: 78, previous: 73 },
          },
          sales_trend: salesTrend,
          today_reservations: todayReservations,
          popular_menus: popularMenus,
          customer_segments: { new: 28, repeat: 42, dormant: 6, other: 4 },
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
    if (method === 'get' && url === '/records') {
      const status: string = config.params?.status ?? ''
      const keyword: string = config.params?.keyword ?? ''
      const list = records
        .filter((r) => (status === '' ? true : r.status === status))
        .filter((r) =>
          keyword === ''
            ? true
            : [r.customer.name, r.customer.kana].some((v) => v.includes(keyword)),
        )
        .sort(
          (a, b) =>
            new Date(b.visited_at).getTime() - new Date(a.visited_at).getTime() || b.id - a.id,
        )
        .map(recordSummary)
      return respond(
        config,
        paginate(list, {
          ...config,
          params: { ...config.params, per_page: config.params?.per_page ?? RECORDS_PER_PAGE },
        }),
      )
    }

    const customerRecordsMatch = url.match(/^\/customers\/(\d+)\/records$/)
    if (customerRecordsMatch) {
      const customerId = Number(customerRecordsMatch[1])
      if (method === 'get') {
        const list = records.filter((r) => r.customer.id === customerId).map(recordSummary)
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
          source: 'staff',
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

    // ---- LINE Settings ----
    if (url === '/line-settings') {
      if (method === 'get') {
        return respond(config, { data: toLineSettingResponse() })
      }
      if (method === 'put') {
        const input = parseBody<LineSettingUpdateInput>(config)
        const errors: Record<string, string[]> = {}
        if (!input.channel_id?.trim()) errors.channel_id = ['チャネルIDを入力してください。']
        if (!input.channel_secret?.trim())
          errors.channel_secret = ['チャネルシークレットを入力してください。']
        if (!input.channel_access_token?.trim())
          errors.channel_access_token = ['チャネルアクセストークンを入力してください。']
        if (Object.keys(errors).length > 0) {
          return validationError(config, errors)
        }
        // secret / token を変更した保存では接続確認をやり直す（is_active を落とす）
        const credentialsChanged =
          lineSetting === null ||
          lineSetting.channel_secret !== input.channel_secret ||
          lineSetting.channel_access_token !== input.channel_access_token
        lineSetting = {
          ...(lineSetting ?? {
            bot_user_id: null,
            bot_basic_id: null,
            bot_display_name: null,
            connected_at: null,
            last_webhook_at: null,
          }),
          channel_id: input.channel_id,
          channel_secret: input.channel_secret,
          channel_access_token: input.channel_access_token,
          is_active: credentialsChanged ? false : (lineSetting?.is_active ?? false),
        }
        return respond(config, { data: toLineSettingResponse() })
      }
      if (method === 'delete') {
        if (lineSetting === null) return notFound(config)
        lineSetting = null
        return respond(config, null, 204)
      }
    }

    if (method === 'post' && url === '/line-settings/verify') {
      if (lineSetting === null) return notFound(config)
      // モックの失敗確認用: アクセストークンに "invalid" を含む場合のみ接続確認を失敗させる
      if (lineSetting.channel_access_token.includes('invalid')) {
        return validationError(config, {
          channel_access_token: ['LINEとの接続確認に失敗しました。'],
        })
      }
      lineSetting = {
        ...lineSetting,
        bot_user_id: 'U4af4980629abcdef1234567890abcdef',
        bot_basic_id: '@000rbmock',
        bot_display_name: `${MOCK_SALON_NAME} 公式`,
        is_active: true,
        connected_at: toIsoWithOffset(new Date()),
      }
      return respond(config, { data: toLineSettingResponse() })
    }

    // ---- Booking Page ----
    if (method === 'get' && url === '/booking-page') {
      return respond(config, {
        data: {
          booking_slug: MOCK_BOOKING_SLUG,
          booking_page_url: `${window.location.origin}/booking/${MOCK_BOOKING_SLUG}`,
        },
      })
    }

    // ---- Google Calendar ----
    if (method === 'get' && url === '/google-calendar') {
      const data = googleSettingsResponse()
      completeGoogleInitialSync()
      return respond(config, { data })
    }

    if (method === 'get' && url === '/google-calendar/busy-blocks') {
      const from: string = config.params?.from ?? toLocalDateString(new Date())
      const to: string = config.params?.to ?? from
      return respond(config, { data: mockBusyBlocks(from, to) })
    }

    if (method === 'put' && url === '/google-calendar/mode') {
      const input = parseBody<GoogleCalendarModeUpdateInput>(config)
      if (input.mode !== 'per_staff' && input.mode !== 'shared') {
        return validationError(config, { mode: ['接続単位が正しくありません。'] })
      }
      // モードを変更すると既存の接続はすべて解除される
      if (input.mode !== googleCalendar.mode) {
        googleCalendar.connections = []
      }
      googleCalendar.mode = input.mode
      saveGoogleCalendarState()
      return respond(config, { data: googleSettingsResponse() })
    }

    if (method === 'post' && url === '/google-calendar/auth-url') {
      if (googleCalendar.mode === null) {
        return validationError(config, { mode: ['先に接続単位を設定してください。'] })
      }
      return respond(config, { data: { auth_url: buildMockAuthUrl() } })
    }

    const googleCalendarListMatch = url.match(/^\/google-calendar\/connections\/(\d+)\/calendars$/)
    if (method === 'get' && googleCalendarListMatch) {
      const connection = findOperableConnection(Number(googleCalendarListMatch[1]))
      if (!connection) return notFound(config)
      if (connection.status === 'needs_reconnect') {
        return validationError(config, {
          connection: ['Googleとの接続が切れています。再接続してください。'],
        })
      }
      return respond(config, { data: mockCalendarEntries() })
    }

    const googleConnectionMatch = url.match(/^\/google-calendar\/connections\/(\d+)$/)
    if (googleConnectionMatch) {
      const id = Number(googleConnectionMatch[1])
      const connection = findOperableConnection(id)
      if (!connection) return notFound(config)
      if (method === 'put') {
        if (connection.status === 'needs_reconnect') {
          return validationError(config, {
            connection: ['Googleとの接続が切れています。再接続してください。'],
          })
        }
        const input = parseBody<GoogleCalendarConnectionUpdateInput>(config)
        // リテラル primary、または calendarList が返す id のみ受け付ける
        const isKnownCalendar =
          input.calendar_id === PRIMARY_CALENDAR_ID ||
          mockCalendarEntries().some((entry) => entry.id === input.calendar_id)
        if (!isKnownCalendar) {
          return validationError(config, { calendar_id: ['指定したカレンダーは選択できません。'] })
        }
        connection.calendar_id = input.calendar_id
        // busy を再構築するため同期待ちへ戻る
        connection.last_synced_at = null
        saveGoogleCalendarState()
        return respond(config, { data: structuredClone(connection) })
      }
      if (method === 'delete') {
        googleCalendar.connections = googleCalendar.connections.filter((item) => item.id !== id)
        saveGoogleCalendarState()
        return respond(config, null, 204)
      }
    }

    // ---- Public Booking（/api/public/v1 系。publicApiClient から利用） ----
    const publicSalonMatch = url.match(/^\/salons\/([a-z0-9]+)$/)
    if (method === 'get' && publicSalonMatch) {
      if (publicSalonMatch[1] !== MOCK_BOOKING_SLUG) return notFound(config)
      return respond(config, {
        data: {
          name: MOCK_SALON_NAME,
          business_hours: sortedBusinessHours(),
          menus: sortedMenus()
            .filter((menu) => menu.is_active)
            .map(({ id, name, price, duration_minutes }) => ({
              id,
              name,
              price,
              duration_minutes,
            })),
          staff: mockStaffUsers.map(({ id, name }) => ({ id, name })),
        },
      })
    }

    const availabilityMatch = url.match(/^\/salons\/([a-z0-9]+)\/availability$/)
    if (method === 'get' && availabilityMatch) {
      if (availabilityMatch[1] !== MOCK_BOOKING_SLUG) return notFound(config)
      const menu = menus.find((m) => m.id === Number(config.params?.menu_id) && m.is_active)
      if (!menu) {
        return validationError(config, { menu_id: ['指定したメニューは利用できません。'] })
      }
      const userId = config.params?.user_id != null ? Number(config.params.user_id) : null
      const candidates =
        userId !== null ? mockStaffUsers.filter((u) => u.id === userId) : mockStaffUsers
      if (candidates.length === 0) {
        return validationError(config, { user_id: ['指定したスタッフは選択できません。'] })
      }
      const [year = 0, month = 0, day = 0] = String(config.params?.date ?? '')
        .split('-')
        .map(Number)
      if (!year || !month || !day) {
        return validationError(config, { date: ['日付の形式が正しくありません。'] })
      }
      const date = new Date(year, month - 1, day)
      const data = listSlotStartMinutes(businessHourOf(date.getDay()), menu.duration_minutes)
        .map((min) => slotToIso(date, min))
        .filter((iso) => isWithinBookingWindow(new Date(iso)))
        .filter((iso) => {
          const startMs = new Date(iso).getTime()
          const endMs = startMs + menu.duration_minutes * 60000
          return candidates.some((u) => isStaffFree(u.id, startMs, endMs))
        })
        .map((start_at) => ({ start_at }))
      return respond(config, { data })
    }

    const publicReservationMatch = url.match(/^\/salons\/([a-z0-9]+)\/reservations$/)
    if (method === 'post' && publicReservationMatch) {
      if (publicReservationMatch[1] !== MOCK_BOOKING_SLUG) return notFound(config)
      const input = parseBody<PublicReservationRequest>(config)

      const customerErrors: Record<string, string[]> = {}
      if (!input.name?.trim()) customerErrors.name = ['お名前を入力してください。']
      else if (input.name.length > 100)
        customerErrors.name = ['お名前は100文字以内で入力してください。']
      if (!input.kana?.trim()) customerErrors.kana = ['フリガナを入力してください。']
      else if (input.kana.length > 100)
        customerErrors.kana = ['フリガナは100文字以内で入力してください。']
      if (!input.phone?.trim()) customerErrors.phone = ['電話番号を入力してください。']
      else if (input.phone.length > 20)
        customerErrors.phone = ['電話番号は20文字以内で入力してください。']
      if (Object.keys(customerErrors).length > 0) {
        return validationError(config, customerErrors)
      }

      const menu = menus.find((m) => m.id === input.menu_id && m.is_active)
      if (!menu) {
        return validationError(config, { menu_id: ['指定したメニューは利用できません。'] })
      }
      const candidates =
        input.user_id != null
          ? mockStaffUsers.filter((u) => u.id === input.user_id)
          : mockStaffUsers
      if (candidates.length === 0) {
        return validationError(config, { user_id: ['指定したスタッフは選択できません。'] })
      }

      const start = new Date(input.start_at)
      if (Number.isNaN(start.getTime())) {
        return validationError(config, { start_at: ['日時の形式が正しくありません。'] })
      }
      const startMinutes = start.getHours() * 60 + start.getMinutes()
      const gridMinutes = listSlotStartMinutes(
        businessHourOf(start.getDay()),
        menu.duration_minutes,
      )
      if (!gridMinutes.includes(startMinutes)) {
        return validationError(config, {
          start_at: ['営業時間外のため、この日時にはご予約いただけません。'],
        })
      }
      if (!isWithinBookingWindow(start)) {
        return validationError(config, {
          start_at: ['この日時はご予約いただけません。予約可能期間は60日先までです。'],
        })
      }

      const normalizedPhone = normalizePhone(input.phone)
      const futureReserved = reservations.filter(
        (r) =>
          r.status === 'reserved' &&
          Date.now() < new Date(r.start_at).getTime() &&
          r.customer.phone !== null &&
          normalizePhone(r.customer.phone) === normalizedPhone,
      ).length
      if (futureReserved >= 3) {
        return validationError(config, {
          phone: ['同じ電話番号でのご予約が上限（3件）に達しています。'],
        })
      }

      const startMs = start.getTime()
      const endMs = startMs + menu.duration_minutes * 60000
      const assigned = [...candidates]
        .sort((a, b) => a.id - b.id)
        .find((u) => isStaffFree(u.id, startMs, endMs))
      if (!assigned) {
        return validationError(config, {
          start_at: ['指定した時間帯は埋まってしまいました。別の日時をお選びください。'],
        })
      }

      // 顧客マッチング（phone 正規化・完全一致。複数一致時は id 最小、不一致なら新規作成）
      let customer = customers
        .filter((c) => c.phone !== null && normalizePhone(c.phone) === normalizedPhone)
        .sort((a, b) => a.id - b.id)[0]
      if (!customer) {
        const nowIso = new Date().toISOString()
        customer = {
          id: nextCustomerId++,
          name: input.name,
          kana: input.kana,
          gender: null,
          birthday: null,
          phone: input.phone,
          email: null,
          memo: null,
          first_visit_at: null,
          last_visit_at: null,
          created_at: nowIso,
          updated_at: nowIso,
        }
        customers = [customer, ...customers]
      }

      const nowIso = toIsoWithOffset(new Date())
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
        user: { id: assigned.id, name: assigned.name },
        start_at: input.start_at,
        end_at: toIsoWithOffset(new Date(endMs)),
        status: 'reserved',
        source: 'web',
        note: null,
        created_at: nowIso,
        updated_at: nowIso,
      }
      reservations = [...reservations, reservation]

      const bookingToken = randomBookingToken()
      publicBookingTokens.set(bookingToken, reservation.id)
      return respond(
        config,
        {
          data: {
            booking_token: bookingToken,
            start_at: reservation.start_at,
            end_at: reservation.end_at,
            menu_name: menu.name,
            staff_name: assigned.name,
            line: {
              add_friend_url: 'https://line.me/R/ti/p/@000rbmock',
              link_code: randomLinkCode(),
            },
          },
        },
        201,
      )
    }

    const publicBookingMatch = url.match(/^\/bookings\/([A-Za-z0-9]{32})$/)
    if (method === 'get' && publicBookingMatch) {
      const reservationId = publicBookingTokens.get(publicBookingMatch[1] ?? '')
      const reservation = reservations.find((r) => r.id === reservationId)
      if (!reservation) return notFound(config)
      return respond(config, { data: toPublicBooking(reservation) })
    }

    const publicCancelMatch = url.match(/^\/bookings\/([A-Za-z0-9]{32})\/cancel$/)
    if (method === 'post' && publicCancelMatch) {
      const reservationId = publicBookingTokens.get(publicCancelMatch[1] ?? '')
      const reservation = reservations.find((r) => r.id === reservationId)
      if (!reservation) return notFound(config)
      // 条件付き UPDATE 相当: reserved かつ開始前のみキャンセル可、それ以外は 409
      if (
        reservation.status !== 'reserved' ||
        Date.now() >= new Date(reservation.start_at).getTime()
      ) {
        return conflictError(config, 'この予約はキャンセルできません。')
      }
      reservation.status = 'cancelled'
      reservation.updated_at = toIsoWithOffset(new Date())
      return respond(config, { data: toPublicBooking(reservation) })
    }

    return notFound(config)
  }
}
