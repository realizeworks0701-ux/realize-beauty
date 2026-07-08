<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Paginator, { type PageState } from 'primevue/paginator'
import Skeleton from 'primevue/skeleton'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import { customerService } from '@/services/customerService'
import { recordService } from '@/services/recordService'
import { formatDate } from '@/utils/format'
import { extractErrorMessage } from '@/utils/apiError'
import type { Customer, PaginationMeta, TreatmentRecord } from '@/types'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()

const PER_PAGE = 10

const customerId = computed(() => Number(route.params.id))

const customer = ref<Customer | null>(null)
const records = ref<TreatmentRecord[]>([])
const meta = ref<PaginationMeta | null>(null)
const loadingCustomer = ref(true)
const loadingRecords = ref(true)
const page = ref(1)

const headerTitle = computed(() =>
  customer.value ? `${customer.value.name} さんのカルテ` : 'カルテ一覧',
)

const showPaginator = computed(
  () => meta.value !== null && meta.value.total > meta.value.per_page,
)

async function loadCustomer(): Promise<void> {
  loadingCustomer.value = true
  try {
    customer.value = await customerService.get(customerId.value)
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '顧客情報の取得に失敗しました'),
      life: 3000,
    })
  } finally {
    loadingCustomer.value = false
  }
}

async function loadRecords(): Promise<void> {
  loadingRecords.value = true
  try {
    const response = await recordService.listByCustomer(customerId.value, {
      page: page.value,
      per_page: PER_PAGE,
    })
    records.value = response.data
    meta.value = response.meta
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'カルテ一覧の取得に失敗しました'),
      life: 3000,
    })
  } finally {
    loadingRecords.value = false
  }
}

function onPage(event: PageState): void {
  page.value = event.page + 1
  loadRecords()
}

function goCustomerDetail(): void {
  router.push({ name: 'customer-detail', params: { id: customerId.value } })
}

function goCreate(): void {
  router.push({ name: 'record-create', params: { id: customerId.value } })
}

function goDetail(record: TreatmentRecord): void {
  router.push({ name: 'record-detail', params: { id: record.id } })
}

function confirmRemove(record: TreatmentRecord): void {
  confirm.require({
    message: `${formatDate(record.visited_at)} のカルテを削除しますか？この操作は取り消せません。`,
    header: 'カルテの削除',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '削除する',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      try {
        await recordService.remove(record.id)
        toast.add({ severity: 'success', summary: 'カルテを削除しました', life: 3000 })
        if (records.value.length === 1 && page.value > 1) {
          page.value -= 1
        }
        await loadRecords()
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: extractErrorMessage(error, 'カルテの削除に失敗しました'),
          life: 3000,
        })
      }
    },
  })
}

onMounted(() => {
  loadCustomer()
  loadRecords()
})
</script>

<template>
  <div class="rb-page">
    <PageHeader
      :title="headerTitle"
      icon="pi pi-file-edit"
      :subtitle="loadingCustomer ? undefined : customer?.kana"
    >
      <template #actions>
        <Button
          label="顧客詳細へ"
          icon="pi pi-arrow-left"
          outlined
          @click="goCustomerDetail"
        />
        <Button label="新規カルテ" icon="pi pi-plus" @click="goCreate" />
      </template>
    </PageHeader>

    <div v-if="loadingRecords" class="timeline">
      <div v-for="n in 4" :key="n" class="timeline-item">
        <div class="timeline-rail">
          <span class="timeline-dot" />
        </div>
        <Skeleton height="88px" border-radius="20px" />
      </div>
    </div>

    <template v-else-if="records.length > 0">
      <div class="timeline">
        <div v-for="record in records" :key="record.id" class="timeline-item">
          <div class="timeline-rail">
            <span class="timeline-dot" />
          </div>
          <article
            class="glass-card record-card"
            role="button"
            tabindex="0"
            @click="goDetail(record)"
            @keydown.enter="goDetail(record)"
          >
            <div class="record-info">
              <div class="record-head">
                <span class="record-date">
                  <i class="pi pi-calendar" />
                  {{ formatDate(record.visited_at) }}
                </span>
                <StatusTag :status="record.status" />
              </div>
              <span class="record-staff">
                <i class="pi pi-user" />
                担当 {{ record.user.name }}
              </span>
            </div>
            <div class="record-side">
              <Button
                icon="pi pi-trash"
                text
                rounded
                severity="danger"
                aria-label="カルテを削除"
                @click.stop="confirmRemove(record)"
              />
              <i class="pi pi-angle-right record-arrow" />
            </div>
          </article>
        </div>
      </div>

      <Paginator
        v-if="showPaginator"
        :rows="meta?.per_page ?? PER_PAGE"
        :total-records="meta?.total ?? 0"
        :first="((meta?.current_page ?? 1) - 1) * (meta?.per_page ?? PER_PAGE)"
        @page="onPage"
      />
    </template>

    <GlassCard v-else>
      <EmptyState
        icon="pi pi-file-edit"
        title="カルテはまだありません"
        description="「新規カルテ」から最初の施術記録を作成しましょう"
      >
        <template #action>
          <Button label="新規カルテ" icon="pi pi-plus" @click="goCreate" />
        </template>
      </EmptyState>
    </GlassCard>
  </div>
</template>

<style scoped>
.timeline {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  padding-left: 0.2rem;
}

.timeline::before {
  content: '';
  position: absolute;
  left: calc(0.2rem + 9px);
  top: 14px;
  bottom: 14px;
  width: 2px;
  border-radius: 999px;
  background: linear-gradient(
    to bottom,
    var(--rb-pink-soft),
    var(--rb-pink-tint)
  );
}

.timeline-item {
  position: relative;
  display: grid;
  grid-template-columns: 20px 1fr;
  gap: 1rem;
  align-items: stretch;
}

.timeline-rail {
  position: relative;
}

.timeline-dot {
  position: absolute;
  top: 28px;
  left: 3px;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--rb-gradient-rose);
  box-shadow:
    0 0 0 4px rgba(246, 201, 214, 0.45),
    0 2px 6px rgba(216, 108, 138, 0.35);
}

.record-card {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.05rem 1.25rem;
  cursor: pointer;
  transition:
    transform 0.18s ease,
    box-shadow 0.18s ease;
}

.record-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--rb-shadow-hover);
}

.record-card:focus-visible {
  outline: 2px solid var(--rb-pink);
  outline-offset: 2px;
}

.record-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.record-head {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  flex-wrap: wrap;
}

.record-date {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  font-family: var(--rb-font-display);
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--rb-text);
}

.record-date i {
  color: var(--rb-pink);
  font-size: 0.95rem;
}

.record-staff {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.84rem;
  color: var(--rb-text-muted);
}

.record-staff i {
  font-size: 0.78rem;
}

.record-side {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-shrink: 0;
}

.record-arrow {
  color: var(--rb-pink-soft);
  font-size: 1rem;
}

@media (max-width: 480px) {
  .record-card {
    padding: 0.9rem 1rem;
  }

  .record-date {
    font-size: 1.02rem;
  }
}
</style>
