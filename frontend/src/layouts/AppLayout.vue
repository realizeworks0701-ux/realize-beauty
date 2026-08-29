<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useConfirm } from 'primevue/useconfirm'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const confirm = useConfirm()
const auth = useAuthStore()

const route = useRoute()
const menuOpen = ref(false)

watch(
  () => route.path,
  () => {
    menuOpen.value = false
  },
)

const navItems = [
  { label: 'ダッシュボード', icon: 'pi pi-home', to: '/dashboard' },
  { label: '顧客', icon: 'pi pi-users', to: '/customers' },
  { label: 'カルテ', icon: 'pi pi-file-edit', to: '/records' },
  { label: '予約', icon: 'pi pi-calendar', to: '/reservations' },
  { label: '設定', icon: 'pi pi-cog', to: '/settings' },
]

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
    <header class="app-header glass-card">
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
        <RouterLink to="/dashboard" class="brand">
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

    <div class="app-body">
      <aside class="app-sidebar glass-card">
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

      <main class="app-main">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1rem 1.25rem 1.5rem;
}

.app-header {
  position: sticky;
  top: 1rem;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.65rem 1.25rem;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  text-decoration: none;
}

.brand-icon {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  border-radius: 12px;
  background: var(--rb-gradient-brand);
  color: #fff;
  box-shadow: 0 4px 14px rgba(216, 108, 138, 0.35);
}

.brand-name {
  font-family: var(--rb-font-display);
  font-weight: 700;
  font-size: 1.15rem;
  background: var(--rb-gradient-brand);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
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
  background: var(--rb-pink-faint);
  border: 1px solid var(--rb-border);
}

.user-avatar {
  background: var(--rb-gradient-rose);
  color: #fff;
  font-weight: 700;
}

.user-name {
  font-size: 0.9rem;
  font-weight: 500;
}

.app-body {
  display: flex;
  gap: 1.25rem;
  flex: 1;
  align-items: flex-start;
}

.app-sidebar {
  position: sticky;
  top: 5.2rem;
  width: 220px;
  flex-shrink: 0;
  padding: 0.9rem;
}

.nav-list {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.7rem 0.9rem;
  border-radius: 14px;
  color: var(--rb-text);
  text-decoration: none;
  font-weight: 500;
  font-size: 0.95rem;
  transition:
    background-color 0.15s ease,
    color 0.15s ease,
    box-shadow 0.15s ease;
}

.nav-item i {
  font-size: 1.05rem;
  color: var(--rb-pink);
  transition: color 0.15s ease;
}

.nav-item:hover {
  background: var(--rb-pink-faint);
}

.nav-item.active {
  background: var(--rb-gradient-brand);
  color: #fff;
  box-shadow: 0 6px 18px rgba(216, 108, 138, 0.35);
}

.nav-item.active i {
  color: #fff;
}

.app-main {
  flex: 1;
  min-width: 0;
  max-width: 1160px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.menu-button {
  display: none;
}

@media (max-width: 1023px) {
  .menu-button {
    display: inline-flex;
  }

  .app-sidebar {
    display: none;
  }

  .app-shell {
    padding: 0.75rem 0.75rem 1.25rem;
  }

  .app-header {
    top: 0.75rem;
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
