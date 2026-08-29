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
  <div class="kpi-card" :class="`rb-gradient-${variant}`">
    <div class="kpi-deco" />
    <div class="kpi-icon"><i :class="icon" /></div>
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
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.3rem 1.4rem;
  border-radius: var(--rb-radius-lg);
  color: #fff;
  box-shadow: var(--rb-shadow-soft);
  transition:
    transform 0.18s ease,
    box-shadow 0.18s ease;
}

.kpi-card:hover {
  transform: translateY(-3px);
  box-shadow: var(--rb-shadow-hover);
}

.kpi-deco {
  position: absolute;
  top: -46px;
  right: -34px;
  width: 130px;
  height: 130px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.22);
  filter: blur(2px);
}

.kpi-icon {
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  flex-shrink: 0;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.28);
  backdrop-filter: blur(6px);
  font-size: 1.25rem;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
}

.kpi-body {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}

.kpi-label {
  font-size: 0.82rem;
  font-weight: 500;
  opacity: 0.92;
}

.kpi-value {
  font-size: 1.7rem;
  font-weight: 700;
  font-family: var(--rb-font-display);
  line-height: 1.15;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.kpi-suffix {
  font-size: 0.95rem;
  margin-left: 0.15rem;
  font-weight: 500;
}

.kpi-prefix {
  font-size: 0.95rem;
  margin-right: 0.1rem;
  font-weight: 500;
}

.kpi-delta {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  align-self: flex-start;
  margin-top: 0.2rem;
  padding: 0.12rem 0.5rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 700;
  background: rgba(255, 255, 255, 0.28);
}

.kpi-delta i {
  font-size: 0.62rem;
}

.kpi-delta.is-up {
  color: #eafff2;
}

.kpi-delta.is-down {
  color: #ffe3e3;
}
</style>
