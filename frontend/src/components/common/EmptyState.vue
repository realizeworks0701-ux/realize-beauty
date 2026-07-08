<script setup lang="ts">
withDefaults(
  defineProps<{
    icon?: string
    title: string
    description?: string
  }>(),
  {
    icon: 'pi pi-inbox',
    description: '',
  },
)
</script>

<template>
  <div class="empty-state">
    <div class="empty-icon"><i :class="icon" /></div>
    <p class="empty-title">{{ title }}</p>
    <p v-if="description" class="empty-description">{{ description }}</p>
    <div v-if="$slots.action" class="empty-action">
      <slot name="action" />
    </div>
  </div>
</template>

<style scoped>
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding: 2.2rem 1rem;
  text-align: center;
}

.empty-icon {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--rb-pink-tint);
  color: var(--rb-pink);
  font-size: 1.5rem;
  margin-bottom: 0.4rem;
}

.empty-title {
  margin: 0;
  font-weight: 700;
  color: var(--rb-text);
}

.empty-description {
  margin: 0;
  font-size: 0.88rem;
  color: var(--rb-text-muted);
}

.empty-action {
  margin-top: 0.8rem;
}
</style>
