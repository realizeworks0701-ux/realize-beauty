<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Avatar from 'primevue/avatar'
import Skeleton from 'primevue/skeleton'
import GlassCard from '@/components/common/GlassCard.vue'
import KpiCard from '@/components/common/KpiCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { useToast } from 'primevue/usetoast'
import { dashboardService } from '@/services/dashboardService'
import { formatDate } from '@/utils/format'
import { extractErrorMessage } from '@/utils/apiError'
import type { DashboardSummary } from '@/types'

const toast = useToast()
const summary = ref<DashboardSummary | null>(null)
const loading = ref(true)

onMounted(async () => {
  try {
    summary.value = await dashboardService.getSummary()
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'ダッシュボードの取得に失敗しました'),
      life: 3000,
    })
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="rb-page">
    <PageHeader
      title="ダッシュボード"
      icon="pi pi-home"
      subtitle="サロン全体の状況をひと目で確認できます"
    />

    <div class="kpi-grid">
      <template v-if="loading">
        <Skeleton v-for="n in 4" :key="n" height="98px" border-radius="20px" />
      </template>
      <template v-else-if="summary">
        <KpiCard
          label="今日の来店"
          :value="summary.today_customers"
          suffix="人"
          icon="pi pi-calendar"
          variant="rose"
        />
        <KpiCard
          label="新規顧客"
          :value="summary.new_customers"
          suffix="人"
          icon="pi pi-user-plus"
          variant="peach"
        />
        <KpiCard
          label="総顧客数"
          :value="summary.total_customers"
          suffix="人"
          icon="pi pi-users"
          variant="mauve"
        />
        <KpiCard
          label="今月のカルテ"
          :value="summary.records_this_month"
          suffix="件"
          icon="pi pi-file-edit"
          variant="cream"
        />
      </template>
    </div>

    <div class="panel-grid">
      <GlassCard title="最近のカルテ" icon="pi pi-file-edit">
        <template v-if="loading">
          <div class="skeleton-list">
            <Skeleton v-for="n in 4" :key="n" height="52px" border-radius="14px" />
          </div>
        </template>
        <template v-else-if="summary && summary.recent_records.length > 0">
          <ul class="item-list">
            <li v-for="record in summary.recent_records" :key="record.id">
              <RouterLink :to="`/records/${record.id}`" class="item-row">
                <Avatar
                  :label="record.customer.name.charAt(0)"
                  shape="circle"
                  class="item-avatar"
                />
                <div class="item-body">
                  <span class="item-title">{{ record.customer.name }}</span>
                  <span class="item-sub">
                    <i class="pi pi-calendar" /> {{ formatDate(record.visited_at) }}
                    <span class="item-dot">·</span>
                    担当 {{ record.user.name }}
                  </span>
                </div>
                <StatusTag :status="record.status" />
              </RouterLink>
            </li>
          </ul>
        </template>
        <EmptyState
          v-else
          icon="pi pi-file-edit"
          title="カルテはまだありません"
          description="顧客ページからカルテを作成できます"
        />
      </GlassCard>

      <GlassCard title="最近の顧客" icon="pi pi-users">
        <template v-if="loading">
          <div class="skeleton-list">
            <Skeleton v-for="n in 4" :key="n" height="52px" border-radius="14px" />
          </div>
        </template>
        <template v-else-if="summary && summary.recent_customers.length > 0">
          <ul class="item-list">
            <li v-for="customer in summary.recent_customers" :key="customer.id">
              <RouterLink :to="`/customers/${customer.id}`" class="item-row">
                <Avatar
                  :label="customer.name.charAt(0)"
                  shape="circle"
                  class="item-avatar beige"
                />
                <div class="item-body">
                  <span class="item-title">{{ customer.name }}</span>
                  <span class="item-sub">
                    <i class="pi pi-phone" /> {{ customer.phone ?? '—' }}
                    <span class="item-dot">·</span>
                    最終来店 {{ formatDate(customer.last_visit_at) }}
                  </span>
                </div>
                <i class="pi pi-angle-right item-arrow" />
              </RouterLink>
            </li>
          </ul>
        </template>
        <EmptyState
          v-else
          icon="pi pi-users"
          title="顧客はまだいません"
          description="顧客一覧から新規登録できます"
        />
      </GlassCard>
    </div>
  </div>
</template>

<style scoped>
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1rem;
}

.panel-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.25rem;
}

@media (min-width: 1024px) {
  .panel-grid {
    grid-template-columns: 1fr 1fr;
  }
}

.skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.item-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.item-row {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.6rem 0.7rem;
  border-radius: 14px;
  text-decoration: none;
  color: var(--rb-text);
  transition: background-color 0.15s ease;
}

.item-row:hover {
  background: var(--rb-pink-faint);
}

.item-avatar {
  background: var(--rb-gradient-rose);
  color: #fff;
  font-weight: 700;
  flex-shrink: 0;
}

.item-avatar.beige {
  background: var(--rb-gradient-peach);
}

.item-body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
  flex: 1;
}

.item-title {
  font-weight: 700;
  font-size: 0.92rem;
}

.item-sub {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.78rem;
  color: var(--rb-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.item-sub i {
  font-size: 0.72rem;
}

.item-dot {
  margin: 0 0.1rem;
}

.item-arrow {
  color: var(--rb-pink-soft);
}
</style>
