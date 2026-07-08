<script setup lang="ts">
defineProps<{
  title: string
  icon?: string
  subtitle?: string
}>()
</script>

<template>
  <div class="page-header">
    <div class="heading">
      <span v-if="icon" class="heading-icon"><i :class="icon" /></span>
      <div>
        <h1 class="title">{{ title }}</h1>
        <p v-if="subtitle" class="subtitle">{{ subtitle }}</p>
      </div>
    </div>
    <div v-if="$slots.actions" class="actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.heading {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}

.heading-icon {
  display: grid;
  place-items: center;
  width: 46px;
  height: 46px;
  border-radius: 15px;
  background: var(--rb-gradient-brand);
  color: #fff;
  font-size: 1.15rem;
  box-shadow: 0 6px 18px rgba(216, 108, 138, 0.3);
}

.title {
  margin: 0;
  font-size: 1.45rem;
}

.subtitle {
  margin: 0.15rem 0 0;
  color: var(--rb-text-muted);
  font-size: 0.88rem;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
</style>
