<script setup lang="ts">
import { computed } from 'vue'

// develop のログイン情報は見込み客に渡すため、本番と見分けが付く必要がある。
// 本番ビルドでは VITE_ENV_LABEL が未設定なので何も描画されない。
const label = computed(() => import.meta.env.VITE_ENV_LABEL ?? '')
</script>

<template>
  <div v-if="label" class="env-badge">{{ label }}</div>
</template>

<style scoped>
.env-badge {
  position: fixed;
  top: 0;
  left: 50%;
  z-index: 2000;
  transform: translateX(-50%);
  padding: 0.125rem 0.75rem;
  border-radius: 0 0 0.375rem 0.375rem;
  background: #7c3aed;
  color: #fff;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  pointer-events: none;
}
</style>
