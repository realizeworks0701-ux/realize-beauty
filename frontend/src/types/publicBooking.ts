import type { BusinessHour } from './businessHour'
import type { ReservationStatus } from './reservation'

/** 公開予約ページ用メニュー（有効メニューのみ・display_order 昇順） */
export interface PublicMenu {
  id: number
  name: string
  price: number
  duration_minutes: number
}

/** 公開予約ページ用スタッフ（id / name のみ） */
export interface PublicStaff {
  id: number
  name: string
}

/** GET /salons/{booking_slug} — 公開サロン情報 */
export interface PublicSalon {
  name: string
  business_hours: BusinessHour[]
  menus: PublicMenu[]
  staff: PublicStaff[]
}

/** 30分刻みの空き枠（開始日時のみ） */
export interface AvailabilitySlot {
  start_at: string
}

/** GET /salons/{booking_slug}/availability のクエリ（user_id 省略時は指名なし） */
export interface PublicAvailabilityParams {
  date: string
  menu_id: number
  user_id?: number
}

/** POST /salons/{booking_slug}/reservations リクエスト（user_id null/省略は指名なし） */
export interface PublicReservationRequest {
  menu_id: number
  user_id?: number | null
  start_at: string
  name: string
  kana: string
  phone: string
}

/** 予約完了画面に表示する LINE 連携案内（友だち追加 + ワンタイム連携コード） */
export interface LineLinkGuide {
  add_friend_url: string
  link_code: string
}

/** 予約作成レスポンス（line はサロン未連携または顧客連携済みの場合 null） */
export interface PublicReservationResponse {
  booking_token: string
  start_at: string
  end_at: string
  menu_name: string
  staff_name: string
  line: LineLinkGuide | null
}

/** GET /bookings/{booking_token} — 予約概要（個人情報は含めない） */
export interface PublicBooking {
  salon_name: string
  menu_name: string
  staff_name: string
  start_at: string
  end_at: string
  status: ReservationStatus
  can_cancel: boolean
}
