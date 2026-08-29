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
  sales_trend: SalesTrendPoint[]
  today_reservations: Reservation[]
  popular_menus: PopularMenu[]
  customer_segments: CustomerSegments
}
