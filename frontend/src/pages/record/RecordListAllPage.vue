<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import type { DataTablePageEvent, DataTableRowClickEvent } from 'primevue/datatable'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import EmptyState from '@/components/common/EmptyState.vue'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { recordService } from '@/services/recordService'
import { extractErrorMessage } from '@/utils/apiError'
import { RECORD_STATUS_LABELS, formatDate, formatNumber } from '@/utils/format'
import type { PaginationMeta, RecordStatus, TreatmentRecord } from '@/types'

const router = useRouter()
const toast = useToast()

/** Select は null を「未選択」とみなしラベルが空欄になるため、絞り込み解除は番兵値で表す */
type StatusFilter = RecordStatus | 'all'

const statusOptions: { label: string; value: StatusFilter }[] = [
  { label: 'すべて', value: 'all' },
  { label: RECORD_STATUS_LABELS.draft, value: 'draft' },
  { label: RECORD_STATUS_LABELS.completed, value: 'completed' },
]

const records = ref<TreatmentRecord[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(false)
const initialized = ref(false)
const keyword = ref('')
const status = ref<StatusFilter>('all')
const page = ref(1)

const rows = computed(() => meta.value?.per_page ?? 20)
const totalRecords = computed(() => meta.value?.total ?? 0)
const first = computed(() => (page.value - 1) * rows.value)
const isFiltering = computed(() => keyword.value.trim().length > 0 || status.value !== 'all')

// レスポンス追い越し対策: 最新の呼び出し以外の結果は捨てる
let fetchSeq = 0

async function fetchRecords(): Promise<void> {
  const seq = ++fetchSeq
  loading.value = true
  try {
    const trimmed = keyword.value.trim()
    const result = await recordService.list({
      status: status.value === 'all' ? undefined : status.value,
      keyword: trimmed.length > 0 ? trimmed : undefined,
      page: page.value,
    })
    if (seq !== fetchSeq) return
    records.value = result.data
    meta.value = result.meta
  } catch (error) {
    if (seq !== fetchSeq) return
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'カルテ一覧の取得に失敗しました'),
      life: 3000,
    })
  } finally {
    if (seq === fetchSeq) {
      loading.value = false
      initialized.value = true
    }
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined

watch(keyword, () => {
  if (debounceTimer !== undefined) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    void fetchRecords()
  }, 300)
})

watch(status, () => {
  if (debounceTimer !== undefined) clearTimeout(debounceTimer)
  page.value = 1
  void fetchRecords()
})

onMounted(() => {
  void fetchRecords()
})

onBeforeUnmount(() => {
  if (debounceTimer !== undefined) clearTimeout(debounceTimer)
})

function onPage(event: DataTablePageEvent): void {
  page.value = event.page + 1
  void fetchRecords()
}

function onRowClick(event: DataTableRowClickEvent): void {
  goToDetail(event.data as TreatmentRecord)
}

function goToDetail(record: TreatmentRecord): void {
  void router.push({ name: 'record-detail', params: { id: record.id } })
}
</script>

<template>
  <div class="rb-page">
    <PageHeader
      title="カルテ一覧"
      icon="pi pi-file-edit"
      subtitle="サロン全体のカルテを検索・閲覧できます"
    />

    <GlassCard>
      <div class="search-row">
        <IconField class="search-field">
          <InputIcon class="pi pi-search" />
          <InputText v-model="keyword" placeholder="顧客の氏名・フリガナで検索" fluid />
        </IconField>
        <Select
          v-model="status"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          class="status-field"
          aria-label="ステータス"
        />
        <span v-if="initialized" class="total-count">
          <i class="pi pi-file-edit" /> 全 {{ formatNumber(totalRecords) }} 件
        </span>
      </div>

      <div v-if="!initialized" class="skeleton-list">
        <Skeleton v-for="n in 6" :key="n" height="58px" border-radius="14px" />
      </div>

      <DataTable
        v-else-if="records.length > 0"
        :value="records"
        lazy
        paginator
        :rows="rows"
        :total-records="totalRecords"
        :first="first"
        :loading="loading"
        row-hover
        table-style="min-width: 640px"
        class="record-table"
        @page="onPage"
        @row-click="onRowClick"
      >
        <Column header="来店日" style="width: 20%">
          <template #body="{ data }">
            <span class="visit-cell">
              <i class="pi pi-calendar" />
              {{ formatDate(data.visited_at) }}
            </span>
          </template>
        </Column>

        <Column header="顧客名">
          <template #body="{ data }">
            <div class="customer-names">
              <span class="customer-name">{{ data.customer.name }}</span>
              <span class="customer-kana">{{ data.customer.kana }}</span>
            </div>
          </template>
        </Column>

        <Column header="担当" style="width: 20%">
          <template #body="{ data }">
            <span class="staff-cell">
              <i class="pi pi-user" />
              {{ data.user.name }}
            </span>
          </template>
        </Column>

        <Column header="ステータス" style="width: 140px">
          <template #body="{ data }">
            <StatusTag :status="data.status" />
          </template>
        </Column>

        <Column header="操作" style="width: 90px">
          <template #body="{ data }">
            <Button
              icon="pi pi-eye"
              text
              rounded
              severity="secondary"
              aria-label="詳細"
              @click.stop="goToDetail(data)"
            />
          </template>
        </Column>
      </DataTable>

      <EmptyState
        v-else-if="isFiltering"
        icon="pi pi-search"
        title="該当するカルテが見つかりません"
        description="キーワードやステータスを変えて再度お試しください"
      />

      <EmptyState
        v-else
        icon="pi pi-file-edit"
        title="カルテはまだありません"
        description="顧客詳細から最初の施術記録を作成しましょう"
      />
    </GlassCard>
  </div>
</template>

<style scoped>
.search-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-bottom: 1.1rem;
}

.search-field {
  flex: 1 1 260px;
  max-width: 440px;
}

.status-field {
  width: 150px;
}

.total-count {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  margin-left: auto;
  padding: 0.35rem 0.9rem;
  border-radius: 999px;
  background: var(--rb-pink-faint);
  color: var(--rb-pink-deep);
  font-size: 0.82rem;
  font-weight: 500;
  white-space: nowrap;
}

.total-count i {
  font-size: 0.78rem;
}

.skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.record-table :deep(.p-datatable-tbody > tr) {
  cursor: pointer;
}

.customer-names {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.customer-name {
  font-weight: 700;
  font-size: 0.92rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.customer-kana {
  font-size: 0.76rem;
  color: var(--rb-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.visit-cell,
.staff-cell {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.88rem;
}

.visit-cell i,
.staff-cell i {
  font-size: 0.78rem;
  color: var(--rb-pink);
}

.visit-cell i {
  color: var(--rb-beige-deep);
}
</style>
