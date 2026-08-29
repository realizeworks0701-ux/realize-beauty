<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Skeleton from 'primevue/skeleton'
import GlassCard from '@/components/common/GlassCard.vue'
import KpiCard from '@/components/common/KpiCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import SalesTrendChart from '@/components/dashboard/SalesTrendChart.vue'
import TodayReservationList from '@/components/dashboard/TodayReservationList.vue'
import PopularMenuList from '@/components/dashboard/PopularMenuList.vue'
import CustomerSegmentList from '@/components/dashboard/CustomerSegmentList.vue'
import { useToast } from 'primevue/usetoast'
import { dashboardService } from '@/services/dashboardService'
import { calcDeltaPercent } from '@/utils/format'
import { extractErrorMessage } from '@/utils/apiError'
import type { DashboardSummary } from '@/types'

const toast = useToast()
const summary = ref<DashboardSummary | null>(null)
const loading = ref(true)

const kpiCards = computed(() => {
  if (!summary.value) return []
  const { kpis } = summary.value

  return [
    {
      label: '新規顧客数',
      value: kpis.new_customers.current,
      suffix: '名',
      icon: 'pi pi-user-plus',
      variant: 'rose' as const,
      delta: calcDeltaPercent(kpis.new_customers.current, kpis.new_customers.previous),
      deltaSuffix: '%',
    },
    {
      label: '予約数',
      value: kpis.reservations.current,
      suffix: '件',
      icon: 'pi pi-calendar',
      variant: 'peach' as const,
      delta: calcDeltaPercent(kpis.reservations.current, kpis.reservations.previous),
      deltaSuffix: '%',
    },
    {
      label: '売上',
      value: kpis.sales.current,
      prefix: '¥',
      icon: 'pi pi-wallet',
      variant: 'mauve' as const,
      delta: calcDeltaPercent(kpis.sales.current, kpis.sales.previous),
      deltaSuffix: '%',
    },
    {
      label: 'リピート率',
      value: kpis.repeat_rate.current,
      suffix: '%',
      icon: 'pi pi-heart',
      variant: 'cream' as const,
      delta: Math.round((kpis.repeat_rate.current - kpis.repeat_rate.previous) * 10) / 10,
      deltaSuffix: 'pt',
    },
  ]
})

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
        <Skeleton v-for="n in 4" :key="n" height="118px" border-radius="20px" />
      </template>
      <template v-else>
        <KpiCard v-for="card in kpiCards" :key="card.label" v-bind="card" />
      </template>
    </div>

    <div class="dash-grid">
      <GlassCard title="売上推移" icon="pi pi-chart-line">
        <Skeleton v-if="loading" height="260px" border-radius="14px" />
        <SalesTrendChart v-else-if="summary" :trend="summary.sales_trend" />
      </GlassCard>

      <GlassCard title="本日の来店予約" icon="pi pi-calendar-clock">
        <template #actions>
          <RouterLink to="/reservations" class="card-link">すべて見る</RouterLink>
        </template>
        <div v-if="loading" class="skeleton-list">
          <Skeleton v-for="n in 4" :key="n" height="52px" border-radius="14px" />
        </div>
        <TodayReservationList
          v-else-if="summary && summary.today_reservations.length > 0"
          :reservations="summary.today_reservations"
        />
        <EmptyState
          v-else
          icon="pi pi-calendar-clock"
          title="本日の予約はありません"
          description="予約カレンダーから予約を登録できます"
        />
      </GlassCard>

      <GlassCard title="人気メニュー" icon="pi pi-star">
        <div v-if="loading" class="skeleton-list">
          <Skeleton v-for="n in 3" :key="n" height="52px" border-radius="14px" />
        </div>
        <PopularMenuList
          v-else-if="summary && summary.popular_menus.length > 0"
          :menus="summary.popular_menus"
        />
        <EmptyState
          v-else
          icon="pi pi-star"
          title="今月の来店実績はまだありません"
          description="来店が確定するとメニュー別の人気が表示されます"
        />
      </GlassCard>

      <GlassCard title="顧客セグメント" icon="pi pi-users">
        <Skeleton v-if="loading" height="96px" border-radius="14px" />
        <CustomerSegmentList v-else-if="summary" :segments="summary.customer_segments" />
      </GlassCard>
    </div>
  </div>
</template>

<style scoped>
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
}

.dash-grid {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 1.25rem;
  align-items: start;
}

.card-link {
  font-size: 0.82rem;
  font-weight: 500;
  color: var(--rb-pink);
  text-decoration: none;
}

.card-link:hover {
  text-decoration: underline;
}

.skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

@media (max-width: 1023px) {
  .kpi-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .dash-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 599px) {
  .kpi-grid {
    grid-template-columns: 1fr;
  }
}
</style>
