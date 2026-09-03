export type PlanCode = 'lite' | 'standard' | 'pro'

/** 契約プランで利用可否が変わる機能。バックエンドの App\Enums\Feature と1対1で対応する */
export type FeatureKey =
  | 'customer'
  | 'medical_record'
  | 'photo'
  | 'reservation'
  | 'google_calendar'
  | 'line'
  | 'ai_summary'
  | 'analytics'

/** Stripe の subscription status をそのまま保持する */
export type SubscriptionStatusCode =
  | 'trialing'
  | 'active'
  | 'past_due'
  | 'canceled'
  | 'unpaid'
  | 'incomplete'
  | 'incomplete_expired'
  | 'paused'

export type FeatureFlags = Record<FeatureKey, boolean>

export interface Subscription {
  plan: PlanCode
  plan_label: string
  monthly_price: number
  status: SubscriptionStatusCode
  status_label: string
  is_active: boolean
  needs_payment_attention: boolean
  cancel_at_period_end: boolean
  current_period_start: string | null
  current_period_end: string | null
  canceled_at: string | null
  ended_at: string | null
  trial_ends_at: string | null
  has_payment_method: boolean
  is_subscribed: boolean
}

export interface PlanCatalogItem {
  code: PlanCode
  label: string
  monthly_price: number
  features: FeatureKey[]
  is_purchasable: boolean
}

export interface SubscriptionOverview {
  subscription: Subscription | null
  plan: PlanCode | null
  features: FeatureFlags
  plans: PlanCatalogItem[]
}

/** 契約プランに含まれない機能へアクセスしたときの 403 ボディ */
export interface FeatureRequiredError {
  message: string
  feature: FeatureKey
  required_plan: PlanCode | null
  current_plan: PlanCode | null
}
