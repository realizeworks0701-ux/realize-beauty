<script setup lang="ts">
import { computed } from 'vue'
import Chart from 'primevue/chart'
import type { SalesTrendPoint } from '@/types'

const props = defineProps<{ trend: SalesTrendPoint[] }>()

// --rb-* トークンと同値（chart.js は CSS 変数を解釈できないため）
const ROSE = '#d86c8a'
const ROSE_FILL = 'rgba(216, 108, 138, 0.16)'
const TEXT_MUTED = '#9a8d91'
const GRID = '#f0e4e8'

const chartData = computed(() => ({
  labels: props.trend.map((point) => `${Number(point.month.slice(5))}月`),
  datasets: [
    {
      data: props.trend.map((point) => point.sales),
      borderColor: ROSE,
      backgroundColor: ROSE_FILL,
      fill: true,
      tension: 0.4,
      borderWidth: 2,
      pointBackgroundColor: '#fff',
      pointBorderColor: ROSE,
      pointRadius: 4,
      pointHoverRadius: 5,
    },
  ],
}))

const chartOptions = {
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (context: { parsed: { y: number } }) =>
          `¥${context.parsed.y.toLocaleString('ja-JP')}`,
      },
    },
  },
  scales: {
    x: {
      grid: { display: false },
      ticks: { color: TEXT_MUTED },
    },
    y: {
      beginAtZero: true,
      grid: { color: GRID },
      ticks: {
        color: TEXT_MUTED,
        callback: (value: number | string) => `¥${Number(value).toLocaleString('ja-JP')}`,
      },
    },
  },
}
</script>

<template>
  <div class="chart-wrap">
    <Chart type="line" :data="chartData" :options="chartOptions" class="chart" />
  </div>
</template>

<style scoped>
.chart-wrap {
  position: relative;
  height: 260px;
}

.chart,
.chart-wrap :deep(.p-chart) {
  height: 100%;
}

.chart-wrap :deep(canvas) {
  max-width: 100%;
}
</style>
