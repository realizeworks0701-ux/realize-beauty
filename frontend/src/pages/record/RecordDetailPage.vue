<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import StatusTag from '@/components/common/StatusTag.vue'
import PhotoGrid from '@/components/common/PhotoGrid.vue'
import { recordService } from '@/services/recordService'
import { photoService } from '@/services/photoService'
import { formatDateTime } from '@/utils/format'
import { extractErrorMessage } from '@/utils/apiError'
import type { Photo, TreatmentRecord } from '@/types'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()

const recordId = computed(() => Number(route.params.id))

const record = ref<TreatmentRecord | null>(null)
const photos = ref<Photo[]>([])
const loading = ref(true)
const deleting = ref(false)
const summarizing = ref(false)
const uploading = ref(false)

const fileInput = ref<HTMLInputElement | null>(null)

const sortedBlocks = computed(() =>
  [...(record.value?.blocks ?? [])].sort((a, b) => a.sort_order - b.sort_order),
)

onMounted(async () => {
  try {
    record.value = await recordService.get(recordId.value)
    photos.value = record.value.photos ?? []
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'カルテが見つかりませんでした'),
      life: 3000,
    })
    await router.replace({ name: 'dashboard' })
    return
  } finally {
    loading.value = false
  }
})

function goRecordList(): void {
  if (!record.value) return
  router.push({
    name: 'customer-record-list',
    params: { id: String(record.value.customer.id) },
  })
}

function goEdit(): void {
  router.push({ name: 'record-edit', params: { id: String(recordId.value) } })
}

function confirmDelete(): void {
  if (!record.value) return
  const customerId = record.value.customer.id
  confirm.require({
    message: 'このカルテを削除しますか？この操作は取り消せません。',
    header: 'カルテの削除',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '削除する',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      deleting.value = true
      try {
        await recordService.remove(recordId.value)
        toast.add({ severity: 'success', summary: 'カルテを削除しました', life: 3000 })
        await router.push({ name: 'customer-record-list', params: { id: String(customerId) } })
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: extractErrorMessage(error, 'カルテの削除に失敗しました'),
          life: 3000,
        })
      } finally {
        deleting.value = false
      }
    },
  })
}

async function generateSummary(): Promise<void> {
  if (!record.value) return
  summarizing.value = true
  try {
    const summary = await recordService.summarize(record.value.id)
    record.value = { ...record.value, ai_summary: summary }
    toast.add({ severity: 'success', summary: 'AI要約を生成しました', life: 3000 })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'AI要約の生成に失敗しました'),
      life: 3000,
    })
  } finally {
    summarizing.value = false
  }
}

function confirmRemovePhoto(photo: Photo): void {
  confirm.require({
    message: 'この写真を削除しますか？',
    header: '写真の削除',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '削除する',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      try {
        await photoService.remove(photo.id)
        photos.value = photos.value.filter((p) => p.id !== photo.id)
        toast.add({ severity: 'success', summary: '写真を削除しました', life: 3000 })
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: extractErrorMessage(error, '写真の削除に失敗しました'),
          life: 3000,
        })
      }
    },
  })
}

function openFilePicker(): void {
  if (uploading.value) return
  fileInput.value?.click()
}

async function handleFileSelected(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !record.value) return
  uploading.value = true
  try {
    const photo = await photoService.upload(record.value.id, file)
    photos.value = [...photos.value, photo]
    toast.add({ severity: 'success', summary: '写真を追加しました', life: 3000 })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '写真のアップロードに失敗しました'),
      life: 3000,
    })
  } finally {
    uploading.value = false
    input.value = ''
  }
}
</script>

<template>
  <div class="rb-page">
    <template v-if="loading">
      <div class="skeleton-header">
        <Skeleton shape="circle" size="46px" />
        <div class="skeleton-header-text">
          <Skeleton width="240px" height="1.4rem" border-radius="8px" />
          <Skeleton width="160px" height="0.9rem" border-radius="8px" />
        </div>
      </div>
      <Skeleton height="90px" border-radius="20px" />
      <Skeleton height="220px" border-radius="20px" />
      <Skeleton height="140px" border-radius="20px" />
      <Skeleton height="200px" border-radius="20px" />
    </template>

    <template v-else-if="record">
      <PageHeader
        :title="`${record.customer.name} さんのカルテ`"
        :subtitle="formatDateTime(record.visited_at)"
        icon="pi pi-file-edit"
      >
        <template #actions>
          <Button label="カルテ一覧" icon="pi pi-list" outlined @click="goRecordList" />
          <Button label="編集" icon="pi pi-pencil" outlined @click="goEdit" />
          <Button
            label="削除"
            icon="pi pi-trash"
            severity="danger"
            outlined
            :loading="deleting"
            @click="confirmDelete"
          />
        </template>
      </PageHeader>

      <GlassCard title="基本情報" icon="pi pi-info-circle">
        <div class="info-chips">
          <span class="info-chip">
            <i class="pi pi-calendar" />
            <span class="info-chip-label">来店日時</span>
            {{ formatDateTime(record.visited_at) }}
          </span>
          <StatusTag :status="record.status" />
          <span class="info-chip beige">
            <i class="pi pi-user" />
            <span class="info-chip-label">担当者</span>
            {{ record.user.name }}
          </span>
        </div>
      </GlassCard>

      <GlassCard title="カルテ内容" icon="pi pi-list">
        <div v-if="sortedBlocks.length > 0" class="block-list">
          <div v-for="block in sortedBlocks" :key="block.id" class="block-item">
            <span class="block-label">
              <i class="pi pi-tag" />
              {{ block.label }}
            </span>
            <p class="block-content">{{ block.content }}</p>
          </div>
        </div>
        <EmptyState
          v-else
          icon="pi pi-list"
          title="カルテ内容はまだありません"
          description="編集画面から施術内容などを記入できます"
        />
      </GlassCard>

      <GlassCard title="AI要約" icon="pi pi-sparkles">
        <template #actions>
          <Button
            :label="record.ai_summary ? '再生成' : 'AI要約を生成'"
            icon="pi pi-sparkles"
            size="small"
            :loading="summarizing"
            @click="generateSummary"
          />
        </template>
        <div v-if="record.ai_summary" class="ai-summary-box">
          <p class="ai-summary-text">{{ record.ai_summary }}</p>
        </div>
        <EmptyState
          v-else
          icon="pi pi-sparkles"
          title="AI要約はまだありません"
          description="カルテ内容からAIが要約を作成します"
        />
      </GlassCard>

      <GlassCard title="写真" icon="pi pi-images">
        <PhotoGrid :photos="photos" removable @remove="confirmRemovePhoto">
          <template #append>
            <button
              type="button"
              class="photo-add-tile"
              :disabled="uploading"
              aria-label="写真を追加"
              @click="openFilePicker"
            >
              <i :class="uploading ? 'pi pi-spin pi-spinner' : 'pi pi-plus'" />
              <span>{{ uploading ? 'アップロード中' : '追加' }}</span>
            </button>
          </template>
        </PhotoGrid>
        <input
          ref="fileInput"
          type="file"
          accept="image/*"
          class="hidden-file-input"
          @change="handleFileSelected"
        />
      </GlassCard>
    </template>
  </div>
</template>

<style scoped>
.skeleton-header {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}

.skeleton-header-text {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.info-chips {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.6rem;
}

.info-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.95rem;
  border-radius: 999px;
  background: var(--rb-pink-faint);
  border: 1px solid var(--rb-border);
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--rb-text);
}

.info-chip i {
  font-size: 0.8rem;
  color: var(--rb-pink);
}

.info-chip.beige {
  background: var(--rb-beige-soft);
}

.info-chip.beige i {
  color: var(--rb-beige-deep);
}

.info-chip-label {
  font-size: 0.75rem;
  color: var(--rb-text-muted);
}

.block-list {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
}

.block-item {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.block-label {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.3rem 0.85rem;
  border-radius: 999px;
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
  font-size: 0.8rem;
  font-weight: 700;
}

.block-label i {
  font-size: 0.75rem;
}

.block-content {
  margin: 0;
  padding: 0.85rem 1rem;
  border-radius: var(--rb-radius-md);
  background: rgba(255, 255, 255, 0.55);
  border: 1px solid var(--rb-border);
  font-size: 0.92rem;
  line-height: 1.7;
  white-space: pre-wrap;
  word-break: break-word;
}

.ai-summary-box {
  padding: 1rem 1.2rem;
  border-radius: var(--rb-radius-md);
  background: linear-gradient(135deg, var(--rb-pink-faint), var(--rb-pink-tint));
  border: 1px solid var(--rb-pink-soft);
}

.ai-summary-text {
  margin: 0;
  font-size: 0.92rem;
  line-height: 1.75;
  color: var(--rb-text);
  white-space: pre-wrap;
  word-break: break-word;
}

.photo-add-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  aspect-ratio: 1 / 1;
  border: 2px dashed var(--rb-pink-soft);
  border-radius: 12px;
  background: var(--rb-pink-faint);
  color: var(--rb-pink);
  font-family: var(--rb-font);
  font-size: 0.78rem;
  font-weight: 500;
  cursor: pointer;
  transition:
    background-color 0.15s ease,
    border-color 0.15s ease;
}

.photo-add-tile:hover:not(:disabled) {
  background: var(--rb-pink-tint);
  border-color: var(--rb-pink);
}

.photo-add-tile:disabled {
  cursor: wait;
  opacity: 0.75;
}

.photo-add-tile i {
  font-size: 1.3rem;
}

.hidden-file-input {
  display: none;
}
</style>
