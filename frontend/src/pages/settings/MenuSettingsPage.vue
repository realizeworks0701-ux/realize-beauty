<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import EmptyState from '@/components/common/EmptyState.vue'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { menuService } from '@/services/menuService'
import { extractErrorMessage, extractFieldErrors } from '@/utils/apiError'
import { formatNumber } from '@/utils/format'
import type { Menu, MenuCreateInput, MenuUpdateInput } from '@/types'

const toast = useToast()
const confirm = useConfirm()

const menus = ref<Menu[]>([])
const initialized = ref(false)
const loading = ref(false)
const reordering = ref(false)

const dialogVisible = ref(false)
const editingMenu = ref<Menu | null>(null)
const saving = ref(false)
const fieldErrors = ref<Record<string, string>>({})

const form = reactive<{
  name: string
  price: number | null
  duration_minutes: number | null
  is_active: boolean
}>({
  name: '',
  price: null,
  duration_minutes: null,
  is_active: true,
})

const isEdit = computed(() => editingMenu.value !== null)

async function fetchMenus(): Promise<void> {
  loading.value = true
  try {
    menus.value = await menuService.list()
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'メニューの取得に失敗しました'),
      life: 3000,
    })
  } finally {
    loading.value = false
    initialized.value = true
  }
}

onMounted(() => {
  void fetchMenus()
})

// ---- ダイアログ ----

function openCreate(): void {
  editingMenu.value = null
  form.name = ''
  form.price = null
  form.duration_minutes = null
  form.is_active = true
  fieldErrors.value = {}
  dialogVisible.value = true
}

function openEdit(menu: Menu): void {
  editingMenu.value = menu
  form.name = menu.name
  form.price = menu.price
  form.duration_minutes = menu.duration_minutes
  form.is_active = menu.is_active
  fieldErrors.value = {}
  dialogVisible.value = true
}

function validate(): boolean {
  const errors: Record<string, string> = {}
  if (form.name.trim() === '') errors['name'] = 'メニュー名を入力してください'
  else if (form.name.trim().length > 100) errors['name'] = 'メニュー名は100文字以内で入力してください'
  if (form.price == null) errors['price'] = '価格を入力してください'
  else if (form.price < 0 || form.price > 9999999) errors['price'] = '価格は0〜9,999,999円で入力してください'
  if (form.duration_minutes == null) errors['duration_minutes'] = '所要時間を入力してください'
  else if (form.duration_minutes < 5 || form.duration_minutes > 480) {
    errors['duration_minutes'] = '所要時間は5〜480分で入力してください'
  }
  fieldErrors.value = errors
  return Object.keys(errors).length === 0
}

async function submit(): Promise<void> {
  if (saving.value || !validate()) return
  if (form.price == null || form.duration_minutes == null) return
  saving.value = true
  try {
    const editing = editingMenu.value
    if (editing) {
      const input: MenuUpdateInput = {
        name: form.name.trim(),
        price: form.price,
        duration_minutes: form.duration_minutes,
        is_active: form.is_active,
      }
      await menuService.update(editing.id, input)
      toast.add({ severity: 'success', summary: 'メニューを更新しました', life: 3000 })
    } else {
      const input: MenuCreateInput = {
        name: form.name.trim(),
        price: form.price,
        duration_minutes: form.duration_minutes,
        is_active: form.is_active,
      }
      await menuService.create(input)
      toast.add({ severity: 'success', summary: 'メニューを登録しました', life: 3000 })
    }
    dialogVisible.value = false
    await fetchMenus()
  } catch (error) {
    const errors = extractFieldErrors(error)
    if (Object.keys(errors).length > 0) {
      fieldErrors.value = errors
      toast.add({ severity: 'error', summary: '入力内容をご確認ください', life: 3000 })
    } else {
      toast.add({
        severity: 'error',
        summary: extractErrorMessage(error, 'メニューの保存に失敗しました'),
        life: 3000,
      })
    }
  } finally {
    saving.value = false
  }
}

// ---- 並び替え ----

async function move(index: number, delta: -1 | 1): Promise<void> {
  if (reordering.value) return
  const current = menus.value[index]
  const target = menus.value[index + delta]
  if (!current || !target) return
  reordering.value = true
  try {
    if (current.display_order === target.display_order) {
      await renumberWithMove(index, delta)
    } else {
      await swapOrders(current, target)
    }
    await fetchMenus()
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '並び順の変更に失敗しました'),
      life: 3000,
    })
    await fetchMenus()
  } finally {
    reordering.value = false
  }
}

async function swapOrders(current: Menu, target: Menu): Promise<void> {
  await menuService.update(current.id, { display_order: target.display_order })
  try {
    await menuService.update(target.id, { display_order: current.display_order })
  } catch (error) {
    // 2件目が失敗すると display_order が重複したままになるため、1件目を戻す
    await menuService.update(current.id, { display_order: current.display_order }).catch(() => {})
    throw error
  }
}

// display_order が重複していると値の入れ替えが no-op になるため、
// 移動後の表示順どおりに全体を振り直して重複を解消する
async function renumberWithMove(index: number, delta: -1 | 1): Promise<void> {
  const desired = [...menus.value]
  const [moved] = desired.splice(index, 1)
  if (!moved) return
  desired.splice(index + delta, 0, moved)
  for (const [order, menu] of desired.entries()) {
    if (menu.display_order !== order) {
      await menuService.update(menu.id, { display_order: order })
    }
  }
}

// ---- 削除 ----

function confirmRemove(menu: Menu): void {
  confirm.require({
    message: `「${menu.name}」を削除しますか？削除しても過去の予約履歴には残ります。`,
    header: 'メニューの削除',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '削除する',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      try {
        await menuService.remove(menu.id)
        toast.add({ severity: 'success', summary: 'メニューを削除しました', life: 3000 })
        await fetchMenus()
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: extractErrorMessage(error, 'メニューの削除に失敗しました'),
          life: 3000,
        })
      }
    },
  })
}

function rowClass(menu: Menu): string {
  return menu.is_active ? '' : 'menu-inactive'
}
</script>

<template>
  <div class="rb-page">
    <PageHeader
      title="メニュー管理"
      icon="pi pi-list"
      subtitle="施術メニューの登録・並び順・有効/無効を管理できます"
    >
      <template #actions>
        <Button label="新規登録" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <GlassCard>
      <div v-if="!initialized" class="skeleton-list">
        <Skeleton v-for="n in 4" :key="n" height="54px" border-radius="14px" />
      </div>

      <DataTable
        v-else-if="menus.length > 0"
        :value="menus"
        :loading="loading"
        :row-class="rowClass"
        table-style="min-width: 640px"
        class="menu-table"
      >
        <Column header="並び順" style="width: 110px">
          <template #body="{ index }">
            <div class="order-cell">
              <Button
                icon="pi pi-arrow-up"
                text
                rounded
                severity="secondary"
                :disabled="index === 0 || reordering"
                aria-label="上へ移動"
                @click="move(index, -1)"
              />
              <Button
                icon="pi pi-arrow-down"
                text
                rounded
                severity="secondary"
                :disabled="index === menus.length - 1 || reordering"
                aria-label="下へ移動"
                @click="move(index, 1)"
              />
            </div>
          </template>
        </Column>

        <Column header="メニュー名">
          <template #body="{ data }">
            <span class="menu-name">{{ data.name }}</span>
          </template>
        </Column>

        <Column header="価格" style="width: 16%">
          <template #body="{ data }">¥{{ formatNumber(data.price) }}</template>
        </Column>

        <Column header="所要時間" style="width: 14%">
          <template #body="{ data }">{{ data.duration_minutes }}分</template>
        </Column>

        <Column header="有効" style="width: 12%">
          <template #body="{ data }">
            <Tag
              :value="data.is_active ? '有効' : '無効'"
              :severity="data.is_active ? undefined : 'secondary'"
              rounded
            />
          </template>
        </Column>

        <Column header="操作" style="width: 110px">
          <template #body="{ data }">
            <div class="action-cell">
              <Button
                icon="pi pi-pencil"
                text
                rounded
                severity="secondary"
                aria-label="編集"
                @click="openEdit(data)"
              />
              <Button
                icon="pi pi-trash"
                text
                rounded
                severity="danger"
                aria-label="削除"
                @click="confirmRemove(data)"
              />
            </div>
          </template>
        </Column>
      </DataTable>

      <EmptyState
        v-else
        icon="pi pi-list"
        title="メニューはまだありません"
        description="最初のメニューを登録してみましょう"
      >
        <template #action>
          <Button label="新規登録" icon="pi pi-plus" @click="openCreate" />
        </template>
      </EmptyState>
    </GlassCard>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="isEdit ? 'メニューの編集' : 'メニューの登録'"
      :style="{ width: '440px', maxWidth: '94vw' }"
      :closable="!saving"
      :draggable="false"
    >
      <div class="dialog-form">
        <div class="field">
          <label class="field-label" for="menu-name">
            <i class="pi pi-bookmark" />
            メニュー名
            <span class="required-badge">必須</span>
          </label>
          <InputText
            id="menu-name"
            v-model="form.name"
            fluid
            :maxlength="100"
            placeholder="例）カット"
            :invalid="!!fieldErrors['name']"
            :disabled="saving"
          />
          <small v-if="fieldErrors['name']" class="field-error">
            <i class="pi pi-exclamation-circle" /> {{ fieldErrors['name'] }}
          </small>
        </div>

        <div class="field">
          <label class="field-label" for="menu-price">
            <i class="pi pi-money-bill" />
            価格（税込・円）
            <span class="required-badge">必須</span>
          </label>
          <InputNumber
            v-model="form.price"
            input-id="menu-price"
            :min="0"
            :max="9999999"
            :use-grouping="true"
            fluid
            placeholder="例）5,500"
            :invalid="!!fieldErrors['price']"
            :disabled="saving"
          />
          <small v-if="fieldErrors['price']" class="field-error">
            <i class="pi pi-exclamation-circle" /> {{ fieldErrors['price'] }}
          </small>
        </div>

        <div class="field">
          <label class="field-label" for="menu-duration">
            <i class="pi pi-clock" />
            所要時間（分）
            <span class="required-badge">必須</span>
          </label>
          <InputNumber
            v-model="form.duration_minutes"
            input-id="menu-duration"
            :min="5"
            :max="480"
            :step="5"
            fluid
            placeholder="例）60"
            :invalid="!!fieldErrors['duration_minutes']"
            :disabled="saving"
          />
          <small v-if="fieldErrors['duration_minutes']" class="field-error">
            <i class="pi pi-exclamation-circle" /> {{ fieldErrors['duration_minutes'] }}
          </small>
        </div>

        <div class="field">
          <label class="field-label" for="menu-active">
            <i class="pi pi-check-circle" />
            有効
          </label>
          <div class="toggle-row">
            <ToggleSwitch v-model="form.is_active" input-id="menu-active" :disabled="saving" />
            <span class="toggle-note">{{
              form.is_active ? '新規予約で選択できます' : '新規予約の選択肢に表示されません'
            }}</span>
          </div>
          <small v-if="fieldErrors['is_active']" class="field-error">
            <i class="pi pi-exclamation-circle" /> {{ fieldErrors['is_active'] }}
          </small>
        </div>
      </div>

      <template #footer>
        <Button
          label="キャンセル"
          severity="secondary"
          outlined
          :disabled="saving"
          @click="dialogVisible = false"
        />
        <Button
          :label="isEdit ? '保存' : '登録'"
          icon="pi pi-check"
          :loading="saving"
          @click="submit"
        />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.order-cell {
  display: flex;
  align-items: center;
  gap: 0.1rem;
}

.menu-name {
  font-weight: 600;
}

.action-cell {
  display: flex;
  align-items: center;
  gap: 0.15rem;
}

.menu-table :deep(.menu-inactive) {
  opacity: 0.55;
}

.dialog-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.field-label {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--rb-text);
}

.field-label i {
  color: var(--rb-pink);
  font-size: 0.82rem;
}

.required-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.12rem 0.55rem;
  border-radius: 999px;
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
  font-size: 0.68rem;
  font-weight: 700;
  line-height: 1.3;
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

.toggle-row {
  display: flex;
  align-items: center;
  gap: 0.7rem;
}

.toggle-note {
  font-size: 0.8rem;
  color: var(--rb-text-muted);
}
</style>
