import { apiClient } from './apiClient'
import type { ApiEnvelope, BusinessHour, BusinessHoursUpdateInput } from '@/types'

export const businessHourService = {
  async list(): Promise<BusinessHour[]> {
    const { data } = await apiClient.get<ApiEnvelope<BusinessHour[]>>('/business-hours')
    return data.data
  },

  async updateAll(input: BusinessHoursUpdateInput): Promise<BusinessHour[]> {
    const { data } = await apiClient.put<ApiEnvelope<BusinessHour[]>>('/business-hours', input)
    return data.data
  },
}
