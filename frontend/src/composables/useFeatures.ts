import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import type { FeatureKey, PlanCode } from '@/types'

/** 機能の日本語名。バックエンドの Feature::label() と揃える */
const FEATURE_LABELS: Record<FeatureKey, string> = {
  customer: '顧客管理',
  medical_record: 'カルテ管理',
  photo: '写真管理',
  reservation: '予約管理',
  google_calendar: 'Googleカレンダー連携',
  line: 'LINE連携',
  ai_summary: 'AI要約',
  analytics: '高度な分析',
}

const PLAN_LABELS: Record<PlanCode, string> = {
  lite: 'Lite',
  standard: 'Standard',
  pro: 'Pro',
}

/** 安い順。機能を含む最初のプランが「必要な最小プラン」になる */
const PLAN_ORDER: PlanCode[] = ['lite', 'standard', 'pro']

/** config/billing.php の対応表の写し。導線の文言を組み立てるためだけに使う */
const PLAN_FEATURES: Record<PlanCode, FeatureKey[]> = {
  lite: ['customer', 'medical_record', 'photo'],
  standard: ['customer', 'medical_record', 'photo', 'reservation', 'google_calendar', 'line'],
  pro: [
    'customer',
    'medical_record',
    'photo',
    'reservation',
    'google_calendar',
    'line',
    'ai_summary',
    'analytics',
  ],
}

/**
 * 契約プランによる画面の出し分け。
 *
 * ここでの判定は導線と表示のためのものであり、セキュリティ境界ではない。
 * 実際の遮断は必ずバックエンドの 403 が担う。
 */
export function useFeatures() {
  const auth = useAuthStore()

  const plan = computed<PlanCode | null>(() => auth.user?.plan ?? null)

  const planLabel = computed(() => (plan.value ? PLAN_LABELS[plan.value] : null))

  /** 機能フラグが未取得かどうか（課金導入前に保存された古いユーザー情報など） */
  const featuresLoaded = computed(() => auth.user?.features !== undefined)

  function can(feature: FeatureKey): boolean {
    // フラグをまだ持っていないセッションでは出し分けを保留する。
    // ここで閉じると、デプロイ直後の既存ログインが全画面から締め出される。
    // 遮断そのものは常にサーバの 403 が担うため、開いていても安全側に倒れる。
    if (!featuresLoaded.value) {
      return true
    }

    return auth.user?.features?.[feature] === true
  }

  /** その機能を使える最も安いプラン */
  function requiredPlanFor(feature: FeatureKey): PlanCode | null {
    return PLAN_ORDER.find((code) => PLAN_FEATURES[code].includes(feature)) ?? null
  }

  function featureLabel(feature: FeatureKey): string {
    return FEATURE_LABELS[feature]
  }

  function planLabelOf(code: PlanCode): string {
    return PLAN_LABELS[code]
  }

  /** そのプランで新たに使えるようになる機能（アップグレード導線の説明に使う） */
  function featuresAddedBy(code: PlanCode): FeatureKey[] {
    const previousCode = PLAN_ORDER[PLAN_ORDER.indexOf(code) - 1]
    const previous = previousCode ? PLAN_FEATURES[previousCode] : []
    return PLAN_FEATURES[code].filter((feature) => !previous.includes(feature))
  }

  /** 契約が無い・失効している（プランを解決できない） */
  const hasNoActivePlan = computed(() => featuresLoaded.value && plan.value === null)

  return {
    plan,
    planLabel,
    featuresLoaded,
    hasNoActivePlan,
    can,
    requiredPlanFor,
    featureLabel,
    planLabelOf,
    featuresAddedBy,
  }
}

export { FEATURE_LABELS, PLAN_LABELS, PLAN_ORDER, PLAN_FEATURES }
