<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Skeleton from 'primevue/skeleton'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import EmptyState from '@/components/common/EmptyState.vue'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { googleCalendarService } from '@/services/googleCalendarService'
import { userService } from '@/services/userService'
import { useAuthStore } from '@/stores/auth'
import { extractErrorMessage } from '@/utils/apiError'
import { formatDateTime } from '@/utils/format'
import {
  MODE_DESCRIPTIONS,
  MODE_LABELS,
  calendarLabel,
  connectErrorMessage,
  resolveSelectedCalendarId,
} from '@/utils/googleCalendar'
import type {
  GoogleCalendarConnection,
  GoogleCalendarListEntry,
  GoogleCalendarMode,
  GoogleCalendarSettings,
  StaffUser,
} from '@/types'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()

const settings = ref<GoogleCalendarSettings | null>(null)
const staff = ref<StaffUser[]>([])

const initialized = ref(false)
const loadError = ref(false)
const savingMode = ref(false)
const connectingKey = ref<string | null>(null)
const disconnectingId = ref<number | null>(null)
const guideOpen = ref(true)

/** モード設定・サロン共有接続の操作はオーナー・マネージャーのみ */
const isOwnerOrManager = computed(
  () => auth.user?.role === 'owner' || auth.user?.role === 'manager',
)

async function fetchAll(): Promise<void> {
  loadError.value = false
  try {
    const [nextSettings, staffList] = await Promise.all([
      googleCalendarService.get(),
      userService.list(),
    ])
    staff.value = staffList
    applySettings(nextSettings)
  } catch (error) {
    loadError.value = true
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'Googleカレンダー連携設定の取得に失敗しました'),
      life: 3000,
    })
  } finally {
    initialized.value = true
  }
}

/**
 * 操作 API 成功後の表示更新。ここでの失敗は操作自体の失敗ではないため、
 * 操作の catch とは分けて別文言で通知する（操作失敗として誤報告しない）。
 */
async function reloadSettings(): Promise<void> {
  try {
    applySettings(await googleCalendarService.get())
  } catch {
    toast.add({
      severity: 'warn',
      summary: '表示の更新に失敗しました。再読み込みしてください',
      life: 4000,
    })
  }
}

function applySettings(next: GoogleCalendarSettings): void {
  settings.value = next
  // 接続済みのときは手順ガイドを折りたたむ
  guideOpen.value = next.connections.length === 0
}

onMounted(async () => {
  const connected = route.query.connected
  const errorCode = typeof route.query.error === 'string' ? route.query.error : null

  await fetchAll()

  if (connected === '1') {
    toast.add({
      severity: 'success',
      summary: 'Googleカレンダーと接続しました',
      detail: '初回の同期には数分かかることがあります',
      life: 4000,
    })
  } else if (errorCode !== null) {
    toast.add({ severity: 'error', summary: connectErrorMessage(errorCode), life: 5000 })
  }
  // リロードで Toast が再表示されないようクエリを除去する
  if (connected !== undefined || route.query.error !== undefined) {
    await router.replace({ query: {} })
  }
})

// ---- モード選択 ----

const modeOptions: { value: GoogleCalendarMode; label: string }[] = [
  { value: 'per_staff', label: MODE_LABELS.per_staff },
  { value: 'shared', label: MODE_LABELS.shared },
]

/**
 * SelectButton は内部状態で選択を描画するため、確認前に見た目が切り替わる（楽観的更新）。
 * これを打ち消すには再マウントが必要なため、キーを更新してサーバの現在値へ描き戻す。
 */
const modeSelectKey = ref(0)

function onModeChange(next: GoogleCalendarMode | null): void {
  const current = settings.value
  if (next === null || current === null || next === current.mode) return

  modeSelectKey.value += 1
  if (current.connections.length === 0) {
    void applyMode(next)
    return
  }
  confirm.require({
    header: '接続単位の変更',
    message:
      '接続単位を変更すると、現在のGoogleカレンダー連携はすべて解除されます。解除後、あらためて接続し直してください。' +
      'Google側に作成済みの予約の予定は削除されませんが、RBのGoogleカレンダーへのアクセス許可は取り消されるため、' +
      '接続し直す際はGoogleの同意画面での許可が必要です。よろしいですか？',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '変更する',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: () => {
      void applyMode(next)
    },
  })
}

async function applyMode(next: GoogleCalendarMode): Promise<void> {
  savingMode.value = true
  try {
    applySettings(await googleCalendarService.updateMode(next))
    toast.add({
      severity: 'success',
      summary: `接続単位を「${MODE_LABELS[next]}」に設定しました`,
      life: 3000,
    })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '接続単位の変更に失敗しました'),
      life: 3000,
    })
  } finally {
    savingMode.value = false
  }
}

// ---- 接続カード ----

type RowState = 'disconnected' | 'active' | 'needs_reconnect'

interface ConnectionRow {
  key: string
  title: string
  /** per_staff の行のスタッフ名（要再接続の注記に使う） */
  staffName: string | null
  connection: GoogleCalendarConnection | null
  /** 接続・カレンダー変更・解除を行えるか（APIの所有者条件と同じ） */
  canOperate: boolean
  /** 操作できない行の注記（未接続時のみ表示する） */
  restrictionNote: string
}

const rows = computed<ConnectionRow[]>(() => {
  const current = settings.value
  if (current === null || current.mode === null) return []

  if (current.mode === 'shared') {
    return [
      {
        key: 'shared',
        title: 'サロン共有カレンダー',
        staffName: null,
        connection: current.connections[0] ?? null,
        canOperate: isOwnerOrManager.value,
        restrictionNote: 'オーナー・マネージャーがログインして接続してください',
      },
    ]
  }

  return [...staff.value]
    .sort((a, b) => a.id - b.id)
    .map((member) => ({
      key: `staff-${member.id}`,
      title: member.id === auth.user?.id ? `${member.name}（あなた）` : member.name,
      staffName: member.name,
      connection: current.connections.find((item) => item.user?.id === member.id) ?? null,
      canOperate: member.id === auth.user?.id,
      restrictionNote: '本人がログインして接続してください',
    }))
})

const STATE_META: Record<RowState, { label: string; icon: string; className: string }> = {
  disconnected: { label: '未接続', icon: 'pi pi-minus-circle', className: 'disconnected' },
  active: { label: '接続済み', icon: 'pi pi-check-circle', className: 'active' },
  needs_reconnect: {
    label: '要再接続',
    icon: 'pi pi-exclamation-triangle',
    className: 'needs-reconnect',
  },
}

function rowState(row: ConnectionRow): RowState {
  return row.connection === null ? 'disconnected' : row.connection.status
}

function reconnectMessage(row: ConnectionRow): string {
  if (row.canOperate) {
    return 'Googleとの接続が切れています。予約の書き込みと外部予定の取り込みが停止しています。再接続してください'
  }
  return row.staffName !== null
    ? `${row.staffName}さんの再接続が必要です`
    : 'サロン共有カレンダーの再接続が必要です'
}

// ---- 接続（OAuth） ----

async function connect(row: ConnectionRow): Promise<void> {
  if (connectingKey.value !== null) return
  connectingKey.value = row.key
  try {
    // 別タブは復帰時のパラメータ処理が二重になるため同一タブで遷移する
    window.location.assign(await googleCalendarService.createAuthUrl())
  } catch (error) {
    // 遷移しない。遷移する場合は離脱するのでローディングは戻さない
    connectingKey.value = null
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'Googleとの接続を開始できませんでした'),
      life: 3000,
    })
  }
}

// ---- 対象カレンダーの変更 ----

const calendarDialogVisible = ref(false)
const dialogConnection = ref<GoogleCalendarConnection | null>(null)
const calendars = ref<GoogleCalendarListEntry[]>([])
const calendarsLoading = ref(false)
const calendarsError = ref<string | null>(null)
const selectedCalendarId = ref<string | null>(null)
const savingCalendar = ref(false)

function openCalendarDialog(connection: GoogleCalendarConnection): void {
  dialogConnection.value = connection
  calendars.value = []
  selectedCalendarId.value = null
  calendarDialogVisible.value = true
  void loadCalendars()
}

async function loadCalendars(): Promise<void> {
  const connection = dialogConnection.value
  if (connection === null) return
  calendarsLoading.value = true
  calendarsError.value = null
  try {
    calendars.value = await googleCalendarService.listCalendars(connection.id)
    selectedCalendarId.value = resolveSelectedCalendarId(connection.calendar_id, calendars.value)
  } catch (error) {
    calendars.value = []
    selectedCalendarId.value = null
    calendarsError.value = extractErrorMessage(error, 'カレンダー一覧を取得できませんでした')
  } finally {
    calendarsLoading.value = false
  }
}

const selectedEntry = computed(
  () => calendars.value.find((entry) => entry.id === selectedCalendarId.value) ?? null,
)

/** primary 以外を選ぶと私用予定を読めなくなるため警告を出す */
const dedicatedCalendarSelected = computed(
  () => selectedEntry.value !== null && !selectedEntry.value.primary,
)

const calendarChanged = computed(() => {
  const connection = dialogConnection.value
  if (connection === null || selectedCalendarId.value === null) return false
  return (
    selectedCalendarId.value !== resolveSelectedCalendarId(connection.calendar_id, calendars.value)
  )
})

function confirmCalendarChange(): void {
  confirm.require({
    header: '対象カレンダーの変更',
    message:
      '対象カレンダーを変更すると、これまでに取り込んだ外部予定はいったん破棄され、新しいカレンダーの予定を取り込み直します。' +
      'これまでのカレンダーに作成済みの予約の予定は削除され、新しいカレンダーへ書き直されます。よろしいですか？',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '変更する',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: () => {
      void saveCalendar()
    },
  })
}

async function saveCalendar(): Promise<void> {
  const connection = dialogConnection.value
  const calendarId = selectedCalendarId.value
  if (connection === null || calendarId === null) return
  savingCalendar.value = true
  let ok = false
  try {
    await googleCalendarService.updateConnection(connection.id, { calendar_id: calendarId })
    ok = true
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, '対象カレンダーの変更に失敗しました'),
      life: 3000,
    })
  } finally {
    savingCalendar.value = false
  }
  if (!ok) return

  calendarDialogVisible.value = false
  toast.add({
    severity: 'success',
    summary: '対象カレンダーを変更しました',
    detail: '新しいカレンダーの予定を取り込み直しています',
    life: 4000,
  })
  await reloadSettings()
}

// ---- 連携解除 ----

function confirmDisconnect(connection: GoogleCalendarConnection): void {
  confirm.require({
    header: '接続の解除',
    message:
      '接続を解除すると、このカレンダーへの予約の書き込みと、カレンダー上の予定の取り込みが停止します。' +
      'Google側に作成済みの予約の予定は削除されません（不要な場合はGoogleカレンダーから手動で削除してください）。' +
      'また、取り込み済みの外部予定は削除され、その時間帯もWeb予約で空き枠として表示されるようになります。' +
      'あわせてRBのGoogleカレンダーへのアクセス許可も取り消されます（Googleアカウントの「サードパーティ アクセス」からRBが消えます）。' +
      '再び連携するには、Googleの同意画面からあらためて許可が必要です。よろしいですか？',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '解除する',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      disconnectingId.value = connection.id
      let ok = false
      try {
        await googleCalendarService.deleteConnection(connection.id)
        ok = true
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: extractErrorMessage(error, '接続の解除に失敗しました'),
          life: 3000,
        })
      } finally {
        disconnectingId.value = null
      }
      if (!ok) return

      toast.add({ severity: 'success', summary: '接続を解除しました', life: 3000 })
      await reloadSettings()
    },
  })
}
</script>

<template>
  <div class="rb-page">
    <PageHeader
      title="Googleカレンダー連携"
      icon="pi pi-calendar"
      subtitle="RBの予約をGoogleカレンダーへ書き込み、カレンダー上のRB以外の予定を空き枠に反映します"
    />

    <div class="gc-body">
      <div v-if="!initialized" class="skeleton-list">
        <Skeleton height="150px" border-radius="14px" />
        <Skeleton height="220px" border-radius="14px" />
      </div>

      <GlassCard v-else-if="loadError">
        <div class="load-error">
          <i class="pi pi-exclamation-triangle" />
          <p>Googleカレンダー連携設定を読み込めませんでした</p>
          <Button label="再読み込み" icon="pi pi-refresh" outlined @click="fetchAll" />
        </div>
      </GlassCard>

      <template v-else>
        <GlassCard title="接続単位" icon="pi pi-sitemap">
          <SelectButton
            :key="modeSelectKey"
            :model-value="settings?.mode ?? null"
            :options="modeOptions"
            option-label="label"
            option-value="value"
            :allow-empty="false"
            :disabled="savingMode || !isOwnerOrManager"
            aria-label="接続単位"
            @update:model-value="onModeChange"
          />

          <p v-if="settings?.mode" class="mode-description">
            {{ MODE_DESCRIPTIONS[settings.mode] }}
          </p>
          <p v-else class="mode-unset">
            <i class="pi pi-info-circle" />
            接続単位を選んでください
          </p>

          <p v-if="!isOwnerOrManager" class="mode-restriction">
            接続単位の変更はオーナー・マネージャーのみ行えます
          </p>
        </GlassCard>

        <GlassCard title="接続状態" icon="pi pi-link">
          <EmptyState
            v-if="settings?.mode == null"
            icon="pi pi-sitemap"
            title="接続単位が未設定です"
            description="接続単位を選ぶと接続できます"
          />
          <EmptyState
            v-else-if="rows.length === 0"
            icon="pi pi-users"
            title="スタッフが登録されていません"
            description="スタッフを登録すると、各スタッフのGoogleカレンダーを接続できます"
          />
          <ul v-else class="connection-list">
            <li v-for="row in rows" :key="row.key" class="connection-row">
              <div class="row-head">
                <span class="row-title">{{ row.title }}</span>
                <span class="state-tag" :class="STATE_META[rowState(row)].className">
                  <i :class="STATE_META[rowState(row)].icon" />
                  {{ STATE_META[rowState(row)].label }}
                </span>
              </div>

              <Message
                v-if="rowState(row) === 'needs_reconnect'"
                severity="warn"
                :closable="false"
                class="row-message"
              >
                {{ reconnectMessage(row) }}
              </Message>

              <dl v-if="row.connection" class="row-meta">
                <div class="meta-item">
                  <dt>Googleアカウント</dt>
                  <dd>{{ row.connection.google_account_email }}</dd>
                </div>
                <template v-if="row.connection.status === 'active'">
                  <div class="meta-item">
                    <dt>対象カレンダー</dt>
                    <dd>{{ calendarLabel(row.connection) }}</dd>
                  </div>
                  <div class="meta-item">
                    <dt>最終同期</dt>
                    <dd>
                      {{
                        row.connection.last_synced_at
                          ? formatDateTime(row.connection.last_synced_at)
                          : '同期待ち'
                      }}
                    </dd>
                  </div>
                </template>
              </dl>

              <p v-if="!row.canOperate && !row.connection" class="row-note">
                {{ row.restrictionNote }}
              </p>

              <div v-if="row.canOperate" class="row-actions">
                <Button
                  v-if="rowState(row) !== 'active'"
                  label="Googleと接続"
                  icon="pi pi-google"
                  :loading="connectingKey === row.key"
                  :disabled="connectingKey !== null"
                  @click="connect(row)"
                />
                <Button
                  v-if="rowState(row) === 'active' && row.connection"
                  label="カレンダーを変更"
                  icon="pi pi-calendar-plus"
                  severity="secondary"
                  outlined
                  @click="openCalendarDialog(row.connection)"
                />
                <Button
                  v-if="row.connection"
                  label="接続を解除"
                  icon="pi pi-times"
                  severity="danger"
                  outlined
                  :loading="disconnectingId === row.connection.id"
                  @click="confirmDisconnect(row.connection)"
                />
              </div>
            </li>
          </ul>

          <p v-if="settings?.connections.length" class="calendar-name-note">
            <i class="pi pi-info-circle" />
            メインカレンダー以外を選んだ場合はカレンダーIDがそのまま表示されます。カレンダー名は「カレンダーを変更」から確認できます
          </p>
        </GlassCard>

        <GlassCard title="設定手順ガイド" icon="pi pi-list-check">
          <template #actions>
            <Button
              :label="guideOpen ? '隠す' : '表示する'"
              :icon="guideOpen ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
              severity="secondary"
              text
              @click="guideOpen = !guideOpen"
            />
          </template>
          <ol v-if="guideOpen" class="guide-list">
            <li>接続単位（スタッフ別／サロン共有）を選ぶ</li>
            <li>「Googleと接続」からGoogleの同意画面へ進み、カレンダーへのアクセスを許可する</li>
            <li>
              同意画面で「このアプリはGoogleで確認されていません」という警告が表示される場合がある。Googleの審査完了前は表示される想定のため、「詳細」→「（アプリ名）に移動」から続行する
            </li>
            <li>
              接続後、対象カレンダーがメインカレンダーになっていることを確認する
              <span class="guide-supplement">
                私用予定を空き枠に反映するため、メインカレンダーを推奨します。メインカレンダー以外を選んだ場合はカレンダーIDがそのまま表示され、カレンダー名は「カレンダーを変更」から確認できます
              </span>
            </li>
            <li>
              接続すると、以降のRBの予約がGoogleカレンダーに自動で追加され、予約の時間変更・キャンセルも反映される
            </li>
            <li>
              Googleカレンダー側でRB以外の予定を入れると、その時間はRBの空き枠から外れ、予約カレンダーに「外部予定」として時刻のみ表示される
              <span class="guide-supplement">
                RBに保存されるのは外部予定の開始・終了時刻のみで、予定のタイトルや参加者は保存しません
              </span>
            </li>
            <li>
              Googleカレンダー側でRBの予約の予定を移動すると、空いていればRBの予約も移動する。移動先が埋まっている場合はRBの予約時間が優先され、Google側が元の時間に戻る
            </li>
          </ol>
        </GlassCard>
      </template>
    </div>

    <Dialog
      v-model:visible="calendarDialogVisible"
      modal
      header="対象カレンダー"
      :style="{ width: '480px', maxWidth: '94vw' }"
      :closable="!savingCalendar"
      :draggable="false"
    >
      <div v-if="calendarsLoading" class="dialog-skeleton">
        <Skeleton height="2.6rem" border-radius="8px" />
        <Skeleton height="1rem" width="70%" border-radius="8px" />
      </div>

      <div v-else-if="calendarsError" class="dialog-error">
        <Message severity="error" :closable="false">{{ calendarsError }}</Message>
        <Button label="再試行" icon="pi pi-refresh" outlined @click="loadCalendars" />
      </div>

      <div v-else class="dialog-body">
        <Select
          v-model="selectedCalendarId"
          :options="calendars"
          option-label="summary"
          option-value="id"
          placeholder="カレンダーを選択"
          fluid
          :disabled="savingCalendar"
        />

        <Message v-if="dedicatedCalendarSelected" severity="warn" :closable="false">
          専用カレンダーを選ぶと、そのカレンダーに入っている予定しか読み取れません。メインカレンダーの私用予定は空き枠に反映されず、私用の予定がある時間にもWeb予約が入る可能性があります。私用予定も空き枠に反映したい場合はメインカレンダーを選んでください。
        </Message>
      </div>

      <template #footer>
        <Button
          label="キャンセル"
          severity="secondary"
          outlined
          :disabled="savingCalendar"
          @click="calendarDialogVisible = false"
        />
        <Button
          label="保存"
          icon="pi pi-check"
          :loading="savingCalendar"
          :disabled="!calendarChanged"
          @click="confirmCalendarChange"
        />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.gc-body {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  width: 100%;
  max-width: 720px;
}

.skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.load-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.6rem;
  padding: 2.4rem 1rem;
  color: var(--rb-text-muted);
}

.load-error i {
  font-size: 1.6rem;
  color: var(--rb-pink);
}

.load-error p {
  margin: 0;
  font-weight: 600;
  color: var(--rb-text);
}

/* ---------- 接続単位 ---------- */

.mode-description {
  margin: 1rem 0 0;
  font-size: 0.85rem;
  line-height: 1.7;
  color: var(--rb-text-muted);
}

.mode-unset {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 1rem 0 0;
  padding: 0.8rem 1rem;
  border-radius: var(--rb-radius-md);
  background: var(--rb-beige-soft);
  border: 1px solid var(--rb-beige);
  font-size: 0.85rem;
  color: var(--rb-text);
}

.mode-unset i {
  color: var(--rb-beige-deep);
}

.mode-restriction {
  margin: 0.6rem 0 0;
  font-size: 0.78rem;
  color: var(--rb-text-muted);
}

/* ---------- 接続状態 ---------- */

.connection-list {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.connection-row {
  padding: 0.95rem 1.1rem;
  border-radius: var(--rb-radius-md);
  border: 1px solid var(--rb-border);
  background: var(--rb-surface-subtle);
}

.row-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.8rem;
  flex-wrap: wrap;
}

.row-title {
  font-weight: 700;
  font-size: 0.95rem;
  color: var(--rb-text);
}

.state-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.28rem 0.8rem;
  border-radius: 999px;
  font-size: 0.76rem;
  font-weight: 700;
  flex-shrink: 0;
}

.state-tag i {
  font-size: 0.76rem;
}

.state-tag.active {
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
}

.state-tag.needs-reconnect {
  background: var(--rb-beige-soft);
  color: var(--rb-accent-deep);
}

.state-tag.disconnected {
  background: rgba(111, 106, 125, 0.12);
  color: var(--rb-text-muted);
}

.row-message {
  margin-top: 0.7rem;
}

.row-meta {
  display: flex;
  gap: 1.6rem;
  flex-wrap: wrap;
  margin: 0.8rem 0 0;
}

.meta-item {
  min-width: 0;
}

.meta-item dt {
  font-size: 0.72rem;
  color: var(--rb-text-muted);
}

.meta-item dd {
  margin: 0.15rem 0 0;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--rb-text);
  word-break: break-all;
}

.row-note {
  margin: 0.7rem 0 0;
  font-size: 0.8rem;
  color: var(--rb-text-muted);
}

.row-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-top: 0.9rem;
}

.calendar-name-note {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin: 1rem 0 0;
  font-size: 0.78rem;
  color: var(--rb-text-muted);
}

.calendar-name-note i {
  margin-top: 0.1rem;
}

/* ---------- 設定手順ガイド ---------- */

.guide-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0;
  padding-left: 1.2rem;
  font-size: 0.88rem;
  color: var(--rb-text);
}

.guide-supplement {
  display: block;
  margin-top: 0.25rem;
  font-size: 0.78rem;
  color: var(--rb-text-muted);
}

/* ---------- カレンダー変更ダイアログ ---------- */

.dialog-skeleton,
.dialog-error,
.dialog-body {
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
}

.dialog-error {
  align-items: flex-start;
}

@media (max-width: 520px) {
  .row-actions {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
