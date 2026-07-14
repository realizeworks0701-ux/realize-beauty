<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import Textarea from 'primevue/textarea'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import PhotoGrid from '@/components/common/PhotoGrid.vue'
import { customerService } from '@/services/customerService'
import { recordService } from '@/services/recordService'
import { photoService } from '@/services/photoService'
import { extractErrorMessage, extractFieldErrors } from '@/utils/apiError'
import { toIsoWithOffset } from '@/utils/format'
import type { Photo, RecordBlockInput, RecordStatus } from '@/types'

interface BlockForm {
  key: number
  id: number | null
  label: string
  content: string
}

const route = useRoute()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()

const isEdit = computed(() => route.name === 'record-edit')
const routeId = computed(() => Number(route.params.id))

const loading = ref(true)
const savingStatus = ref<RecordStatus | null>(null)
const saving = computed(() => savingStatus.value !== null)

const customerName = ref('')
const visitedAt = ref<Date | null>(null)
const blocks = ref<BlockForm[]>([])
const photos = ref<Photo[]>([])

const fieldErrors = ref<Record<string, string>>({})
const blocksError = ref('')

const uploading = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

let blockKeySeq = 0

function newBlock(label = '', content = '', id: number | null = null): BlockForm {
  blockKeySeq += 1
  return { key: blockKeySeq, id, label, content }
}

onMounted(async () => {
  try {
    if (isEdit.value) {
      const record = await recordService.get(routeId.value)
      customerName.value = record.customer.name
      visitedAt.value = new Date(record.visited_at)
      const sorted = [...(record.blocks ?? [])].sort((a, b) => a.sort_order - b.sort_order)
      blocks.value =
        sorted.length > 0
          ? sorted.map((block) => newBlock(block.label, block.content, block.id))
          : [newBlock('施術内容'), newBlock('カウンセリング')]
      photos.value = record.photos ?? []
    } else {
      const customer = await customerService.get(routeId.value)
      customerName.value = customer.name
      visitedAt.value = new Date()
      blocks.value = [newBlock('施術内容'), newBlock('カウンセリング')]
    }
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(
        error,
        isEdit.value ? 'カルテが見つかりませんでした' : '顧客が見つかりませんでした',
      ),
      life: 3000,
    })
    await router.replace(
      isEdit.value ? { name: 'dashboard' } : { name: 'customer-list' },
    )
    return
  } finally {
    loading.value = false
  }
})

function addBlock(): void {
  blocks.value.push(newBlock())
}

function moveBlock(index: number, delta: -1 | 1): void {
  const target = index + delta
  if (target < 0 || target >= blocks.value.length) return
  const list = [...blocks.value]
  const [item] = list.splice(index, 1)
  if (!item) return
  list.splice(target, 0, item)
  blocks.value = list
}

function removeBlock(index: number): void {
  if (blocks.value.length <= 1) return
  blocks.value.splice(index, 1)
}

interface ValidatedPayload {
  blocks: RecordBlockInput[]
  /** payload の index → 画面上の index（422エラーの読み替え用） */
  indexMap: number[]
}

function validate(): ValidatedPayload | null {
  fieldErrors.value = {}
  blocksError.value = ''
  const errors: Record<string, string> = {}

  if (!visitedAt.value) {
    errors['visited_at'] = '来店日時を入力してください'
  }

  const payloadBlocks: RecordBlockInput[] = []
  const indexMap: number[] = []

  blocks.value.forEach((block, index) => {
    const label = block.label.trim()
    const content = block.content.trim()
    if (label === '' && content === '') return
    if (label === '') {
      errors[`blocks.${index}.label`] = '項目名を入力してください'
      return
    }
    if (content === '') {
      errors[`blocks.${index}.content`] = '内容を入力してください'
      return
    }
    payloadBlocks.push({
      ...(block.id != null ? { id: block.id } : {}),
      label,
      content,
      sort_order: payloadBlocks.length,
    })
    indexMap.push(index)
  })

  if (Object.keys(errors).length === 0 && payloadBlocks.length === 0) {
    blocksError.value = 'カルテ項目を1つ以上入力してください'
    return null
  }

  if (Object.keys(errors).length > 0) {
    fieldErrors.value = errors
    return null
  }

  return { blocks: payloadBlocks, indexMap }
}

function applyServerErrors(error: unknown, indexMap: number[]): boolean {
  const raw = extractFieldErrors(error)
  const keys = Object.keys(raw)
  if (keys.length === 0) return false

  const mapped: Record<string, string> = {}
  for (const key of keys) {
    if (key === 'blocks') {
      blocksError.value = raw[key] ?? ''
      continue
    }
    const match = key.match(/^blocks\.(\d+)\.(.+)$/)
    if (match) {
      const uiIndex = indexMap[Number(match[1])]
      if (uiIndex != null) {
        mapped[`blocks.${uiIndex}.${match[2]}`] = raw[key] ?? ''
        continue
      }
    }
    mapped[key] = raw[key] ?? ''
  }
  fieldErrors.value = mapped
  toast.add({ severity: 'error', summary: '入力内容をご確認ください', life: 3000 })
  return true
}

async function save(status: RecordStatus): Promise<void> {
  if (saving.value) return
  const validated = validate()
  if (!validated || !visitedAt.value) return

  savingStatus.value = status
  try {
    const input = {
      visited_at: toIsoWithOffset(visitedAt.value),
      status,
      blocks: validated.blocks,
    }
    const saved = isEdit.value
      ? await recordService.update(routeId.value, input)
      : await recordService.create(routeId.value, input)
    toast.add({
      severity: 'success',
      summary: status === 'draft' ? '下書きを保存しました' : 'カルテを保存しました',
      life: 3000,
    })
    await router.push({ name: 'record-detail', params: { id: String(saved.id) } })
  } catch (error) {
    if (!applyServerErrors(error, validated.indexMap)) {
      toast.add({
        severity: 'error',
        summary: extractErrorMessage(error, 'カルテの保存に失敗しました'),
        life: 3000,
      })
    }
  } finally {
    savingStatus.value = null
  }
}

function cancel(): void {
  router.back()
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
  if (!file) return
  uploading.value = true
  try {
    const photo = await photoService.upload(routeId.value, file)
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
          <Skeleton width="220px" height="1.4rem" border-radius="8px" />
          <Skeleton width="140px" height="0.9rem" border-radius="8px" />
        </div>
      </div>
      <Skeleton height="120px" border-radius="20px" />
      <Skeleton height="320px" border-radius="20px" />
      <Skeleton height="80px" border-radius="20px" />
    </template>

    <template v-else>
      <PageHeader
        :title="isEdit ? 'カルテ編集' : 'カルテ作成'"
        :subtitle="customerName ? `${customerName} さん` : ''"
        icon="pi pi-file-edit"
      />

      <GlassCard title="基本情報" icon="pi pi-calendar">
        <div class="field visited-field">
          <label class="field-label" for="visited-at">
            <i class="pi pi-clock" />
            来店日時
            <span class="required-mark">必須</span>
          </label>
          <DatePicker
            v-model="visitedAt"
            input-id="visited-at"
            date-format="yy/mm/dd"
            show-time
            hour-format="24"
            show-icon
            icon-display="input"
            fluid
            placeholder="来店日時を選択"
            :invalid="Boolean(fieldErrors['visited_at'])"
          />
          <small v-if="fieldErrors['visited_at']" class="field-error">
            <i class="pi pi-exclamation-circle" />
            {{ fieldErrors['visited_at'] }}
          </small>
        </div>
      </GlassCard>

      <GlassCard title="カルテ内容" icon="pi pi-list">
        <div class="block-list">
          <div v-for="(block, index) in blocks" :key="block.key" class="block-editor">
            <div class="block-head">
              <span class="block-badge">
                <i class="pi pi-tag" />
                項目 {{ index + 1 }}
              </span>
              <div class="block-tools">
                <Button
                  icon="pi pi-arrow-up"
                  text
                  rounded
                  severity="secondary"
                  :disabled="index === 0"
                  aria-label="上へ移動"
                  @click="moveBlock(index, -1)"
                />
                <Button
                  icon="pi pi-arrow-down"
                  text
                  rounded
                  severity="secondary"
                  :disabled="index === blocks.length - 1"
                  aria-label="下へ移動"
                  @click="moveBlock(index, 1)"
                />
                <Button
                  icon="pi pi-trash"
                  text
                  rounded
                  severity="danger"
                  :disabled="blocks.length === 1"
                  aria-label="項目を削除"
                  @click="removeBlock(index)"
                />
              </div>
            </div>

            <div class="field">
              <label class="field-label" :for="`block-label-${block.key}`">
                <i class="pi pi-bookmark" />
                項目名
              </label>
              <InputText
                :id="`block-label-${block.key}`"
                v-model="block.label"
                placeholder="例：施術内容、使用薬剤"
                fluid
                :invalid="Boolean(fieldErrors[`blocks.${index}.label`])"
              />
              <small v-if="fieldErrors[`blocks.${index}.label`]" class="field-error">
                <i class="pi pi-exclamation-circle" />
                {{ fieldErrors[`blocks.${index}.label`] }}
              </small>
            </div>

            <div class="field">
              <label class="field-label" :for="`block-content-${block.key}`">
                <i class="pi pi-pencil" />
                内容
              </label>
              <Textarea
                :id="`block-content-${block.key}`"
                v-model="block.content"
                auto-resize
                rows="3"
                fluid
                placeholder="施術の内容やお客様の様子などを記入"
                :invalid="Boolean(fieldErrors[`blocks.${index}.content`])"
              />
              <small v-if="fieldErrors[`blocks.${index}.content`]" class="field-error">
                <i class="pi pi-exclamation-circle" />
                {{ fieldErrors[`blocks.${index}.content`] }}
              </small>
            </div>
          </div>
        </div>

        <div class="block-footer">
          <Button label="項目を追加" icon="pi pi-plus" outlined @click="addBlock" />
        </div>
        <small v-if="blocksError" class="field-error blocks-error">
          <i class="pi pi-exclamation-circle" />
          {{ blocksError }}
        </small>
      </GlassCard>

      <GlassCard v-if="isEdit" title="写真" icon="pi pi-images">
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

      <div v-else class="photo-note">
        <i class="pi pi-info-circle" />
        写真はカルテ保存後に追加できます
      </div>

      <GlassCard>
        <div class="form-actions">
          <Button
            label="キャンセル"
            text
            severity="secondary"
            :disabled="saving"
            @click="cancel"
          />
          <Button
            label="下書き保存"
            icon="pi pi-save"
            outlined
            :loading="savingStatus === 'draft'"
            :disabled="saving && savingStatus !== 'draft'"
            @click="save('draft')"
          />
          <Button
            label="保存"
            icon="pi pi-check"
            :loading="savingStatus === 'completed'"
            :disabled="saving && savingStatus !== 'completed'"
            @click="save('completed')"
          />
        </div>
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

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.visited-field {
  max-width: 340px;
}

.field-label {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--rb-text);
}

.field-label i {
  font-size: 0.75rem;
  color: var(--rb-pink);
}

.required-mark {
  padding: 0.1rem 0.55rem;
  border-radius: 999px;
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
  font-size: 0.68rem;
  font-weight: 700;
}

.field-error {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--rb-pink-strong);
  font-size: 0.78rem;
}

.field-error i {
  font-size: 0.75rem;
}

.block-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.block-editor {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem 1.1rem 1.15rem;
  border-radius: var(--rb-radius-md);
  background: rgba(255, 255, 255, 0.55);
  border: 1px solid var(--rb-border);
}

.block-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
}

.block-badge {
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

.block-badge i {
  font-size: 0.75rem;
}

.block-tools {
  display: flex;
  align-items: center;
  gap: 0.15rem;
}

.block-footer {
  margin-top: 1rem;
}

.blocks-error {
  display: flex;
  margin-top: 0.6rem;
}

.photo-note {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.9rem 1.2rem;
  border-radius: var(--rb-radius-md);
  border: 1px dashed var(--rb-pink-soft);
  background: var(--rb-pink-faint);
  color: var(--rb-text-muted);
  font-size: 0.88rem;
}

.photo-note i {
  color: var(--rb-pink);
}

.form-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 0.6rem;
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

@media (max-width: 640px) {
  .visited-field {
    max-width: none;
  }
}
</style>
