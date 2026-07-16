import { apiClient } from './apiClient'
import type { ApiEnvelope, BookingPage, LineSetting, LineSettingUpdateInput } from '@/types'

export const lineSettingsService = {
  async get(): Promise<LineSetting> {
    const { data } = await apiClient.get<ApiEnvelope<LineSetting>>('/line-settings')
    return data.data
  },

  async update(input: LineSettingUpdateInput): Promise<LineSetting> {
    const { data } = await apiClient.put<ApiEnvelope<LineSetting>>('/line-settings', input)
    return data.data
  },

  async verify(): Promise<LineSetting> {
    const { data } = await apiClient.post<ApiEnvelope<LineSetting>>('/line-settings/verify')
    return data.data
  },

  async disconnect(): Promise<void> {
    await apiClient.delete('/line-settings')
  },

  async getBookingPage(): Promise<BookingPage> {
    const { data } = await apiClient.get<ApiEnvelope<BookingPage>>('/booking-page')
    return data.data
  },
}
