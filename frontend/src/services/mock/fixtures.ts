import type {
  BusinessHour,
  Customer,
  FeatureFlags,
  FeatureKey,
  Menu,
  Photo,
  PlanCatalogItem,
  PlanCode,
  Reservation,
  StaffUser,
  Subscription,
  TreatmentRecord,
  User,
} from '@/types'
import { toIsoWithOffset } from '@/utils/format'

/**
 * 開発用モックデータ（VITE_USE_MOCK=true のときのみ使用）
 */

/** 公開予約ページ用のモックサロン（/booking/{slug} で参照する16文字英数小文字） */
export const MOCK_BOOKING_SLUG = 'rbmocksalon00001'
export const MOCK_SALON_NAME = 'Realize Beauty 表参道'

/** モックの初期プラン。dev:mock 中に mockAdapter が書き換える */
export const MOCK_INITIAL_PLAN: PlanCode = 'pro'

export const buildMockFeatures = (plan: PlanCode | null): FeatureFlags => {
  const enabled = plan ? MOCK_PLAN_FEATURES[plan] : []
  return Object.fromEntries(
    MOCK_FEATURE_KEYS.map((key) => [key, enabled.includes(key)]),
  ) as FeatureFlags
}

const MOCK_FEATURE_KEYS: FeatureKey[] = [
  'customer',
  'medical_record',
  'photo',
  'reservation',
  'google_calendar',
  'line',
  'ai_summary',
  'analytics',
]

export const MOCK_PLAN_FEATURES: Record<PlanCode, FeatureKey[]> = {
  lite: ['customer', 'medical_record', 'photo'],
  standard: ['customer', 'medical_record', 'photo', 'reservation', 'google_calendar', 'line'],
  pro: MOCK_FEATURE_KEYS,
}

export const MOCK_PLAN_CATALOG: PlanCatalogItem[] = [
  {
    code: 'lite',
    label: 'Lite',
    monthly_price: 980,
    features: MOCK_PLAN_FEATURES.lite,
    is_purchasable: true,
  },
  {
    code: 'standard',
    label: 'Standard',
    monthly_price: 1980,
    features: MOCK_PLAN_FEATURES.standard,
    is_purchasable: true,
  },
  {
    code: 'pro',
    label: 'Pro',
    monthly_price: 3980,
    features: MOCK_PLAN_FEATURES.pro,
    is_purchasable: true,
  },
]

export const buildMockSubscription = (plan: PlanCode): Subscription => {
  const catalog = MOCK_PLAN_CATALOG.find((item) => item.code === plan)
  const now = new Date()
  const periodEnd = new Date(now)
  periodEnd.setMonth(periodEnd.getMonth() + 1)

  return {
    plan,
    plan_label: catalog?.label ?? plan,
    monthly_price: catalog?.monthly_price ?? 0,
    status: 'active',
    status_label: '利用中',
    is_active: true,
    needs_payment_attention: false,
    cancel_at_period_end: false,
    current_period_start: now.toISOString(),
    current_period_end: periodEnd.toISOString(),
    canceled_at: null,
    ended_at: null,
    trial_ends_at: null,
    has_payment_method: true,
    is_subscribed: true,
  }
}

export const mockUser: User = {
  id: 1,
  name: '山田 太郎',
  email: 'owner@example.com',
  role: 'owner',
  plan: MOCK_INITIAL_PLAN,
  subscription_status: 'active',
  features: buildMockFeatures(MOCK_INITIAL_PLAN),
}

export const mockCustomers: Customer[] = [
  {
    id: 1,
    name: '佐藤 花子',
    kana: 'サトウ ハナコ',
    gender: 2,
    birthday: '1992-04-12',
    phone: '090-1234-5678',
    email: 'hanako@example.com',
    memo: 'カラーの持ちを気にされている。次回はトリートメント提案。',
    first_visit_at: '2025-11-02',
    last_visit_at: '2026-07-05',
    created_at: '2025-11-02T10:00:00+09:00',
    updated_at: '2026-07-05T18:30:00+09:00',
  },
  {
    id: 2,
    name: '田中 美咲',
    kana: 'タナカ ミサキ',
    gender: 2,
    birthday: '1988-09-30',
    phone: '080-2345-6789',
    email: 'misaki@example.com',
    memo: '敏感肌。施術前にパッチテスト必須。',
    first_visit_at: '2026-01-15',
    last_visit_at: '2026-07-01',
    created_at: '2026-01-15T11:00:00+09:00',
    updated_at: '2026-07-01T15:00:00+09:00',
  },
  {
    id: 3,
    name: '鈴木 一郎',
    kana: 'スズキ イチロウ',
    gender: 1,
    birthday: '1979-01-22',
    phone: '070-3456-7890',
    email: null,
    memo: null,
    first_visit_at: '2026-03-08',
    last_visit_at: '2026-06-20',
    created_at: '2026-03-08T14:00:00+09:00',
    updated_at: '2026-06-20T17:00:00+09:00',
  },
  {
    id: 4,
    name: '高橋 結衣',
    kana: 'タカハシ ユイ',
    gender: 2,
    birthday: '1997-07-07',
    phone: '090-4567-8901',
    email: 'yui@example.com',
    memo: 'ネイル月1回。ピンク系デザインが好み。',
    first_visit_at: '2026-02-14',
    last_visit_at: '2026-07-07',
    created_at: '2026-02-14T13:00:00+09:00',
    updated_at: '2026-07-07T12:00:00+09:00',
  },
  {
    id: 5,
    name: '伊藤 さくら',
    kana: 'イトウ サクラ',
    gender: 2,
    birthday: '1995-03-03',
    phone: '080-5678-9012',
    email: 'sakura@example.com',
    memo: 'まつエク利用。オフ込みで予約枠を長めに。',
    first_visit_at: '2025-12-20',
    last_visit_at: '2026-06-28',
    created_at: '2025-12-20T10:30:00+09:00',
    updated_at: '2026-06-28T16:00:00+09:00',
  },
  {
    id: 6,
    name: '渡辺 恵',
    kana: 'ワタナベ メグミ',
    gender: 2,
    birthday: '1983-12-01',
    phone: '090-6789-0123',
    email: null,
    memo: '白髪染め＋ヘッドスパのセットが定番。',
    first_visit_at: '2025-10-05',
    last_visit_at: '2026-05-30',
    created_at: '2025-10-05T09:00:00+09:00',
    updated_at: '2026-05-30T19:00:00+09:00',
  },
  {
    id: 7,
    name: '小林 直樹',
    kana: 'コバヤシ ナオキ',
    gender: 1,
    birthday: '1990-06-18',
    phone: '070-7890-1234',
    email: 'naoki@example.com',
    memo: null,
    first_visit_at: '2026-04-01',
    last_visit_at: '2026-07-02',
    created_at: '2026-04-01T15:00:00+09:00',
    updated_at: '2026-07-02T11:00:00+09:00',
  },
  {
    id: 8,
    name: '加藤 里奈',
    kana: 'カトウ リナ',
    gender: 2,
    birthday: '2000-10-25',
    phone: '080-8901-2345',
    email: 'rina@example.com',
    memo: 'ブリーチ履歴あり。ダメージケア重視。',
    first_visit_at: '2026-05-11',
    last_visit_at: '2026-06-15',
    created_at: '2026-05-11T12:00:00+09:00',
    updated_at: '2026-06-15T14:00:00+09:00',
  },
]

const photoSeed = (id: number, caption: string | null, sortOrder: number): Photo => ({
  id,
  url: `https://picsum.photos/seed/rb-${id}/600/600`,
  caption,
  sort_order: sortOrder,
})

export const mockRecords: TreatmentRecord[] = [
  {
    id: 1,
    customer: { id: 1, name: '佐藤 花子', kana: 'サトウ ハナコ', phone: '090-1234-5678' },
    user: { id: 1, name: '山田 太郎' },
    status: 'completed',
    visited_at: '2026-07-05T14:00:00+09:00',
    ai_summary:
      'カラーリタッチとトリートメントを実施。前回よりも明るめの8トーンへ調整。次回は6週間後のリタッチと集中トリートメントを提案。',
    blocks: [
      {
        id: 1,
        label: '施術内容',
        content: 'カラーリタッチ（8トーン アッシュブラウン）＋トリートメント',
        sort_order: 0,
      },
      {
        id: 2,
        label: '使用薬剤',
        content: 'イルミナカラー オーシャン 8 / オキシ3%',
        sort_order: 1,
      },
      { id: 3, label: '放置時間', content: '25分', sort_order: 2 },
      { id: 4, label: '次回提案', content: '6週間後リタッチ＋集中トリートメント', sort_order: 3 },
    ],
    photos: [photoSeed(1, '仕上がり（後ろ）', 0), photoSeed(2, '仕上がり（横）', 1)],
    created_at: '2026-07-05T15:30:00+09:00',
    updated_at: '2026-07-05T15:30:00+09:00',
  },
  {
    id: 2,
    customer: { id: 1, name: '佐藤 花子', kana: 'サトウ ハナコ', phone: '090-1234-5678' },
    user: { id: 1, name: '山田 太郎' },
    status: 'completed',
    visited_at: '2026-05-24T11:00:00+09:00',
    ai_summary: null,
    blocks: [
      { id: 5, label: '施術内容', content: 'カット＋フルカラー', sort_order: 0 },
      {
        id: 6,
        label: 'カウンセリング',
        content: '毛先のダメージが気になるとのこと。ホームケアにオイルを提案。',
        sort_order: 1,
      },
    ],
    photos: [photoSeed(3, null, 0)],
    created_at: '2026-05-24T12:30:00+09:00',
    updated_at: '2026-05-24T12:30:00+09:00',
  },
  {
    id: 3,
    customer: { id: 4, name: '高橋 結衣', kana: 'タカハシ ユイ', phone: '090-4567-8901' },
    user: { id: 1, name: '山田 太郎' },
    status: 'completed',
    visited_at: '2026-07-07T10:00:00+09:00',
    ai_summary: null,
    blocks: [
      {
        id: 7,
        label: 'デザイン',
        content: 'ワンカラー（くすみピンク）＋ラメグラデーション',
        sort_order: 0,
      },
      { id: 8, label: '使用カラー', content: 'PK-04 / GL-11', sort_order: 1 },
      { id: 9, label: 'パーツ', content: 'ゴールドスタッズ 小 ×6', sort_order: 2 },
    ],
    photos: [photoSeed(4, '右手', 0), photoSeed(5, '左手', 1), photoSeed(6, 'アップ', 2)],
    created_at: '2026-07-07T11:30:00+09:00',
    updated_at: '2026-07-07T11:30:00+09:00',
  },
  {
    id: 4,
    customer: { id: 2, name: '田中 美咲', kana: 'タナカ ミサキ', phone: '080-2345-6789' },
    user: { id: 1, name: '山田 太郎' },
    status: 'draft',
    visited_at: '2026-07-01T13:00:00+09:00',
    ai_summary: null,
    blocks: [
      { id: 10, label: '肌状態', content: '頬に軽い乾燥。Tゾーンは皮脂多め。', sort_order: 0 },
      { id: 11, label: 'ホームケア', content: '保湿重視のスキンケアを継続。', sort_order: 1 },
    ],
    photos: [],
    created_at: '2026-07-01T14:00:00+09:00',
    updated_at: '2026-07-01T14:00:00+09:00',
  },
]

export const mockStaffUsers: StaffUser[] = [
  { id: 1, name: '山田 太郎', role: 'owner' },
  { id: 2, name: '田中 美咲', role: 'staff' },
  { id: 3, name: '佐藤 恵', role: 'staff' },
]

export const mockMenus: Menu[] = [
  {
    id: 1,
    name: 'カット',
    price: 5500,
    duration_minutes: 60,
    display_order: 1,
    is_active: true,
    created_at: '2026-07-01T10:00:00+09:00',
    updated_at: '2026-07-01T10:00:00+09:00',
  },
  {
    id: 2,
    name: 'カラー',
    price: 8800,
    duration_minutes: 90,
    display_order: 2,
    is_active: true,
    created_at: '2026-07-01T10:00:00+09:00',
    updated_at: '2026-07-01T10:00:00+09:00',
  },
  {
    id: 3,
    name: 'パーマ（旧）',
    price: 9900,
    duration_minutes: 120,
    display_order: 3,
    is_active: false,
    created_at: '2026-07-01T10:00:00+09:00',
    updated_at: '2026-07-01T10:00:00+09:00',
  },
]

export const mockBusinessHours: BusinessHour[] = [
  { day_of_week: 0, is_closed: true, open_time: '09:00', close_time: '19:00' },
  { day_of_week: 1, is_closed: false, open_time: '09:00', close_time: '19:00' },
  { day_of_week: 2, is_closed: false, open_time: '09:00', close_time: '19:00' },
  { day_of_week: 3, is_closed: false, open_time: '09:00', close_time: '19:00' },
  { day_of_week: 4, is_closed: false, open_time: '09:00', close_time: '19:00' },
  { day_of_week: 5, is_closed: false, open_time: '09:00', close_time: '21:00' },
  { day_of_week: 6, is_closed: false, open_time: '09:00', close_time: '18:00' },
]

const todayAt = (hours: number, minutes: number): string => {
  const date = new Date()
  date.setHours(hours, minutes, 0, 0)
  return toIsoWithOffset(date)
}

/** 当日ベースの予約フィクスチャ（カレンダー確認用の最小セット） */
export const buildMockReservations = (): Reservation[] => [
  {
    id: 1,
    customer: { id: 1, name: '佐藤 花子', kana: 'サトウ ハナコ', phone: '090-1234-5678' },
    menu: { id: 1, name: 'カット', price: 5500, duration_minutes: 60, is_active: true },
    user: { id: 1, name: '山田 太郎' },
    start_at: todayAt(10, 0),
    end_at: todayAt(11, 0),
    status: 'reserved',
    source: 'staff',
    note: null,
    created_at: todayAt(9, 0),
    updated_at: todayAt(9, 0),
  },
  {
    id: 2,
    customer: { id: 2, name: '田中 美咲', kana: 'タナカ ミサキ', phone: '080-2345-6789' },
    menu: { id: 2, name: 'カラー', price: 8800, duration_minutes: 90, is_active: true },
    user: { id: 2, name: '田中 美咲' },
    start_at: todayAt(13, 0),
    end_at: todayAt(14, 30),
    status: 'visited',
    source: 'web',
    note: '前回より明るめのカラー希望',
    created_at: todayAt(9, 0),
    updated_at: todayAt(9, 0),
  },
  {
    id: 3,
    customer: { id: 4, name: '高橋 結衣', kana: 'タカハシ ユイ', phone: '090-4567-8901' },
    menu: { id: 1, name: 'カット', price: 5500, duration_minutes: 60, is_active: true },
    user: { id: 1, name: '山田 太郎' },
    start_at: todayAt(10, 30),
    end_at: todayAt(11, 30),
    status: 'cancelled',
    source: 'web',
    note: '体調不良のためキャンセル',
    created_at: todayAt(9, 0),
    updated_at: todayAt(9, 30),
  },
]
