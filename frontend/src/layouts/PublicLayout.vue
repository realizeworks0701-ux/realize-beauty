<script setup lang="ts">
// 公開ページ用レイアウト（サイドバー・認証UIなし。モバイルファースト・1カラム中央寄せ）
defineProps<{
  salonName?: string | null
}>()
</script>

<template>
  <div class="public-shell">
    <header v-if="salonName" class="public-header glass-card">
      <span class="header-icon"><i class="pi pi-sparkles" /></span>
      <span class="salon-name">{{ salonName }}</span>
    </header>

    <main class="public-main">
      <slot />
    </main>
  </div>
</template>

<style scoped>
.public-shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.9rem;
  padding: 0.9rem 1rem 2.5rem;
}

.public-header {
  width: 100%;
  max-width: 480px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  padding: 0.75rem 1rem;
}

.header-icon {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 10px;
  background: var(--rb-gradient-brand);
  color: #fff;
  font-size: 0.85rem;
}

.salon-name {
  font-family: var(--rb-font-display);
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--rb-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.public-main {
  width: 100%;
  max-width: 480px;
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}
</style>
