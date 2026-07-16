<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import { useAuthStore } from '@/stores/auth'
import type { UserRole } from '@/types'

const auth = useAuthStore()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()

const loadingUser = ref(true)
const loggingOut = ref(false)

const ROLE_META: Record<UserRole, { label: string; icon: string; className: string }> = {
  owner: { label: 'オーナー', icon: 'pi pi-crown', className: 'owner' },
  manager: { label: 'マネージャー', icon: 'pi pi-briefcase', className: 'manager' },
  staff: { label: 'スタッフ', icon: 'pi pi-user', className: 'staff' },
}

const roleMeta = computed(() => (auth.user ? ROLE_META[auth.user.role] : null))
const initial = computed(() => auth.user?.name.charAt(0) ?? '')

onMounted(async () => {
  try {
    await auth.refreshUser()
  } catch {
    // 取得失敗時はローカルに保持しているユーザー情報をそのまま表示する
  } finally {
    loadingUser.value = false
  }
})

function confirmLogout(): void {
  confirm.require({
    message: 'ログアウトしてもよろしいですか？',
    header: 'ログアウト',
    icon: 'pi pi-sign-out',
    acceptLabel: 'ログアウト',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      loggingOut.value = true
      try {
        await auth.logout()
        toast.add({ severity: 'success', summary: 'ログアウトしました', life: 3000 })
        await router.replace({ name: 'login' })
      } finally {
        loggingOut.value = false
      }
    },
  })
}
</script>

<template>
  <div class="rb-page">
    <PageHeader title="設定" icon="pi pi-cog" subtitle="アカウント情報の確認と管理を行えます" />

    <div class="settings-body">
      <GlassCard title="アカウント" icon="pi pi-user">
        <template v-if="loadingUser && !auth.user">
          <div class="account-skeleton">
            <Skeleton shape="circle" size="76px" />
            <div class="account-skeleton-lines">
              <Skeleton width="10rem" height="1.3rem" border-radius="8px" />
              <Skeleton width="14rem" height="1rem" border-radius="8px" />
              <Skeleton width="6rem" height="1.4rem" border-radius="999px" />
            </div>
          </div>
        </template>
        <template v-else-if="auth.user">
          <div class="account">
            <Avatar :label="initial" shape="circle" class="account-avatar" />
            <div class="account-info">
              <span class="account-name">{{ auth.user.name }}</span>
              <span class="account-email">
                <i class="pi pi-envelope" />
                {{ auth.user.email }}
              </span>
              <span v-if="roleMeta" class="role-badge" :class="roleMeta.className">
                <i :class="roleMeta.icon" />
                {{ roleMeta.label }}
              </span>
            </div>
          </div>
        </template>
        <EmptyState
          v-else
          icon="pi pi-user"
          title="アカウント情報を取得できませんでした"
          description="お手数ですが、再度ログインしてお試しください"
        />
      </GlassCard>

      <GlassCard title="パスワード変更" icon="pi pi-lock">
        <div class="info-box">
          <i class="pi pi-info-circle info-icon" />
          <p class="info-text">パスワード変更は今後のアップデートで対応予定です</p>
        </div>
      </GlassCard>

      <GlassCard title="サロン設定" icon="pi pi-shop">
        <div class="salon-links">
          <RouterLink to="/settings/menus" class="salon-link">
            <span class="salon-link-icon"><i class="pi pi-list" /></span>
            <span class="salon-link-body">
              <span class="salon-link-title">メニュー管理</span>
              <span class="salon-link-description">施術メニューの登録・並び順・有効/無効</span>
            </span>
            <i class="pi pi-angle-right salon-link-arrow" />
          </RouterLink>
          <RouterLink to="/settings/business-hours" class="salon-link">
            <span class="salon-link-icon"><i class="pi pi-clock" /></span>
            <span class="salon-link-body">
              <span class="salon-link-title">営業時間設定</span>
              <span class="salon-link-description">曜日別の営業時間・定休日の設定</span>
            </span>
            <i class="pi pi-angle-right salon-link-arrow" />
          </RouterLink>
          <RouterLink to="/settings/line" class="salon-link">
            <span class="salon-link-icon"><i class="pi pi-comments" /></span>
            <span class="salon-link-body">
              <span class="salon-link-title">LINE連携</span>
              <span class="salon-link-description">LINE公式アカウントの接続・Web予約ページURL</span>
            </span>
            <i class="pi pi-angle-right salon-link-arrow" />
          </RouterLink>
        </div>
      </GlassCard>

      <GlassCard title="ログアウト" icon="pi pi-sign-out">
        <div class="logout-row">
          <p class="logout-note">
            現在のアカウントからログアウトします。再度ご利用いただくには、ログインが必要です。
          </p>
          <Button
            label="ログアウト"
            icon="pi pi-sign-out"
            severity="danger"
            outlined
            :loading="loggingOut"
            class="logout-button"
            @click="confirmLogout"
          />
        </div>
      </GlassCard>
    </div>
  </div>
</template>

<style scoped>
.settings-body {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  width: 100%;
  max-width: 720px;
}

/* ---------- アカウント ---------- */

.account {
  display: flex;
  align-items: center;
  gap: 1.2rem;
  flex-wrap: wrap;
}

.account-avatar {
  width: 76px;
  height: 76px;
  flex-shrink: 0;
  background: var(--rb-gradient-brand);
  color: #fff;
  font-family: var(--rb-font-display);
  font-size: 1.9rem;
  font-weight: 700;
  box-shadow: 0 6px 18px rgba(216, 108, 138, 0.3);
}

.account-info {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.35rem;
  min-width: 0;
}

.account-name {
  font-family: var(--rb-font-display);
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--rb-text);
}

.account-email {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.88rem;
  color: var(--rb-text-muted);
  word-break: break-all;
}

.account-email i {
  font-size: 0.8rem;
  color: var(--rb-pink);
}

.role-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.15rem;
  padding: 0.3rem 0.85rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 700;
}

.role-badge i {
  font-size: 0.78rem;
}

.role-badge.owner {
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
}

.role-badge.manager {
  background: var(--rb-beige-soft);
  color: var(--rb-text);
}

.role-badge.staff {
  background: var(--rb-pink-faint);
  color: var(--rb-pink-strong);
}

.account-skeleton {
  display: flex;
  align-items: center;
  gap: 1.2rem;
}

.account-skeleton-lines {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

/* ---------- パスワード変更 ---------- */

.info-box {
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
  padding: 0.95rem 1.1rem;
  border-radius: var(--rb-radius-md);
  background: var(--rb-beige-soft);
  border: 1px solid var(--rb-beige);
}

.info-icon {
  margin-top: 0.1rem;
  font-size: 1rem;
  color: var(--rb-beige-deep);
}

.info-text {
  margin: 0;
  font-size: 0.9rem;
  color: var(--rb-text);
}

/* ---------- サロン設定 ---------- */

.salon-links {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.salon-link {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 0.8rem 0.9rem;
  border-radius: var(--rb-radius-md);
  border: 1px solid var(--rb-border);
  background: rgba(255, 255, 255, 0.55);
  text-decoration: none;
  color: var(--rb-text);
  transition:
    background-color 0.15s ease,
    box-shadow 0.15s ease;
}

.salon-link:hover {
  background: var(--rb-pink-faint);
  box-shadow: var(--rb-shadow-soft);
}

.salon-link-icon {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  flex-shrink: 0;
  border-radius: 12px;
  background: var(--rb-pink-tint);
  color: var(--rb-pink-strong);
}

.salon-link-body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
  flex: 1;
}

.salon-link-title {
  font-weight: 700;
  font-size: 0.95rem;
}

.salon-link-description {
  font-size: 0.8rem;
  color: var(--rb-text-muted);
}

.salon-link-arrow {
  color: var(--rb-pink-soft);
}

/* ---------- ログアウト ---------- */

.logout-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.logout-note {
  margin: 0;
  font-size: 0.9rem;
  color: var(--rb-text-muted);
  flex: 1 1 260px;
}

.logout-button {
  flex-shrink: 0;
}

@media (max-width: 520px) {
  .logout-row {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
