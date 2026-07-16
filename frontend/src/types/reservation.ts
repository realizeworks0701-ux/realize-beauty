import type { MenuSummary } from './menu'

export type ReservationStatus = 'reserved' | 'visited' | 'cancelled' | 'no_show'

/** 予約の登録経路（staff=サロン側で登録、web=公開Web予約） */
export type ReservationSource = 'staff' | 'web'

export interface ReservationCustomerSummary {
  id: number
  name: string
  kana: string
  phone: string | null
}

export interface ReservationUserSummary {
  id: number
  name: string
}

export interface Reservation {
  id: number
  customer: ReservationCustomerSummary
  menu: MenuSummary
  user: ReservationUserSummary
  start_at: string
  end_at: string
  status: ReservationStatus
  source: ReservationSource
  note: string | null
  created_at: string
  updated_at: string
}

export interface ReservationCreateInput {
  customer_id: number
  menu_id: number
  user_id: number
  start_at: string
  note?: string | null
}

export interface ReservationUpdateInput {
  customer_id?: number
  menu_id?: number
  user_id?: number
  start_at?: string
  status?: ReservationStatus
  note?: string | null
}

export interface ReservationListParams {
  from?: string
  to?: string
  user_id?: number
  status?: ReservationStatus
}
