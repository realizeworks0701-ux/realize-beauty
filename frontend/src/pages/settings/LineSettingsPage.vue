<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { AxiosError } from 'axios'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Skeleton from 'primevue/skeleton'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import GlassCard from '@/components/common/GlassCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { lineSettingsService } from '@/services/lineSettingsService'
import { extractErrorMessage, extractFieldErrors } from '@/utils/apiError'
import { formatDateTime } from '@/utils/format'
import { buildBookingPageUrl } from '@/utils/publicBooking'
import type { BookingPage, LineSetting } from '@/types'

const toast = useToast()
const confirm = useConfirm()

const setting = ref<LineSetting | null>(null)
const bookingPage = ref<BookingPage | null>(null)

const initialized = ref(false)
const loadError = ref(false)
const saving = ref(false)
const verifying = ref(false)
const disconnecting = ref(false)
const guideOpen = ref(true)

const form = reactive({ channel_id: '', channel_secret: '', channel_access_token: '' })
const fieldErrors = ref<Record<string, string>>({})

async function fetchAll(): Promise<void> {
  loadError.value = false
  try {
    const [lineSetting, page] = await Promise.all([
      lineSettingsService.get(),
      lineSettingsService.getBookingPage(),
    ])
    applySetting(lineSetting)
    bookingPage.value = page
    // 接続済みのときは手順ガイドを折りたたむ
    guideOpen.value = !lineSetting.is_active
  } catch (error) {
    loadError.value = true
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'LINE連携設定の取得に失敗しました'),
      life: 3000,
    })
  } finally {
    initialized.value = true
  }
}

/** secret / token はマスク値しか取得できないため、入力欄は常に空へ戻す */
function applySetting(next: LineSetting): void {
  setting.value = next
  form.channel_id = next.channel_id ?? ''
  form.channel_secret = ''
  form.channel_access_token = ''
  fieldErrors.value = {}
}

onMounted(() => {
  void fetchAll()
})

// ---- 接続状態 ----

type ConnectionState = 'unconfigured' | 'unverified' | 'connected'

const connectionState = computed<ConnectionState>(() => {
  if (!setting.value?.configured) return 'unconfigured'
  return setting.value.is_active ? 'connected' : 'unverified'
})

const STATE_META: Record<ConnectionState, { label: string; icon: string; description: string }> = {
  unconfigured: {
    label: '未設定',
    icon: 'pi pi-minus-circle',
    description: 'LINE連携は未設定です。下記の設定手順に沿って認証情報を登録してください',
  },
  unverified: {
    label: '接続確認待ち',
    icon: 'pi pi-exclamation-triangle',
    description: '認証情報は保存済みです。接続確認を行ってください',
  },
  connected: {
    label: '接続済み',
    icon: 'pi pi-check-circle',
    description: 'LINEでの予約確定通知・前日リマインダーが有効です',
  },
}

const stateMeta = computed(() => STATE_META[connectionState.value])

// ---- 認証情報の保存 ----

function validate(): boolean {
  const errors: Record<string, string> = {}
  if (!form.channel_id.trim()) errors.channel_id = 'Channel ID を入力してください'
  if (!form.channel_secret.trim()) errors.channel_secret = 'Channel Secret を入力してください'
  if (!form.channel_access_token.trim())
    errors.channel_access_token = 'チャネルアクセストークンを入力してください'
  fieldErrors.value = errors
  return Object.keys(errors).length === 0
}

async function save(): Promise<void> {
  if (saving.value || !validate()) return
  saving.value = true
  try {
    applySetting(
      await lineSettingsService.update({
        channel_id: form.channel_id.trim(),
        channel_secret: form.channel_secret.trim(),
        channel_access_token: form.channel_access_token.trim(),
      }),
    )
    guideOpen.value = true
    toast.add({
      severity: 'success',
      summary: '認証情報を保存しました',
      detail: '続けて「接続確認」を行ってください',
      life: 4000,
    })
  } catch (error) {
    const errors = extractFieldErrors(error)
    if (Object.keys(errors).length > 0) {
      fieldErrors.value = errors
      toast.add({ severity: 'error', summary: '入力内容をご確認ください', life: 3000 })
    } else {
      toast.add({
        severity: 'error',
        summary: extractErrorMessage(error, '認証情報の保存に失敗しました'),
        life: 3000,
      })
    }
  } finally {
    saving.value = false
  }
}

// ---- 接続確認 ----

async function verify(): Promise<void> {
  if (verifying.value) return
  verifying.value = true
  try {
    const verified = await lineSettingsService.verify()
    applySetting(verified)
    guideOpen.value = false
    toast.add({
      severity: 'success',
      summary: '接続を確認しました',
      detail: verified.bot_display_name ?? undefined,
      life: 4000,
    })
  } catch (error) {
    // 接続確認の失敗は接続状態を変更しない（Toast のみ）
    const failed = error instanceof AxiosError && error.response?.status === 422
    toast.add({
      severity: 'error',
      summary: failed ? '接続に失敗しました' : extractErrorMessage(error, '接続確認に失敗しました'),
      detail: failed ? 'Channel ID・Secret・アクセストークンを確認してください' : undefined,
      life: 5000,
    })
  } finally {
    verifying.value = false
  }
}

// ---- URL コピー ----

/** SPA と API は別オリジンのため、booking_page_url（APP_URL 起点）ではなく booking_slug から組み立てる */
const bookingPageUrl = computed(() =>
  bookingPage.value
    ? buildBookingPageUrl(window.location.origin, bookingPage.value.booking_slug)
    : '',
)

async function copy(value: string, label: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(value)
    toast.add({ severity: 'success', summary: `${label}をコピーしました`, life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: 'コピーに失敗しました', life: 3000 })
  }
}

// ---- 連携解除 ----

function confirmDisconnect(): void {
  confirm.require({
    header: 'LINE連携の解除',
    message:
      '連携を解除すると保存済みの認証情報は削除され、LINEでの予約確定通知・前日リマインダーが停止します。' +
      'また、すべてのお客様のLINE連携（連携済みアカウント・発行済み連携コード）も解除されます。よろしいですか？',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: '解除する',
    rejectLabel: 'キャンセル',
    acceptProps: { severity: 'danger' },
    rejectProps: { severity: 'secondary', outlined: true },
    accept: async () => {
      disconnecting.value = true
      try {
        await lineSettingsService.disconnect()
        applySetting(await lineSettingsService.get())
        guideOpen.value = true
        toast.add({ severity: 'success', summary: 'LINE連携を解除しました', life: 3000 })
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: extractErrorMessage(error, 'LINE連携の解除に失敗しました'),
          life: 3000,
        })
      } finally {
        disconnecting.value = false
      }
    },
  })
}
</script>

<template>
  <div class="rb-page">
    <PageHeader
      title="LINE連携"
      icon="pi pi-comments"
      subtitle="LINE公式アカウントとの連携とWeb予約ページURLを確認できます"
    />

    <div class="line-body">
      <div v-if="!initialized" class="skeleton-list">
        <Skeleton height="110px" border-radius="14px" />
        <Skeleton height="260px" border-radius="14px" />
      </div>

      <GlassCard v-else-if="loadError">
        <div class="load-error">
          <i class="pi pi-exclamation-triangle" />
          <p>LINE連携設定を読み込めませんでした</p>
          <Button label="再読み込み" icon="pi pi-refresh" outlined @click="fetchAll" />
        </div>
      </GlassCard>

      <template v-else>
        <GlassCard title="接続状態" icon="pi pi-link">
          <div class="status-head">
            <span class="state-tag" :class="connectionState">
              <i :class="stateMeta.icon" />
              {{ stateMeta.label }}
            </span>
            <span v-if="setting?.bot_display_name" class="bot-name">
              {{ setting.bot_display_name }}
            </span>
            <span v-if="setting?.bot_basic_id" class="bot-basic-id">
              {{ setting.bot_basic_id }}
            </span>
          </div>

          <p class="state-description">{{ stateMeta.description }}</p>

          <dl v-if="connectionState === 'connected'" class="status-meta">
            <div class="meta-item">
              <dt>接続日時</dt>
              <dd>{{ formatDateTime(setting?.connected_at) }}</dd>
            </div>
            <div class="meta-item">
              <dt>最終Webhook受信</dt>
              <dd>
                {{ setting?.last_webhook_at ? formatDateTime(setting.last_webhook_at) : '未受信' }}
              </dd>
            </div>
          </dl>
        </GlassCard>

        <GlassCard title="認証情報" icon="pi pi-key">
          <form class="credential-form" novalidate @submit.prevent="save">
            <div class="field">
              <label class="field-label" for="line-channel-id">Channel ID</label>
              <InputText
                id="line-channel-id"
                v-model="form.channel_id"
                autocomplete="off"
                placeholder="1234567890"
                maxlength="100"
                fluid
                :disabled="saving"
                :invalid="!!fieldErrors.channel_id"
              />
              <small v-if="fieldErrors.channel_id" class="field-error">
                <i class="pi pi-exclamation-circle" />
                {{ fieldErrors.channel_id }}
              </small>
            </div>

            <div class="field">
              <label class="field-label" for="line-channel-secret">Channel Secret</label>
              <Password
                id="line-channel-secret"
                v-model="form.channel_secret"
                :feedback="false"
                toggle-mask
                autocomplete="off"
                fluid
                :disabled="saving"
                :invalid="!!fieldErrors.channel_secret"
                :input-props="{ maxlength: 100 }"
              />
              <small v-if="setting?.channel_secret" class="field-hint">
                保存済み: {{ setting.channel_secret }}
              </small>
              <small v-if="fieldErrors.channel_secret" class="field-error">
                <i class="pi pi-exclamation-circle" />
                {{ fieldErrors.channel_secret }}
              </small>
            </div>

            <div class="field">
              <label class="field-label" for="line-access-token">
                チャネルアクセストークン（長期）
              </label>
              <Password
                id="line-access-token"
                v-model="form.channel_access_token"
                :feedback="false"
                toggle-mask
                autocomplete="off"
                fluid
                :disabled="saving"
                :invalid="!!fieldErrors.channel_access_token"
                :input-props="{ maxlength: 500 }"
              />
              <small v-if="setting?.channel_access_token" class="field-hint">
                保存済み: {{ setting.channel_access_token }}
              </small>
              <small v-if="fieldErrors.channel_access_token" class="field-error">
                <i class="pi pi-exclamation-circle" />
                {{ fieldErrors.channel_access_token }}
              </small>
            </div>

            <p class="form-note">
              <i class="pi pi-info-circle" />
              保存だけでは連携は有効になりません。保存後に「接続確認」を行ってください。保存済みの認証情報を変更して保存すると、接続状態は「接続確認待ち」に戻ります
            </p>

            <div class="form-actions">
              <Button type="submit" label="保存" icon="pi pi-check" :loading="saving" />
              <Button
                type="button"
                label="接続確認"
                icon="pi pi-sync"
                severity="secondary"
                outlined
                :loading="verifying"
                :disabled="!setting?.configured || saving"
                @click="verify"
              />
            </div>
          </form>
        </GlassCard>

        <GlassCard title="Webhook URL" icon="pi pi-send">
          <div class="url-row">
            <code class="url-value">{{ setting?.webhook_url }}</code>
            <Button
              icon="pi pi-copy"
              severity="secondary"
              outlined
              aria-label="Webhook URL をコピー"
              @click="copy(setting?.webhook_url ?? '', 'Webhook URL')"
            />
          </div>
          <p class="url-note">LINE Developers の Messaging API 設定に登録してください</p>
        </GlassCard>

        <GlassCard title="Web予約ページURL" icon="pi pi-globe">
          <div class="url-row">
            <code class="url-value">{{ bookingPageUrl }}</code>
            <Button
              icon="pi pi-copy"
              severity="secondary"
              outlined
              aria-label="Web予約ページURL をコピー"
              @click="copy(bookingPageUrl, 'Web予約ページURL')"
            />
          </div>
          <p class="url-note">リッチメニュー・Instagram・Googleマップ等に掲載してください</p>
        </GlassCard>

        <GlassCard title="設定手順" icon="pi pi-list-check">
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
            <li>
              LINE Official Account Manager でLINE公式アカウントを作成する（既存アカウントでも可）
            </li>
            <li>
              LINE Official Account Manager の「設定 → Messaging API」から Messaging API
              を有効化する
            </li>
            <li>
              LINE Developers コンソールで Channel ID・Channel Secret
              を確認し、チャネルアクセストークン（長期）を発行する
            </li>
            <li>上記3項目を本ページに貼り付けて「保存」→「接続確認」を行う</li>
            <li>
              本ページの Webhook URL を LINE Developers の Messaging API 設定に登録し、Webhook
              の利用をONにする
              <span class="guide-supplement">
                あわせて応答メッセージ（自動応答）と、あいさつメッセージ（友だち追加時）もOFFを推奨します。本システムが友だち追加時に連携コード案内を自動返信するため、ONのままだとメッセージが二重に届きます
              </span>
            </li>
            <li>
              設定完了後、LINE公式アカウントのトークへテストメッセージを送信し、本ページの「最終Webhook受信」が更新されることを確認する
              <span class="guide-supplement">
                接続確認で検証できるのはアクセストークンのみで、Channel Secret の正しさは実際の
                webhook 受信でしか確認できません
              </span>
            </li>
          </ol>
        </GlassCard>

        <GlassCard v-if="setting?.configured" title="連携解除" icon="pi pi-times-circle">
          <div class="disconnect-row">
            <p class="disconnect-note">
              保存済みの認証情報とお客様のLINE連携をすべて削除します。LINEでの通知は停止します
            </p>
            <Button
              label="連携を解除"
              icon="pi pi-times"
              severity="danger"
              outlined
              :loading="disconnecting"
              class="disconnect-button"
              @click="confirmDisconnect"
            />
          </div>
        </GlassCard>
      </template>
    </div>
  </div>
</template>

<style scoped>
.line-body {
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

/* ---------- 接続状態 ---------- */

.status-head {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  flex-wrap: wrap;
}

.state-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.3rem 0.85rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 700;
}

.state-tag i {
  font-size: 0.78rem;
}

.state-tag.connected {
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
}

.state-tag.unverified {
  background: var(--rb-beige-soft);
  color: var(--rb-accent-deep);
}

.state-tag.unconfigured {
  background: rgba(111, 106, 125, 0.12);
  color: var(--rb-text-muted);
}

.bot-name {
  font-family: var(--rb-font-display);
  font-weight: 700;
  color: var(--rb-text);
}

.bot-basic-id {
  font-size: 0.85rem;
  color: var(--rb-text-muted);
}

.state-description {
  margin: 0.7rem 0 0;
  font-size: 0.88rem;
  color: var(--rb-text-muted);
}

.status-meta {
  display: flex;
  gap: 2rem;
  flex-wrap: wrap;
  margin: 1rem 0 0;
  padding-top: 0.9rem;
  border-top: 1px solid var(--rb-border);
}

.meta-item dt {
  font-size: 0.76rem;
  color: var(--rb-text-muted);
}

.meta-item dd {
  margin: 0.15rem 0 0;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--rb-text);
}

/* ---------- 認証情報 ---------- */

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-bottom: 1rem;
}

.field-label {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--rb-text);
}

.field-hint {
  font-size: 0.76rem;
  color: var(--rb-text-muted);
}

.field-error {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.76rem;
  color: var(--rb-danger);
}

.form-note {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin: 0;
  padding: 0.8rem 1rem;
  border-radius: var(--rb-radius-md);
  background: var(--rb-beige-soft);
  border: 1px solid var(--rb-beige);
  font-size: 0.82rem;
  color: var(--rb-text);
}

.form-note i {
  margin-top: 0.1rem;
  color: var(--rb-beige-deep);
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
  margin-top: 1.2rem;
  padding-top: 1.1rem;
  border-top: 1px solid var(--rb-border);
}

/* ---------- URL ---------- */

.url-row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.url-value {
  flex: 1;
  min-width: 0;
  padding: 0.65rem 0.85rem;
  border-radius: var(--rb-radius-sm);
  border: 1px solid var(--rb-border);
  background: var(--rb-surface-subtle);
  font-size: 0.82rem;
  color: var(--rb-text);
  overflow-x: auto;
  white-space: nowrap;
}

.url-note {
  margin: 0.6rem 0 0;
  font-size: 0.8rem;
  color: var(--rb-text-muted);
}

/* ---------- 設定手順 ---------- */

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

/* ---------- 連携解除 ---------- */

.disconnect-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.disconnect-note {
  margin: 0;
  font-size: 0.88rem;
  color: var(--rb-text-muted);
  flex: 1 1 260px;
}

.disconnect-button {
  flex-shrink: 0;
}

@media (max-width: 520px) {
  .form-actions,
  .disconnect-row {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
