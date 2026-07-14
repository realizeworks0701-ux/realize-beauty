import type { Customer } from './customer'
import type { TreatmentRecord } from './record'

export interface DashboardSummary {
  today_customers: number
  new_customers: number
  total_customers: number
  records_this_month: number
  today_reservations: number
  recent_customers: Customer[]
  recent_records: TreatmentRecord[]
}
