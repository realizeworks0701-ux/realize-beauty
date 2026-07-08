import { apiClient } from './apiClient'
import type { ApiEnvelope, User } from '@/types'

export interface LoginResult {
  token: string
  user: User
}

export const authService = {
  async login(email: string, password: string): Promise<LoginResult> {
    const { data } = await apiClient.post<ApiEnvelope<LoginResult>>('/auth/login', {
      email,
      password,
    })
    return data.data
  },

  async logout(): Promise<void> {
    await apiClient.post('/auth/logout')
  },

  async me(): Promise<User> {
    const { data } = await apiClient.get<ApiEnvelope<User>>('/auth/me')
    return data.data
  },
}
