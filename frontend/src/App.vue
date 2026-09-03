<script setup lang="ts">
import { computed, onMounted, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import AppLayout from '@/layouts/AppLayout.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
const isPublic = computed(() => route.meta.public === true)

// localStorage のユーザー情報は前回ログイン時の写しで、契約プランや機能フラグが
// 古い（あるいは課金導入前で存在しない）ことがある。起動時に一度だけ取り直す。
// 失敗しても画面は動かし続ける（認証切れは apiClient が 401 で拾う）。
onMounted(() => {
  if (auth.isAuthenticated) {
    void auth.refreshUser().catch(() => undefined)
  }
})

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
