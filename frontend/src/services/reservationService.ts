import { apiClient } from './apiClient'
import type {
  ApiEnvelope,
  Reservation,
  ReservationCreateInput,
  ReservationListParams,
  ReservationUpdateInput,
} from '@/types'

export const reservationService = {
  async list(params: ReservationListParams = {}): Promise<Reservation[]> {
    const { data } = await apiClient.get<ApiEnvelope<Reservation[]>>('/reservations', { params })
    return data.data
  },

  async get(id: number): Promise<Reservation> {
    const { data } = await apiClient.get<ApiEnvelope<Reservation>>(`/reservations/${id}`)
    return data.data
  },

  async create(input: ReservationCreateInput): Promise<Reservation> {
    const { data } = await apiClient.post<ApiEnvelope<Reservation>>('/reservations', input)
    return data.data
  },

  async update(id: number, input: ReservationUpdateInput): Promise<Reservation> {
    const { data } = await apiClient.patch<ApiEnvelope<Reservation>>(`/reservations/${id}`, input)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await apiClient.delete(`/reservations/${id}`)
  },
}
