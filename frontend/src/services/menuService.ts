import { apiClient } from './apiClient'
import type { ApiEnvelope, Menu, MenuCreateInput, MenuListParams, MenuUpdateInput } from '@/types'

export const menuService = {
  async list(params: MenuListParams = {}): Promise<Menu[]> {
    const { data } = await apiClient.get<ApiEnvelope<Menu[]>>('/menus', { params })
    return data.data
  },

  async get(id: number): Promise<Menu> {
    const { data } = await apiClient.get<ApiEnvelope<Menu>>(`/menus/${id}`)
    return data.data
  },

  async create(input: MenuCreateInput): Promise<Menu> {
    const { data } = await apiClient.post<ApiEnvelope<Menu>>('/menus', input)
    return data.data
  },

  async update(id: number, input: MenuUpdateInput): Promise<Menu> {
    const { data } = await apiClient.put<ApiEnvelope<Menu>>(`/menus/${id}`, input)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await apiClient.delete(`/menus/${id}`)
  },
}
