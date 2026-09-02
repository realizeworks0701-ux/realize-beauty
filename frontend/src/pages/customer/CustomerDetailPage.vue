<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { useToast } from 'primevue/usetoast'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import PhotoGrid from '@/components/common/PhotoGrid.vue'
import { customerService } from '@/services/customerService'
import { recordService } from '@/services/recordService'
import { calcAge, formatDate, genderLabel } from '@/utils/format'
import { extractErrorMessage } from '@/utils/apiError'
import type { Customer, Photo, TreatmentRecord } from '@/types'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const customerId = Number(route.params.id)

const customer = ref<Customer | null>(null)
const loading = ref(true)

const activeTab = ref<string | number>('info')

const records = ref<TreatmentRecord[]>([])
const recordsLoading = ref(false)
const recordsLoaded = ref(false)

const photos = ref<Photo[]>([])
const photosLoading = ref(false)
const photosLoaded = ref(false)

onMounted(loadCustomer)

watch(activeTab, (tab) => {
  if (tab === 'records' && !recordsLoaded.value) {
    recordsLoaded.value = true
    void loadRecords()
  }
  if (tab === 'photos' && !photosLoaded.value) {
    photosLoaded.value = true
    void loadPhotos()
  }
})

async function loadCustomer(): Promise<void> {
  loading.value = true
  try {
    customer.value = await customerService.get(customerId)
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '顧客情報の取得に失敗しました'),
      life: 3000,
    })
    await router.replace({ name: 'customer-list' })
    return
  } finally {
    loading.value = false
  }
}

async function loadRecords(): Promise<void> {
  recordsLoading.value = true
  try {
    const result = await recordService.listByCustomer(customerId, { per_page: 20 })
    records.value = result.data
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'カルテの取得に失敗しました'),
      life: 3000,
    })
  } finally {
    recordsLoading.value = false
  }
}

async function loadPhotos(): Promise<void> {
  photosLoading.value = true
  try {
    const result = await recordService.listByCustomer(customerId, { per_page: 20 })
    const sorted = [...result.data].sort(
      (a, b) => new Date(b.visited_at).getTime() - new Date(a.visited_at).getTime(),
    )
    const details = await Promise.all(sorted.map((record) => recordService.get(record.id)))
    photos.value = details.flatMap((record) => record.photos ?? [])
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '写真の取得に失敗しました'),
      life: 3000,
    })
  } finally {
    photosLoading.value = false
  }
}

function goEdit(): void {
  void router.push({ name: 'customer-edit', params: { id: customerId } })
}

function goRecordCreate(): void {
  void router.push({ name: 'record-create', params: { id: customerId } })
}

function birthdayText(target: Customer): string {
  if (!target.birthday) return '—'
  const age = calcAge(target.birthday)
  return age == null ? formatDate(target.birthday) : `${formatDate(target.birthday)}（${age}歳）`
}
</script>

<template>
  <div class="rb-page">
    <template v-if="loading">
      <div class="header-skeleton">
        <Skeleton shape="circle" size="46px" border-radius="15px" />
        <div class="header-skeleton-text">
          <Skeleton width="180px" height="1.4rem" />
          <Skeleton width="120px" height="0.9rem" />
        </div>
      </div>
      <Skeleton height="320px" border-radius="20px" />
    </template>

    <template v-else-if="customer">
      <PageHeader :title="customer.name" :subtitle="customer.kana" icon="pi pi-user">
        <template #actions>
          <Button label="編集" icon="pi pi-pencil" outlined @click="goEdit" />
          <Button label="カルテ作成" icon="pi pi-plus" @click="goRecordCreate" />
        </template>
      </PageHeader>

      <Tabs v-model:value="activeTab" class="detail-tabs">
        <TabList>
          <Tab value="info"><i class="pi pi-id-card tab-icon" />基本情報</Tab>
          <Tab value="records"><i class="pi pi-file-edit tab-icon" />カルテ</Tab>
          <Tab value="photos"><i class="pi pi-images tab-icon" />写真</Tab>
        </TabList>

        <TabPanels>
          <!-- 基本情報 -->
          <TabPanel value="info">
            <GlassCard title="基本情報" icon="pi pi-id-card">
              <dl class="info-grid">
                <div class="info-item">
                  <dt><i class="pi pi-phone" />電話番号</dt>
                  <dd>{{ customer.phone ?? '—' }}</dd>
                </div>
                <div class="info-item">
                  <dt><i class="pi pi-envelope" />メールアドレス</dt>
                  <dd>{{ customer.email ?? '—' }}</dd>
                </div>
                <div class="info-item">
                  <dt><i class="pi pi-heart" />性別</dt>
                  <dd>{{ genderLabel(customer.gender) }}</dd>
                </div>
                <div class="info-item">
                  <dt><i class="pi pi-gift" />生年月日</dt>
                  <dd>{{ birthdayText(customer) }}</dd>
                </div>
                <div class="info-item">
                  <dt><i class="pi pi-calendar-plus" />初回来店</dt>
                  <dd>{{ formatDate(customer.first_visit_at) }}</dd>
                </div>
                <div class="info-item">
                  <dt><i class="pi pi-history" />最終来店</dt>
                  <dd>{{ formatDate(customer.last_visit_at) }}</dd>
                </div>
                <div class="info-item info-item-full">
                  <dt><i class="pi pi-comment" />メモ</dt>
                  <dd class="info-memo">{{ customer.memo ?? '—' }}</dd>
                </div>
              </dl>
            </GlassCard>
          </TabPanel>

          <!-- カルテ -->
          <TabPanel value="records">
            <GlassCard title="カルテ" icon="pi pi-file-edit">
              <template #actions>
                <RouterLink
                  :to="{ name: 'customer-record-list', params: { id: customerId } }"
                  class="records-link"
                >
                  <i class="pi pi-list" />カルテ一覧
                </RouterLink>
                <Button label="新規カルテ" icon="pi pi-plus" size="small" @click="goRecordCreate" />
              </template>

              <div v-if="recordsLoading" class="skeleton-list">
                <Skeleton v-for="n in 4" :key="n" height="64px" border-radius="14px" />
              </div>

              <ul v-else-if="records.length > 0" class="record-list">
                <li v-for="record in records" :key="record.id">
                  <RouterLink
                    :to="{ name: 'record-detail', params: { id: record.id } }"
                    class="record-row"
                  >
                    <span class="record-date">
                      <i class="pi pi-calendar" />
                      {{ formatDate(record.visited_at) }}
                    </span>
                    <StatusTag :status="record.status" />
                    <span class="record-user">
                      <i class="pi pi-user" />
                      担当 {{ record.user.name }}
                    </span>
                    <i class="pi pi-angle-right record-arrow" />
                  </RouterLink>
                </li>
              </ul>

              <EmptyState
                v-else
                icon="pi pi-file-edit"
                title="カルテはまだありません"
                description="最初のカルテを作成しましょう"
              >
                <template #action>
                  <Button label="新規カルテを作成" icon="pi pi-plus" @click="goRecordCreate" />
                </template>
              </EmptyState>
            </GlassCard>
          </TabPanel>

          <!-- 写真 -->
          <TabPanel value="photos">
            <GlassCard title="写真" icon="pi pi-images">
              <div v-if="photosLoading" class="photo-skeleton-grid">
                <div v-for="n in 8" :key="n" class="photo-skeleton">
                  <Skeleton width="100%" height="100%" border-radius="12px" />
                </div>
              </div>

              <PhotoGrid v-else-if="photos.length > 0" :photos="photos" />

              <EmptyState
                v-else
                icon="pi pi-images"
                title="写真はまだありません"
                description="カルテに写真を追加するとここに表示されます"
              />
            </GlassCard>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </template>
  </div>
</template>

<style scoped>
.header-skeleton {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}

.header-skeleton-text {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.detail-tabs :deep(.p-tabpanels) {
  background: transparent;
  padding: 1.25rem 0 0;
}

.detail-tabs :deep(.p-tab) {
  display: inline-flex;
  align-items: center;
}

.detail-tabs :deep(.p-tab-active) {
  color: var(--rb-pink-strong);
}

.tab-icon {
  font-size: 0.85rem;
  margin-right: 0.4rem;
}

/* ---------- 基本情報 ---------- */

.info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.1rem 1.5rem;
  margin: 0;
}

@media (max-width: 640px) {
  .info-grid {
    grid-template-columns: 1fr;
  }
}

.info-item {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  padding: 0.85rem 1rem;
  border-radius: var(--rb-radius-md);
  background: var(--rb-primary-faint);
  border: 1px solid var(--rb-border);
}

.info-item-full {
  grid-column: 1 / -1;
}

.info-item dt {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.78rem;
  font-weight: 500;
  color: var(--rb-text-muted);
}

.info-item dt i {
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border-radius: 8px;
  background: var(--rb-pink-tint);
  color: var(--rb-pink-strong);
  font-size: 0.72rem;
}

.info-item dd {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--rb-text);
  overflow-wrap: anywhere;
}

.info-memo {
  white-space: pre-wrap;
  line-height: 1.7;
}

/* ---------- カルテ ---------- */

.records-link {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--rb-pink-strong);
  text-decoration: none;
  padding: 0.35rem 0.6rem;
  border-radius: 10px;
  transition: background-color 0.15s ease;
}

.records-link:hover {
  background: var(--rb-pink-faint);
}

.skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.record-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.record-row {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  flex-wrap: wrap;
  padding: 0.85rem 1rem;
  border-radius: var(--rb-radius-md);
  border: 1px solid var(--rb-border);
  background: var(--rb-surface-subtle);
  color: var(--rb-text);
  text-decoration: none;
  transition:
    transform 0.18s ease,
    box-shadow 0.18s ease,
    background-color 0.18s ease;
}

.record-row:hover {
  transform: translateY(-2px);
  box-shadow: var(--rb-shadow-hover);
  background: var(--rb-surface);
}

.record-date {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.3rem 0.85rem;
  border-radius: 999px;
  background: var(--rb-gradient-rose);
  color: #fff;
  font-size: 0.82rem;
  font-weight: 700;
}

.record-date i {
  font-size: 0.78rem;
}

.record-user {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.85rem;
  color: var(--rb-text-muted);
}

.record-user i {
  font-size: 0.78rem;
}

.record-arrow {
  margin-left: auto;
  color: var(--rb-pink-soft);
}

/* ---------- 写真 ---------- */

.photo-skeleton-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
}

@media (min-width: 900px) {
  .photo-skeleton-grid {
    grid-template-columns: repeat(4, 1fr);
  }
}

.photo-skeleton {
  aspect-ratio: 1 / 1;
}
</style>
