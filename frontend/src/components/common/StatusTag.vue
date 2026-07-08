<script setup lang="ts">
import { computed } from 'vue'
import type { RecordStatus } from '@/types'
import { recordStatusLabel } from '@/utils/format'

const props = defineProps<{
  status: RecordStatus
}>()

const label = computed(() => recordStatusLabel(props.status))
</script>

<template>
  <span class="status-tag" :class="status">
    <i :class="status === 'completed' ? 'pi pi-check-circle' : 'pi pi-pencil'" />
    {{ label }}
  </span>
</template>

<style scoped>
.status-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.28rem 0.8rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 700;
}

.status-tag i {
  font-size: 0.78rem;
}

.status-tag.completed {
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
}

.status-tag.draft {
  background: var(--rb-beige-soft);
  color: #8a7566;
}
</style>
