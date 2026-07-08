import { apiClient } from './apiClient'
import type { ApiEnvelope, DashboardSummary } from '@/types'

export const dashboardService = {
  async getSummary(): Promise<DashboardSummary> {
    const { data } = await apiClient.get<ApiEnvelope<DashboardSummary>>('/dashboard')
    return data.data
  },
}
