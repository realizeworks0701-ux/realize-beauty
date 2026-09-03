import type { FeatureFlags, PlanCode, SubscriptionStatusCode } from './subscription'

export type UserRole = 'owner' | 'manager' | 'staff'

export interface User {
  id: number
  name: string
  email: string
  role: UserRole
  /** 契約プラン。契約が無い・失効している場合は null */
  plan: PlanCode | null
  subscription_status: SubscriptionStatusCode | null
  /** 表示制御にのみ使う。実際の遮断はバックエンドの 403 が担う */
  features: FeatureFlags
}

/** GET /users が返す在籍スタッフ（予約の担当者選択用） */
export interface StaffUser {
  id: number
  name: string
  role: UserRole
}
