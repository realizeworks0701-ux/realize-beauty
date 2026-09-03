import { PLAN_FEATURES } from '@/composables/useFeatures'
import type { FeatureFlags, FeatureKey, PlanCode, User } from '@/types'

const ALL_FEATURES = PLAN_FEATURES.pro

export function buildFeatureFlags(plan: PlanCode | null): FeatureFlags {
  const enabled: FeatureKey[] = plan ? PLAN_FEATURES[plan] : []
  return Object.fromEntries(ALL_FEATURES.map((key) => [key, enabled.includes(key)])) as FeatureFlags
}

/**
 * 認証ストアに差し込むテスト用ユーザー。
 * 契約プランを指定すると features がそれに合わせて組み立てられる。
 */
export function buildTestUser(plan: PlanCode | null = 'pro', overrides: Partial<User> = {}): User {
  return {
    id: 1,
    name: '山田 太郎',
    email: 'owner@example.com',
    role: 'owner',
    plan,
    subscription_status: plan === null ? null : 'active',
    features: buildFeatureFlags(plan),
    ...overrides,
  }
}
