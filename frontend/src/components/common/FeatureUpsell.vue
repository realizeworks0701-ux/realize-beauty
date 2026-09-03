<script setup lang="ts">
import { computed } from 'vue'
import Button from 'primevue/button'
import { useFeatures } from '@/composables/useFeatures'
import type { FeatureKey } from '@/types'

const props = withDefaults(
  defineProps<{
    feature: FeatureKey
    /** カード内に差し込む小さめの表示にする */
    compact?: boolean
  }>(),
  { compact: false },
)

const { featureLabel, requiredPlanFor, planLabelOf, featuresAddedBy, hasNoActivePlan } =
  useFeatures()

const requiredPlan = computed(() => requiredPlanFor(props.feature))

/**
 * 契約が無い・終わっている場合に「上位プランが必要」と案内すると誤解を招く。
 * 実際に必要なのは再契約なので、文言を分ける。
 */
const title = computed(() => {
  if (hasNoActivePlan.value) {
    return `ご契約が終了しているため、${featureLabel(props.feature)}をご利用いただけません`
  }

  return requiredPlan.value
    ? `この機能は${planLabelOf(requiredPlan.value)}プラン以上でご利用いただけます`
    : `${featureLabel(props.feature)}は現在のプランではご利用いただけません`
})

const actionLabel = computed(() => {
  if (hasNoActivePlan.value) {
    return 'プランを選び直す'
  }

  return requiredPlan.value ? `${planLabelOf(requiredPlan.value)}プランを見る` : 'プランを見る'
})

/** そのプランで一緒に使えるようになる機能を並べ、値ごとの納得感を出す */
const includedLabels = computed(() =>
  !hasNoActivePlan.value && requiredPlan.value
    ? featuresAddedBy(requiredPlan.value).map(featureLabel)
    : [],
)
</script>

<template>
  <div class="feature-upsell" :class="{ compact }">
    <span class="upsell-icon"><i class="pi pi-lock" /></span>
    <p class="upsell-title">{{ title }}</p>
    <p v-if="includedLabels.length > 0" class="upsell-description">
      {{ includedLabels.join('・') }}をご利用いただけます。
    </p>
    <RouterLink to="/settings/plan">
      <Button
        :label="actionLabel"
        icon="pi pi-arrow-up-right"
        icon-pos="right"
        :size="compact ? 'small' : undefined"
      />
    </RouterLink>
  </div>
</template>

<style scoped>
.feature-upsell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 2rem 1.25rem;
  text-align: center;
}

.feature-upsell.compact {
  padding: 1.5rem 1rem;
  gap: 0.55rem;
}

.upsell-icon {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: var(--rb-primary-tint);
  color: var(--rb-primary);
  font-size: 1.35rem;
}

.compact .upsell-icon {
  width: 42px;
  height: 42px;
  font-size: 1.1rem;
}

.upsell-title {
  margin: 0;
  font-weight: 700;
  color: var(--rb-text);
}

.compact .upsell-title {
  font-size: 0.92rem;
}

.upsell-description {
  margin: 0;
  max-width: 34rem;
  font-size: 0.88rem;
  line-height: 1.6;
  color: var(--rb-text-muted);
}
</style>
