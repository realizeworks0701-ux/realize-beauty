<script setup lang="ts">
// /booking 配下のキャッチオール（slug / token の欠落・typo 等）。
// 管理画面への導線は出さない。
import EmptyState from '@/components/common/EmptyState.vue'
import PublicLayout from '@/layouts/PublicLayout.vue'
</script>

<template>
  <PublicLayout>
    <div class="glass-card state-card">
      <EmptyState
        icon="pi pi-search"
        title="ページが見つかりません"
        description="URLをお確かめのうえ、サロンから案内されたリンクを開いてください"
      />
    </div>
  </PublicLayout>
</template>

<style scoped>
.state-card {
  margin-top: 1rem;
  padding: 1.4rem 1.2rem;
}
</style>
