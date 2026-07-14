import { apiClient } from './apiClient'
import type { ApiEnvelope, StaffUser } from '@/types'

export const userService = {
  async list(): Promise<StaffUser[]> {
    const { data } = await apiClient.get<ApiEnvelope<StaffUser[]>>('/users')
    return data.data
  },
}
