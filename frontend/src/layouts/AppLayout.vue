<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useConfirm } from 'primevue/useconfirm'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import { useAuthStore } from '@/stores/auth'
import { useFeatures } from '@/composables/useFeatures'
import type { FeatureKey } from '@/types'

const router = useRouter()
const confirm = useConfirm()
const auth = useAuthStore()
const { can } = useFeatures()

const route = useRoute()
const menuOpen = ref(false)

watch(
  () => route.path,
  () => {
    menuOpen.value = false
  },
)

/** feature を持つ項目は、契約プランに含まれるときだけ表示する（ADR-029） */
const NAV_ITEMS: { label: string; icon: string; to: string; feature?: FeatureKey }[] = [
  { label: 'ダッシュボード', icon: 'pi pi-home', to: '/dashboard' },
  { label: '顧客', icon: 'pi pi-users', to: '/customers', feature: 'customer' },
  { label: 'カルテ', icon: 'pi pi-file-edit', to: '/records', feature: 'medical_record' },
  { label: '予約', icon: 'pi pi-calendar', to: '/reservations', feature: 'reservation' },
  { label: '設定', icon: 'pi pi-cog', to: '/settings' },
]

// 配列はPC用サイドバーとモバイル用Drawerの2箇所で描画するため、絞り込みはテンプレートではなくここで行う
const navItems = computed(() => NAV_ITEMS.filter((item) => !item.feature || can(item.feature)))

function confirmLogout(): void {
  confirm.require({
    message: 'ログアウトしますか？',
    header: 'ログアウト',
    icon: 'pi pi-sign-out',
    acceptLabel: 'ログアウト',
    rejectLabel: 'キャンセル',
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      await auth.logout()
      router.push({ name: 'login' })
    },
  })
}
</script>

<template>
  <div class="app-shell">
    <aside class="app-sidebar">
      <RouterLink to="/dashboard" class="brand">
        <span class="brand-icon"><i class="pi pi-sparkles" /></span>
        <span class="brand-name">Realize Beauty</span>
      </RouterLink>
      <nav class="nav-list">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="nav-item"
          :class="{ active: $route.path.startsWith(item.to) }"
        >
          <i :class="item.icon" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>
    </aside>

    <Drawer v-model:visible="menuOpen" header="メニュー" class="mobile-drawer">
      <nav class="nav-list">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="nav-item"
          :class="{ active: $route.path.startsWith(item.to) }"
        >
          <i :class="item.icon" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>
    </Drawer>

    <div class="app-column">
      <header class="app-header">
        <div class="header-left">
          <Button
            icon="pi pi-bars"
            severity="secondary"
            text
            rounded
            class="menu-button"
            aria-label="メニューを開く"
            @click="menuOpen = true"
          />
          <RouterLink to="/dashboard" class="brand brand-compact">
            <span class="brand-icon"><i class="pi pi-sparkles" /></span>
            <span class="brand-name">Realize Beauty</span>
          </RouterLink>
        </div>
        <div class="header-right">
          <div class="user-chip">
            <Avatar :label="auth.user?.name?.charAt(0) ?? '?'" shape="circle" class="user-avatar" />
            <span class="user-name">{{ auth.user?.name ?? '' }}</span>
          </div>
          <Button
            icon="pi pi-sign-out"
            severity="secondary"
            text
            rounded
            aria-label="ログアウト"
            @click="confirmLogout"
          />
        </div>
      </header>

      <main class="app-main">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  min-height: 100dvh;
  display: grid;
  grid-template-columns: 220px 1fr;
}

.app-sidebar {
  position: sticky;
  top: 0;
  align-self: start;
  height: 100vh;
  height: 100dvh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.25rem 0.9rem;
  background: var(--rb-gradient-brand);
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0 0.35rem;
  text-decoration: none;
}

.brand-icon {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.2);
  color: #fff;
}

.brand-name {
  font-family: var(--rb-font-display);
  font-weight: 700;
  font-size: 1.1rem;
  color: #fff;
  white-space: nowrap;
}

.nav-list {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.7rem 0.9rem;
  border-radius: var(--rb-radius-md);
  color: rgba(255, 255, 255, 0.95);
  text-decoration: none;
  font-weight: 500;
  font-size: 0.95rem;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}

.nav-item i {
  font-size: 1.05rem;
}

.nav-item:hover {
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
}

.nav-item.active {
  background: rgba(255, 255, 255, 0.18);
  color: #fff;
  font-weight: 700;
}

.app-column {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.app-header {
  position: sticky;
  top: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.7rem 1.5rem;
  background: var(--rb-surface);
  border-bottom: 1px solid var(--rb-border);
}

.header-left {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.brand-compact {
  display: none;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.user-chip {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0.85rem 0.25rem 0.3rem;
  border-radius: 999px;
  background: var(--rb-primary-faint);
  border: 1px solid var(--rb-border);
}

.user-avatar {
  background: var(--rb-gradient-brand);
  color: #fff;
  font-weight: 700;
}

.user-name {
  font-size: 0.9rem;
  font-weight: 500;
}

.app-main {
  flex: 1;
  min-width: 0;
  width: 100%;
  max-width: 1240px;
  padding: 1.5rem;
}

.menu-button {
  display: none;
}

@media (max-width: 1023px) {
  .app-shell {
    grid-template-columns: 1fr;
  }

  .app-sidebar {
    display: none;
  }

  .menu-button,
  .brand-compact {
    display: inline-flex;
  }

  .app-header {
    padding: 0.7rem 1rem;
  }

  .app-main {
    padding: 1rem;
  }
}

@media (max-width: 599px) {
  .user-name {
    display: none;
  }

  .user-chip {
    padding: 0.15rem;
    background: transparent;
    border: none;
  }
}
</style>
