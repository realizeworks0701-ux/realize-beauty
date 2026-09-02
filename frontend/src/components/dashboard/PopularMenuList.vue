<script setup lang="ts">
import { computed } from 'vue'
import type { PopularMenu } from '@/types'
import { formatNumber } from '@/utils/format'

const props = defineProps<{ menus: PopularMenu[] }>()

const VARIANTS = ['rose', 'peach', 'mauve', 'cream'] as const

const maxCount = computed(() => Math.max(...props.menus.map((menu) => menu.count), 1))

function tileClass(index: number): string {
  return `rb-gradient-${VARIANTS[index % VARIANTS.length] ?? 'rose'}`
}

function barWidth(count: number): string {
  return `${Math.round((count / maxCount.value) * 100)}%`
}
</script>

<template>
  <ul class="menu-list">
    <li v-for="(menu, index) in menus" :key="menu.menu_id" class="menu-row">
      <span class="menu-tile" :class="tileClass(index)"><i class="pi pi-sparkles" /></span>
      <div class="menu-body">
        <div class="menu-head">
          <span class="menu-name">{{ menu.name }}</span>
          <span class="menu-price">
            {{ menu.price != null ? `¥${formatNumber(menu.price)}` : '—' }}
          </span>
        </div>
        <div class="menu-bar-track">
          <div class="menu-bar" :style="{ width: barWidth(menu.count) }" />
        </div>
      </div>
      <span class="menu-count">{{ menu.count }}件</span>
    </li>
  </ul>
</template>

<style scoped>
.menu-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
}

.menu-row {
  display: flex;
  align-items: center;
  gap: 0.8rem;
}

.menu-tile {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  flex-shrink: 0;
  border-radius: 12px;
  color: #fff;
  font-size: 1rem;
}

.menu-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.menu-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}

.menu-name {
  font-weight: 700;
  font-size: 0.88rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.menu-price {
  flex-shrink: 0;
  font-size: 0.78rem;
  color: var(--rb-text-muted);
}

.menu-bar-track {
  height: 6px;
  border-radius: 999px;
  background: var(--rb-primary-faint);
  overflow: hidden;
}

.menu-bar {
  height: 100%;
  border-radius: 999px;
  background: var(--rb-gradient-brand);
}

.menu-count {
  flex-shrink: 0;
  font-size: 0.8rem;
  font-weight: 700;
  color: var(--rb-primary-deep);
}
</style>
