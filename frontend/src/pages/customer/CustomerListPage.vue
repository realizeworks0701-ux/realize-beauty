<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import type { DataTablePageEvent, DataTableRowClickEvent } from 'primevue/datatable'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import EmptyState from '@/components/common/EmptyState.vue'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { customerService } from '@/services/customerService'
import { extractErrorMessage } from '@/utils/apiError'
import { formatDate, formatNumber } from '@/utils/format'
import type { Customer, PaginationMeta } from '@/types'

const router = useRouter()
const toast = useToast()
const confirm = useConfirm()

const customers = ref<Customer[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(false)
const initialized = ref(false)
const keyword = ref('')
const page = ref(1)

const rows = computed(() => meta.value?.per_page ?? 10)
const totalRecords = computed(() => meta.value?.total ?? 0)
const first = computed(() => (page.value - 1) * rows.value)
const isSearching = computed(() => keyword.value.trim().length > 0)

// レスポンス追い越し対策: 最新の呼び出し以外の結果は捨てる
let fetchSeq = 0

async function fetchCustomers(): Promise<void> {
  const seq = ++fetchSeq
  loading.value = true
  try {
    const trimmed = keyword.value.trim()
    const result = await customerService.list({
      keyword: trimmed.length > 0 ? trimmed : undefined,
      page: page.value,
    })
    if (seq !== fetchSeq) return
    customers.value = result.data
    meta.value = result.meta
  } catch (error) {
    if (seq !== fetchSeq) return
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '顧客一覧の取得に失敗しました'),
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
    void fetchCustomers()
  }, 300)
})

onMounted(() => {
  void fetchCustomers()
})

onBeforeUnmount(() => {
  if (debounceTimer !== undefined) clearTimeout(debounceTimer)
})

function onPage(event: DataTablePageEvent): void {
  page.value = event.page + 1
  void fetchCustomers()
}

function onRowClick(event: DataTableRowClickEvent): void {
  goToDetail(event.data as Customer)
}

function goToCreate(): void {
  void router.push({ name: 'customer-create' })
}

function goToDetail(customer: Customer): void {
  void router.push({ name: 'customer-detail', params: { id: customer.id } })
}

function goToEdit(customer: Customer): void {
  void router.push({ name: 'customer-edit', params: { id: customer.id } })
}

function confirmRemove(customer: Customer): void {
  confirm.require({
    message: `${customer.name}さんを削除しますか？この操作は取り消せません。`,
    header: '顧客の削除',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '削除する',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      try {
        await customerService.remove(customer.id)
        toast.add({ severity: 'success', summary: '顧客を削除しました', life: 3000 })
        if (customers.value.length === 1 && page.value > 1) {
          page.value -= 1
        }
        await fetchCustomers()
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: extractErrorMessage(error, '顧客の削除に失敗しました'),
          life: 3000,
        })
      }
    },
  })
}

const AVATAR_VARIANTS = ['rose', 'peach', 'mauve'] as const

function avatarVariant(customer: Customer): 'rose' | 'peach' | 'mauve' {
  return AVATAR_VARIANTS[customer.id % AVATAR_VARIANTS.length] ?? 'rose'
}
</script>

<template>
  <div class="rb-page">
    <PageHeader
      title="顧客一覧"
      icon="pi pi-users"
      subtitle="登録されている顧客を検索・管理できます"
    >
      <template #actions>
        <Button label="新規登録" icon="pi pi-plus" @click="goToCreate" />
      </template>
    </PageHeader>

    <GlassCard>
      <div class="search-row">
        <IconField class="search-field">
          <InputIcon class="pi pi-search" />
          <InputText v-model="keyword" placeholder="氏名・フリガナ・電話番号で検索" fluid />
        </IconField>
        <span v-if="initialized" class="total-count">
          <i class="pi pi-user" /> 全 {{ formatNumber(totalRecords) }} 名
        </span>
      </div>

      <div v-if="!initialized" class="skeleton-list">
        <Skeleton v-for="n in 6" :key="n" height="58px" border-radius="14px" />
      </div>

      <DataTable
        v-else-if="customers.length > 0"
        :value="customers"
        lazy
        paginator
        :rows="rows"
        :total-records="totalRecords"
        :first="first"
        :loading="loading"
        row-hover
        table-style="min-width: 640px"
        class="customer-table"
        @page="onPage"
        @row-click="onRowClick"
      >
        <Column header="顧客">
          <template #body="{ data }">
            <div class="customer-cell">
              <Avatar
                :label="data.name.charAt(0)"
                shape="circle"
                class="customer-avatar"
                :class="avatarVariant(data)"
              />
              <div class="customer-names">
                <span class="customer-name">{{ data.name }}</span>
                <span class="customer-kana">{{ data.kana }}</span>
              </div>
            </div>
          </template>
        </Column>

        <Column header="電話番号" style="width: 22%">
          <template #body="{ data }">
            <span class="phone-cell">
              <i class="pi pi-phone" />
              {{ data.phone ?? '—' }}
            </span>
          </template>
        </Column>

        <Column header="最終来店" style="width: 18%">
          <template #body="{ data }">
            <span class="visit-cell">
              <i class="pi pi-calendar" />
              {{ formatDate(data.last_visit_at) }}
            </span>
          </template>
        </Column>

        <Column header="操作" style="width: 150px">
          <template #body="{ data }">
            <div class="action-cell">
              <Button
                icon="pi pi-eye"
                text
                rounded
                severity="secondary"
                aria-label="詳細"
                @click.stop="goToDetail(data)"
              />
              <Button
                icon="pi pi-pencil"
                text
                rounded
                severity="secondary"
                aria-label="編集"
                @click.stop="goToEdit(data)"
              />
              <Button
                icon="pi pi-trash"
                text
                rounded
                severity="danger"
                aria-label="削除"
                @click.stop="confirmRemove(data)"
              />
            </div>
          </template>
        </Column>
      </DataTable>

      <EmptyState
        v-else-if="isSearching"
        icon="pi pi-search"
        title="該当する顧客が見つかりません"
        description="キーワードを変えて再度お試しください"
      />

      <EmptyState
        v-else
        icon="pi pi-users"
        title="顧客はまだいません"
        description="最初の顧客を登録してみましょう"
      >
        <template #action>
          <Button label="新規登録" icon="pi pi-plus" @click="goToCreate" />
        </template>
      </EmptyState>
    </GlassCard>
  </div>
</template>

<style scoped>
.search-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1.1rem;
}

.search-field {
  flex: 1 1 260px;
  max-width: 440px;
}

.total-count {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
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

.customer-table :deep(.p-datatable-tbody > tr) {
  cursor: pointer;
}

.customer-cell {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.customer-avatar {
  color: #fff;
  font-weight: 700;
  flex-shrink: 0;
}

.customer-avatar.rose {
  background: var(--rb-gradient-rose);
}

.customer-avatar.peach {
  background: var(--rb-gradient-peach);
}

.customer-avatar.mauve {
  background: var(--rb-gradient-mauve);
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

.phone-cell,
.visit-cell {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.88rem;
}

.phone-cell i,
.visit-cell i {
  font-size: 0.78rem;
  color: var(--rb-pink);
}

.visit-cell i {
  color: var(--rb-beige-deep);
}

.action-cell {
  display: flex;
  align-items: center;
  gap: 0.15rem;
}
</style>
