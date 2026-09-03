import type { Reservation } from './reservation'

export interface KpiComparison {
  current: number
  previous: number
}

export interface DashboardKpis {
  new_customers: KpiComparison
  reservations: KpiComparison
  sales: KpiComparison
  repeat_rate: KpiComparison
}

export interface SalesTrendPoint {
  month: string
  sales: number
}

export interface PopularMenu {
  menu_id: number
  name: string
  price: number | null
  count: number
}

export interface CustomerSegments {
  new: number
  repeat: number
  dormant: number
  other: number
}

export interface DashboardSummary {
  kpis: DashboardKpis
  today_reservations: Reservation[]
  /** 以下3件は高度な分析。analytics を含まないプランでは null が返る */
  sales_trend: SalesTrendPoint[] | null
  popular_menus: PopularMenu[] | null
  customer_segments: CustomerSegments | null
}
