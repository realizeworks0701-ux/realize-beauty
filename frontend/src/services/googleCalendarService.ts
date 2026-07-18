import { apiClient } from './apiClient'
import type {
  ApiEnvelope,
  BusyBlock,
  GoogleCalendarAuthUrl,
  GoogleCalendarConnection,
  GoogleCalendarConnectionUpdateInput,
  GoogleCalendarListEntry,
  GoogleCalendarMode,
  GoogleCalendarSettings,
} from '@/types'

export const googleCalendarService = {
  async get(): Promise<GoogleCalendarSettings> {
    const { data } = await apiClient.get<ApiEnvelope<GoogleCalendarSettings>>('/google-calendar')
    return data.data
  },

  async updateMode(mode: GoogleCalendarMode): Promise<GoogleCalendarSettings> {
    const { data } = await apiClient.put<ApiEnvelope<GoogleCalendarSettings>>(
      '/google-calendar/mode',
      { mode },
    )
    return data.data
  },

  /** OAuth 認可URLを発行する（SPA はこのURLへ同一タブで遷移する） */
  async createAuthUrl(): Promise<string> {
    const { data } = await apiClient.post<ApiEnvelope<GoogleCalendarAuthUrl>>(
      '/google-calendar/auth-url',
    )
    return data.data.auth_url
  },

  async listCalendars(connectionId: number): Promise<GoogleCalendarListEntry[]> {
    const { data } = await apiClient.get<ApiEnvelope<GoogleCalendarListEntry[]>>(
      `/google-calendar/connections/${connectionId}/calendars`,
    )
    return data.data
  },

  async updateConnection(
    connectionId: number,
    input: GoogleCalendarConnectionUpdateInput,
  ): Promise<GoogleCalendarConnection> {
    const { data } = await apiClient.put<ApiEnvelope<GoogleCalendarConnection>>(
      `/google-calendar/connections/${connectionId}`,
      input,
    )
    return data.data
  },

  async deleteConnection(connectionId: number): Promise<void> {
    await apiClient.delete(`/google-calendar/connections/${connectionId}`)
  },

  /** 予約カレンダーの「外部予定」表示用。from/to は GET /reservations と同一指定 */
  async listBusyBlocks(from: string, to: string): Promise<BusyBlock[]> {
    const { data } = await apiClient.get<ApiEnvelope<BusyBlock[]>>('/google-calendar/busy-blocks', {
      params: { from, to },
    })
    return data.data
  },
}
