<script setup lang="ts">
import { computed, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import AppLayout from '@/layouts/AppLayout.vue'

const route = useRoute()
const isPublic = computed(() => route.meta.public === true)

// 公開予約ページは旧テーマ（ピンク/グラス）を維持する。Toast などは body 直下へ
// Teleport されるため、ラッパー div ではなく documentElement にクラスを付ける。
watchEffect(() => {
  document.documentElement.classList.toggle('rb-legacy-theme', route.meta.legacyTheme === true)
})
</script>

<template>
  <Toast position="top-right" />
  <ConfirmDialog />
  <RouterView v-if="isPublic" />
  <AppLayout v-else />
</template>
