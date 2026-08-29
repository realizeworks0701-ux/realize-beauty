<script setup lang="ts">
import type { Reservation } from '@/types'
import { formatTime, reservationStatusLabel } from '@/utils/format'

defineProps<{ reservations: Reservation[] }>()
</script>

<template>
  <ul class="reservation-list">
    <li v-for="reservation in reservations" :key="reservation.id">
      <RouterLink to="/reservations" class="reservation-row">
        <span class="time">{{ formatTime(reservation.start_at) }}</span>
        <div class="body">
          <span class="name">{{ reservation.customer.name }} 様</span>
          <span class="menu">{{ reservation.menu.name }}</span>
        </div>
        <span class="status" :class="`is-${reservation.status}`">
          {{ reservationStatusLabel(reservation.status) }}
        </span>
      </RouterLink>
    </li>
  </ul>
</template>

<style scoped>
.reservation-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.reservation-row {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.6rem 0.7rem;
  border-radius: 14px;
  text-decoration: none;
  color: var(--rb-text);
  transition: background-color 0.15s ease;
}

.reservation-row:hover {
  background: var(--rb-pink-faint);
}

.time {
  flex-shrink: 0;
  min-width: 52px;
  padding: 0.3rem 0.45rem;
  border-radius: 10px;
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
  font-family: var(--rb-font-display);
  font-weight: 700;
  font-size: 0.85rem;
  text-align: center;
}

.body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
  flex: 1;
}

.name {
  font-weight: 700;
  font-size: 0.92rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.menu {
  font-size: 0.78rem;
  color: var(--rb-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.status {
  flex-shrink: 0;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 700;
}

.status.is-reserved {
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
}

.status.is-visited {
  background: var(--rb-beige-soft);
  color: #7a6a4f;
}
</style>
