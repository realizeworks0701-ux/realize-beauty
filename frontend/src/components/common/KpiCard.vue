<script setup lang="ts">
import { computed } from 'vue'
import { formatNumber } from '@/utils/format'

const props = withDefaults(
  defineProps<{
    label: string
    value: number
    icon: string
    variant?: 'rose' | 'peach' | 'mauve' | 'cream'
    prefix?: string
    suffix?: string
    delta?: number | null
    deltaSuffix?: string
  }>(),
  {
    variant: 'rose',
    prefix: '',
    suffix: '',
    delta: null,
    deltaSuffix: '%',
  },
)

const displayValue = computed(() => formatNumber(props.value))
</script>

<template>
  <div class="kpi-card">
    <div class="kpi-icon" :class="`rb-gradient-${variant}`"><i :class="icon" /></div>
    <div class="kpi-body">
      <span class="kpi-label">{{ label }}</span>
      <span class="kpi-value">
        <span v-if="prefix" class="kpi-prefix">{{ prefix }}</span
        >{{ displayValue }}<span v-if="suffix" class="kpi-suffix">{{ suffix }}</span>
      </span>
      <span v-if="delta !== null" class="kpi-delta" :class="delta >= 0 ? 'is-up' : 'is-down'">
        <i :class="delta >= 0 ? 'pi pi-arrow-up-right' : 'pi pi-arrow-down-right'" />
        {{ delta >= 0 ? '+' : '' }}{{ delta }}{{ deltaSuffix }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.kpi-card {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.2rem 1.35rem;
  border-radius: var(--rb-radius-lg);
  background: var(--rb-surface);
  border: 1px solid var(--rb-border);
  box-shadow: var(--rb-shadow-soft);
  color: var(--rb-text);
  transition:
    transform 0.18s ease,
    box-shadow 0.18s ease;
}

.kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--rb-shadow-hover);
}

.kpi-icon {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  flex-shrink: 0;
  border-radius: var(--rb-radius-md);
  color: #fff;
  font-size: 1.15rem;
}

.kpi-body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.kpi-label {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--rb-text-muted);
}

.kpi-value {
  font-size: 1.75rem;
  font-weight: 700;
  font-family: var(--rb-font-display);
  line-height: 1.2;
  color: var(--rb-text);
}

.kpi-suffix {
  font-size: 0.9rem;
  margin-left: 0.15rem;
  font-weight: 500;
  color: var(--rb-text-muted);
}

.kpi-prefix {
  font-size: 0.95rem;
  margin-right: 0.1rem;
  font-weight: 700;
}

.kpi-delta {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  align-self: flex-start;
  margin-top: 0.15rem;
  font-size: 0.78rem;
  font-weight: 700;
}

.kpi-delta i {
  font-size: 0.62rem;
}

.kpi-delta.is-up {
  color: var(--rb-success);
}

.kpi-delta.is-down {
  color: var(--rb-danger);
}
</style>
