import { apiClient } from './apiClient'
import type { ApiEnvelope, Customer, CustomerInput, CustomerListParams, Paginated } from '@/types'

export const customerService = {
  async list(params: CustomerListParams = {}): Promise<Paginated<Customer>> {
    const { data } = await apiClient.get<Paginated<Customer>>('/customers', { params })
    return data
  },

  async get(id: number): Promise<Customer> {
    const { data } = await apiClient.get<ApiEnvelope<Customer>>(`/customers/${id}`)
    return data.data
  },

  async create(input: CustomerInput): Promise<Customer> {
    const { data } = await apiClient.post<ApiEnvelope<Customer>>('/customers', input)
    return data.data
  },

  async update(id: number, input: CustomerInput): Promise<Customer> {
    const { data } = await apiClient.put<ApiEnvelope<Customer>>(`/customers/${id}`, input)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await apiClient.delete(`/customers/${id}`)
  },
}
