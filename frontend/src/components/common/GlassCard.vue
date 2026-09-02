<script setup lang="ts">
defineProps<{
  title?: string
  icon?: string
}>()
</script>

<template>
  <section class="glass-card card">
    <header v-if="title || $slots.actions" class="card-header">
      <h2 v-if="title" class="card-title">
        <span v-if="icon" class="card-icon"><i :class="icon" /></span>
        {{ title }}
      </h2>
      <div v-if="$slots.actions" class="card-actions">
        <slot name="actions" />
      </div>
    </header>
    <slot />
  </section>
</template>

<style scoped>
.card {
  padding: 1.4rem 1.5rem;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.1rem;
}

.card-title {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin: 0;
  font-size: 1.05rem;
}

.card-icon {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 10px;
  background: var(--rb-primary-tint);
  color: var(--rb-primary-strong);
  font-size: 0.9rem;
}

.card-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
</style>
