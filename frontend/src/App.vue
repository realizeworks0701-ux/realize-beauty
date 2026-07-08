<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import AppLayout from '@/layouts/AppLayout.vue'

const route = useRoute()
const isPublic = computed(() => route.meta.public === true)
</script>

<template>
  <Toast position="top-right" />
  <ConfirmDialog />
  <RouterView v-if="isPublic" />
  <AppLayout v-else />
</template>
