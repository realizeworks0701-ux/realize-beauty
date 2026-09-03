import { apiClient } from './apiClient'
import type { ApiEnvelope, PlanCode, Subscription, SubscriptionOverview } from '@/types'

export const subscriptionService = {
  async get(): Promise<SubscriptionOverview> {
    const { data } = await apiClient.get<ApiEnvelope<SubscriptionOverview>>('/subscription')
    return data.data
  },

  /**
   * Stripe Checkout のURLを取得する。送るのは plan だけで、Price ID はサーバが解決する。
   */
  async checkout(plan: PlanCode): Promise<string> {
    const { data } = await apiClient.post<ApiEnvelope<{ url: string }>>('/subscription/checkout', {
      plan,
    })
    return data.data.url
  },

  /**
   * Checkout から戻った直後に結果を取り込む。
   * Webhook 到着待ちの間も契約が反映され、二重契約を防げる。
   */
  async syncCheckout(sessionId: string): Promise<Subscription | null> {
    const { data } = await apiClient.post<ApiEnvelope<Subscription | null>>(
      '/subscription/sync-checkout',
      { session_id: sessionId },
    )
    return data.data
  },

  /** 支払い方法・請求履歴は Stripe カスタマーポータルに委ねる */
  async portal(): Promise<string> {
    const { data } = await apiClient.post<ApiEnvelope<{ url: string }>>('/subscription/portal')
    return data.data.url
  },

  async changePlan(plan: PlanCode): Promise<Subscription> {
    const { data } = await apiClient.post<ApiEnvelope<Subscription>>('/subscription/change-plan', {
      plan,
    })
    return data.data
  },

  async cancel(): Promise<Subscription> {
    const { data } = await apiClient.post<ApiEnvelope<Subscription>>('/subscription/cancel')
    return data.data
  },

  async resume(): Promise<Subscription> {
    const { data } = await apiClient.post<ApiEnvelope<Subscription>>('/subscription/resume')
    return data.data
  },
}
