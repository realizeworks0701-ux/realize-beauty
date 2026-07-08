import { apiClient } from './apiClient'
import type {
  ApiEnvelope,
  Paginated,
  RecordCreateInput,
  RecordUpdateInput,
  TreatmentRecord,
} from '@/types'

export const recordService = {
  async listByCustomer(
    customerId: number,
    params: { page?: number; per_page?: number } = {},
  ): Promise<Paginated<TreatmentRecord>> {
    const { data } = await apiClient.get<Paginated<TreatmentRecord>>(
      `/customers/${customerId}/records`,
      { params },
    )
    return data
  },

  async create(customerId: number, input: RecordCreateInput): Promise<TreatmentRecord> {
    const { data } = await apiClient.post<ApiEnvelope<TreatmentRecord>>(
      `/customers/${customerId}/records`,
      input,
    )
    return data.data
  },

  async get(id: number): Promise<TreatmentRecord> {
    const { data } = await apiClient.get<ApiEnvelope<TreatmentRecord>>(`/records/${id}`)
    return data.data
  },

  async update(id: number, input: RecordUpdateInput): Promise<TreatmentRecord> {
    const { data } = await apiClient.patch<ApiEnvelope<TreatmentRecord>>(`/records/${id}`, input)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await apiClient.delete(`/records/${id}`)
  },

  async summarize(id: number): Promise<string> {
    const { data } = await apiClient.post<ApiEnvelope<{ summary: string }>>(
      `/records/${id}/summarize`,
    )
    return data.data.summary
  },
}
