<script setup lang="ts">
import { computed } from 'vue'
import type { CustomerSegments } from '@/types'

const props = defineProps<{ segments: CustomerSegments }>()

const items = computed(() => [
  { key: 'new', label: '新規', value: props.segments.new },
  { key: 'repeat', label: 'リピーター', value: props.segments.repeat },
  { key: 'dormant', label: '休眠', value: props.segments.dormant },
  { key: 'other', label: 'その他', value: props.segments.other },
])
</script>

<template>
  <div class="segment-grid">
    <div v-for="item in items" :key="item.key" class="segment" :class="`is-${item.key}`">
      <span class="segment-label">{{ item.label }}</span>
      <span class="segment-value">{{ item.value }}<span class="segment-suffix">名</span></span>
    </div>
  </div>
</template>

<style scoped>
.segment-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.6rem;
}

.segment {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.7rem 0.9rem;
  border-radius: 14px;
  border: 1px solid var(--rb-border);
}

.segment.is-new {
  background: var(--rb-primary-faint);
}

.segment.is-repeat {
  background: var(--rb-primary-tint);
}

.segment.is-dormant {
  background: var(--rb-accent-soft);
}

.segment.is-other {
  background: var(--rb-surface-subtle);
}

.segment-label {
  font-size: 0.75rem;
  color: var(--rb-text-muted);
}

.segment-value {
  font-family: var(--rb-font-display);
  font-weight: 700;
  font-size: 1.25rem;
}

.segment-suffix {
  font-size: 0.75rem;
  font-weight: 500;
  margin-left: 0.1rem;
}
</style>
