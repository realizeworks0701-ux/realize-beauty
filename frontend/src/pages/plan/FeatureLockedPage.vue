<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import FeatureUpsell from '@/components/common/FeatureUpsell.vue'
import { FEATURE_LABELS, useFeatures } from '@/composables/useFeatures'
import type { FeatureKey } from '@/types'

const route = useRoute()
const { featureLabel } = useFeatures()

/**
 * 不正な feature でURLを直接叩かれても壊れないよう、既知のキーだけを受け付ける。
 * `in` はプロトタイプのキー（constructor / toString など）も通してしまうため使わない。
 */
const feature = computed<FeatureKey | null>(() => {
  const value = String(route.params.feature ?? '')
  return Object.hasOwn(FEATURE_LABELS, value) ? (value as FeatureKey) : null
})
</script>

<template>
  <div class="rb-page">
    <PageHeader
      :title="feature ? featureLabel(feature) : 'ご利用いただけない機能'"
      icon="pi pi-lock"
      subtitle="現在のご契約プランではこの機能をご利用いただけません"
    />

    <div class="locked-body">
      <GlassCard>
        <FeatureUpsell v-if="feature" :feature="feature" />
        <div v-else class="unknown">
          <p>指定された機能が見つかりませんでした。</p>
          <RouterLink to="/dashboard" class="back-link">ダッシュボードへ戻る</RouterLink>
        </div>
      </GlassCard>
    </div>
  </div>
</template>

<style scoped>
.locked-body {
  max-width: 720px;
}

.unknown {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 2rem 1rem;
  text-align: center;
  color: var(--rb-text-muted);
}

.back-link {
  color: var(--rb-primary);
  font-weight: 600;
  text-decoration: none;
}

.back-link:hover {
  text-decoration: underline;
}
</style>
