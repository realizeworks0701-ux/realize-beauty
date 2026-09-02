import axios from 'axios'
import { resolvePublicApiBaseURL } from '@/utils/apiBaseUrl'
import type {
  ApiEnvelope,
  AvailabilitySlot,
  PublicAvailabilityParams,
  PublicBooking,
  PublicReservationRequest,
  PublicReservationResponse,
  PublicSalon,
} from '@/types'

// 公開APIは認証不要のため、Authorization ヘッダを付与する管理用 apiClient とは分離する。
const baseURL = resolvePublicApiBaseURL(
  import.meta.env.VITE_PUBLIC_API_BASE_URL,
  import.meta.env.VITE_API_BASE_URL,
)

export const publicApiClient = axios.create({
  baseURL,
  headers: {
    Accept: 'application/json',
  },
})

// 開発用モック（VITE_USE_MOCK=true のときのみ有効。本番ビルドでは除去される）
if (import.meta.env.VITE_USE_MOCK === 'true') {
  const { installMockAdapter } = await import('./mock/mockAdapter')
  installMockAdapter(publicApiClient)
}

export const publicBookingService = {
  async getSalon(bookingSlug: string): Promise<PublicSalon> {
    const { data } = await publicApiClient.get<ApiEnvelope<PublicSalon>>(`/salons/${bookingSlug}`)
    return data.data
  },

  async listAvailability(
    bookingSlug: string,
    params: PublicAvailabilityParams,
  ): Promise<AvailabilitySlot[]> {
    const { data } = await publicApiClient.get<ApiEnvelope<AvailabilitySlot[]>>(
      `/salons/${bookingSlug}/availability`,
      { params },
    )
    return data.data
  },

  async createReservation(
    bookingSlug: string,
    input: PublicReservationRequest,
  ): Promise<PublicReservationResponse> {
    const { data } = await publicApiClient.post<ApiEnvelope<PublicReservationResponse>>(
      `/salons/${bookingSlug}/reservations`,
      input,
    )
    return data.data
  },

  async getBooking(bookingToken: string): Promise<PublicBooking> {
    const { data } = await publicApiClient.get<ApiEnvelope<PublicBooking>>(
      `/bookings/${bookingToken}`,
    )
    return data.data
  },

  async cancelBooking(bookingToken: string): Promise<PublicBooking> {
    const { data } = await publicApiClient.post<ApiEnvelope<PublicBooking>>(
      `/bookings/${bookingToken}/cancel`,
    )
    return data.data
  },
}
