# 管理画面パープルテーマ刷新 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 管理画面の配色と質感を「ラベンダー/パープル基調 + 不透明の白カード + フル高グラデーションサイドバー」に刷新し、公開Web予約ページは現行のピンク/グラスデザインのまま維持する。

**Architecture:** `main.css` の `:root` に意味的な新トークン(`--rb-primary-*`, `--rb-surface*`, `--rb-success`, `--rb-danger`, `--rb-accent-*`)を定義し、既存128箇所が参照する旧トークン名(`--rb-pink-*`, `--rb-beige-*`)は新トークンへのエイリアスとして残すことで、大半のページを無改修で追従させる。公開ページは `documentElement` に付与する `rb-legacy-theme` クラスの配下で旧トークン値・旧グラスカード・旧背景を再定義して隔離する。

**Tech Stack:** Vue 3 + TypeScript / PrimeVue 4 (Aura preset) / chart.js / vitest / oxfmt + oxlint + eslint

**Spec:** [docs/superpowers/specs/2026-08-30-purple-theme-design.md](../specs/2026-08-30-purple-theme-design.md)

## Global Constraints

- 作業ブランチは `feature/purple-theme`。バックエンド(`backend/`)は一切変更しない
- Documentation Driven Development: Task 1 で設計書を先に更新してから実装する
- **トークン名を改名しない**(`--rb-pink-*` / `--rb-beige-*` / `--rb-gradient-rose|peach|mauve|cream` は値のみ変更 or エイリアス化)。`--rb-gradient-*` の4名は `KpiCard.vue` の `variant` 型・`PopularMenuList.vue` の `VARIANTS`・`CustomerListPage.vue` の `AVATAR_VARIANTS` と文字列連結で結合しているため
- **既存 spec の契約を壊さない**: `.kpi-delta` / `is-up` / `is-down` / `.status-tag` / `.completed` / `.draft` のクラス名、および `{{ delta }}{{ deltaSuffix }}` と `{{ menu.count }}件` の補間を要素や改行で分断しない(`wrapper.text()` が空白入りになりテストが落ちる)
- `KpiCard.vue` に PrimeVue コンポーネントや `RouterLink` を新規導入しない(spec が plugins/stubs 無しでマウントするため)。ダッシュボード配下に `/reservations` 以外の `RouterLink` 先を増やさない
- Tailwind 等のユーティリティCSSは使わない。色は必ず `var(--rb-*)` 経由。`any` 禁止
- `BookingPage.vue:958` の `#06c755` は LINE ブランドカラーのため変更しない
- 白背景に載るテキストはコントラスト比 4.5:1 以上を確保する
- コミットは日本語の `feat:`/`docs:`/`fix:` プレフィックス + `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`
- コミット前に `cd frontend && npm run format`(oxfmt)を実行。無関係な未コミット変更(`backend/.env.example`, `backend/config/cors.php`, `docs/decisions/ADR-022-deployment.md`, `docs/deployment.md`, `frontend/.gitignore`, `memories/repo/adr-summary.md`, 未追跡の `backend/tests/Feature/CorsConfigTest.php`)には触れず、変更ファイルを名前指定でステージする

## 新パレット(全タスク共通の正典)

| トークン | 値 |
|---|---|
| `--rb-primary` | `#7c5cbf` |
| `--rb-primary-strong` | `#6d4fa8` |
| `--rb-primary-deep` | `#59408c` |
| `--rb-primary-soft` | `#c9b8ec` |
| `--rb-primary-tint` | `#ece5fa` |
| `--rb-primary-faint` | `#f5f1fd` |
| `--rb-accent` | `#c9a227` |
| `--rb-accent-soft` | `#f6efdc` |
| `--rb-accent-deep` | `#8a6d1f` |
| `--rb-bg` | `#f7f6fb` |
| `--rb-surface` | `#ffffff` |
| `--rb-surface-subtle` | `#faf9fd` |
| `--rb-border` | `#eeebf5` |
| `--rb-text` | `#2e2a38` |
| `--rb-text-muted` | `#6f6a7d` |
| `--rb-success` | `#067647` |
| `--rb-danger` | `#b42318` |

---

### Task 1: 設計書更新(ADR-027・design-system・layout)

**Files:**
- Create: `docs/decisions/ADR-027-purple-theme.md`
- Modify: `docs/ui/design-system.md`(全面改訂)
- Modify: `docs/ui/layout.md`
- Modify: `docs/decisions/ADR-020-frontend-theme.md`(冒頭に置換注記)

**Interfaces:**
- Produces: 新パレットとカード仕様の正典。後続タスクはこの記述に従う

- [ ] **Step 1: ADR-027 を作成**

`docs/decisions/TEMPLATE.md` の見出し構成に合わせ、`docs/decisions/ADR-027-purple-theme.md` を作成する(Status: Accepted / Date: 2026-08-30)。決定事項として以下を記載:

1. 管理画面の基調を「白×くすみピンク×ベージュ + Glassmorphism」から「ラベンダー/パープル + 不透明の白カード」へ転換する。ADR-020 を supersede する
2. 上記「新パレット」の表をそのまま掲載する。加えてグラデーション: `--rb-gradient-brand: linear-gradient(160deg, #9b7bd6 0%, #7c5cbf 55%, #6d4fa8 100%)`、`rose/peach/mauve/cream` は淡いパープル4トーン(名前据え置き)
3. トークンは「意味的な新名を正典とし、旧名(`--rb-pink-*`/`--rb-beige-*`)はエイリアスとして残す」。改名は既存128箇所へ波及するため行わない
4. `backdrop-filter` を管理画面から全廃し、カードは不透明白 + 1px境界線 + 控えめな影とする。`.glass-card` はクラス名を据え置き定義のみ差し替え、`.rb-card` を同義の新名として追加する
5. レイアウトを「フル高パープルサイドバー + 白ヘッダー」に変更する。ADR-026 のレスポンシブ挙動(`<1024px` Drawer / `<600px` ユーザー名非表示)は維持する
6. 状態色を用途別に分離する: エラーは `--rb-danger`(従来 `--rb-pink-strong` を流用していた `.field-error` 7箇所)、増加は `--rb-success`、第2アクセント(情報ボックス・土曜・manager ロール・draft・外部予定・no_show)は `--rb-accent-*`(ベージュの後継)
7. 見出し・数値のフォントを `Zen Maru Gothic`(丸ゴシック)から `Noto Sans JP` に統一する。公開ページは Zen Maru Gothic を継続利用するため `index.html` の読み込みは維持する
8. 公開Web予約ページ(`/booking/*`)は現行デザインを維持する。`documentElement` への `rb-legacy-theme` クラス付与で隔離し、判定は `meta.public` ではなく新設の `meta.legacyTheme` で行う(`/login` も `meta.public: true` のため)

Consequences に記載: 旧トークン名と実際の色が乖離する(エイリアス解消は別タスク) / 公開ページと管理画面でデザインが二系統になる / `backdrop-filter` 廃止で iOS の描画負荷が下がり `preset.ts` の datepicker ハックが不要になる。

- [ ] **Step 2: docs/ui/design-system.md を全面改訂**

現行の「白×くすみピンク×ベージュ / Glassmorphism」の記述を、新パレット表・カード仕様(不透明白 + `--rb-border` の1px境界線 + `--rb-shadow-soft`、角丸 `--rb-radius-lg` 16px)・タイポグラフィ(本文/見出しとも Noto Sans JP)・状態色の割り当て(success/danger/accent の用途)・共通コンポーネント一覧に置き換える。`.glass-card` と `.rb-card` の関係(前者は後方互換のエイリアス)も明記する。

- [ ] **Step 3: docs/ui/layout.md を改訂**

共通レイアウトの記述を「左端にフル高(100dvh)のパープルグラデーションサイドバー(220px、内部にロゴ)+ 右側に白ヘッダーバー(ユーザーチップ/ログアウト)+ メイン領域」に更新する。`<1024px` でサイドバーは Drawer に切り替わること、Drawer のパネル背景は Teleport されるため `preset.ts` で指定することを明記する。

- [ ] **Step 4: ADR-020 に置換注記を追加**

`docs/decisions/ADR-020-frontend-theme.md` の Status 行(または冒頭)に一行追記する:

```markdown
> **本 ADR は [ADR-027](ADR-027-purple-theme.md) により置換された(2026-08-30)。以下は当時の決定の記録である。**
```

- [ ] **Step 5: コミット**

```bash
git add docs/decisions/ADR-027-purple-theme.md docs/decisions/ADR-020-frontend-theme.md docs/ui/design-system.md docs/ui/layout.md
git commit -m "docs: パープルテーマ刷新のADR-027と設計書更新

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: トークン層の刷新とレガシーテーマ隔離

**Files:**
- Modify: `frontend/src/assets/main.css`(全面書き換え)
- Modify: `frontend/src/theme/preset.ts`
- Modify: `frontend/src/router/index.ts`(公開3ルートに `meta.legacyTheme`)
- Modify: `frontend/src/App.vue`
- Test: `frontend/src/App.spec.ts`(新規)

**Interfaces:**
- Produces: 新トークン一式(上記パレット表)、`.rb-card` / `.glass-card`(白カード)、`:root.rb-legacy-theme` スコープ、`meta.legacyTheme` によるクラストグル。後続タスクはこれらを前提にする

- [ ] **Step 1: 失敗するテストを書く**

`frontend/src/App.spec.ts` を新規作成:

```ts
import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import App from './App.vue'

const Blank = { template: '<div />' }

function buildRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/dashboard', component: Blank },
      { path: '/login', component: Blank, meta: { public: true } },
      { path: '/booking/:slug', component: Blank, meta: { public: true, legacyTheme: true } },
    ],
  })
}

async function mountAt(path: string) {
  const router = buildRouter()
  await router.push(path)
  await router.isReady()
  const wrapper = mount(App, {
    global: {
      plugins: [router, PrimeVue, ToastService, ConfirmationService],
      stubs: { AppLayout: Blank },
    },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('App のテーマ切り替え', () => {
  afterEach(() => {
    document.documentElement.classList.remove('rb-legacy-theme')
  })

  it('公開予約ページではレガシーテーマクラスを付ける', async () => {
    await mountAt('/booking/demo-salon')
    expect(document.documentElement.classList.contains('rb-legacy-theme')).toBe(true)
  })

  it('管理画面ではレガシーテーマクラスを付けない', async () => {
    await mountAt('/dashboard')
    expect(document.documentElement.classList.contains('rb-legacy-theme')).toBe(false)
  })

  it('ログイン画面は public だがレガシーテーマにしない', async () => {
    await mountAt('/login')
    expect(document.documentElement.classList.contains('rb-legacy-theme')).toBe(false)
  })

  it('予約ページから管理画面へ遷移するとクラスを外す', async () => {
    const { router } = await mountAt('/booking/demo-salon')
    await router.push('/dashboard')
    await flushPromises()
    expect(document.documentElement.classList.contains('rb-legacy-theme')).toBe(false)
  })
})
```

- [ ] **Step 2: テストが失敗することを確認**

```bash
cd frontend && npm run test:unit -- App.spec
```

Expected: FAIL(`rb-legacy-theme` が付かない)

- [ ] **Step 3: router に meta.legacyTheme を追加**

`frontend/src/router/index.ts` の公開3ルートの `meta` を変更する(`/login` は変更しない):

```ts
      path: '/booking/cancel/:token',
      name: 'public-booking-cancel',
      component: () => import('@/pages/public/BookingCancelPage.vue'),
      meta: { public: true, legacyTheme: true },
```

同様に `/booking/:slug`(`public-booking`)と `/booking/:pathMatch(.*)*`(`public-not-found`)の `meta` も `{ public: true, legacyTheme: true }` にする。

- [ ] **Step 4: App.vue でクラスをトグルする**

`frontend/src/App.vue` を以下で置換:

```vue
<script setup lang="ts">
import { computed, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import AppLayout from '@/layouts/AppLayout.vue'

const route = useRoute()
const isPublic = computed(() => route.meta.public === true)

// 公開予約ページは旧テーマ（ピンク/グラス）を維持する。Toast などは body 直下へ
// Teleport されるため、ラッパー div ではなく documentElement にクラスを付ける。
watchEffect(() => {
  document.documentElement.classList.toggle('rb-legacy-theme', route.meta.legacyTheme === true)
})
</script>

<template>
  <Toast position="top-right" />
  <ConfirmDialog />
  <RouterView v-if="isPublic" />
  <AppLayout v-else />
</template>
```

- [ ] **Step 5: main.css を全面書き換え**

`frontend/src/assets/main.css` を以下で置換:

```css
/*
 * Realize Beauty — Design Tokens & Base Styles
 * 管理画面: ラベンダー/パープル × 不透明の白カード
 * 公開予約ページ: 旧テーマ（白×くすみピンク×ベージュ / Glassmorphism）を
 *   :root.rb-legacy-theme で維持する
 * docs/ui/design-system.md / ADR-027 参照
 */

:root {
  /* Colors */
  --rb-primary: #7c5cbf;
  --rb-primary-strong: #6d4fa8;
  --rb-primary-deep: #59408c;
  --rb-primary-soft: #c9b8ec;
  --rb-primary-tint: #ece5fa;
  --rb-primary-faint: #f5f1fd;

  /* 第2アクセント（情報ボックス・土曜・manager・draft・外部予定・no_show） */
  --rb-accent: #c9a227;
  --rb-accent-soft: #f6efdc;
  --rb-accent-deep: #8a6d1f;

  /* 状態色 */
  --rb-success: #067647;
  --rb-danger: #b42318;

  /* Surface */
  --rb-bg: #f7f6fb;
  --rb-surface: #ffffff;
  --rb-surface-subtle: #faf9fd;
  --rb-border: #eeebf5;
  --rb-text: #2e2a38;
  --rb-text-muted: #6f6a7d;

  /* Gradients（名前は後方互換のため据え置き。値はパープル4トーン） */
  --rb-gradient-rose: linear-gradient(135deg, #b9a3e8 0%, #7c5cbf 100%);
  --rb-gradient-peach: linear-gradient(135deg, #a7b6ef 0%, #6473c9 100%);
  --rb-gradient-mauve: linear-gradient(135deg, #d5aae2 0%, #9b5cbf 100%);
  --rb-gradient-cream: linear-gradient(135deg, #a9c9ea 0%, #5f86bf 100%);
  --rb-gradient-brand: linear-gradient(160deg, #9b7bd6 0%, #7c5cbf 55%, #6d4fa8 100%);

  /* Card */
  --rb-card-bg: var(--rb-surface);
  --rb-card-border: var(--rb-border);

  /* Shadow */
  --rb-shadow-soft: 0 2px 8px rgba(90, 70, 150, 0.06);
  --rb-shadow-hover: 0 8px 24px rgba(90, 70, 150, 0.12);
  --rb-shadow-brand: 0 4px 14px rgba(124, 92, 191, 0.32);

  /* Radius */
  --rb-radius-lg: 16px;
  --rb-radius-md: 12px;
  --rb-radius-sm: 10px;

  /* Fonts */
  --rb-font: 'Noto Sans JP', sans-serif;
  --rb-font-display: 'Noto Sans JP', sans-serif;

  /* 後方互換エイリアス（既存の var(--rb-pink-*) / var(--rb-beige-*) 参照用） */
  --rb-pink: var(--rb-primary);
  --rb-pink-strong: var(--rb-primary-strong);
  --rb-pink-deep: var(--rb-primary-deep);
  --rb-pink-soft: var(--rb-primary-soft);
  --rb-pink-tint: var(--rb-primary-tint);
  --rb-pink-faint: var(--rb-primary-faint);
  --rb-beige: var(--rb-accent);
  --rb-beige-soft: var(--rb-accent-soft);
  --rb-beige-deep: var(--rb-accent-deep);
}

*,
*::before,
*::after {
  box-sizing: border-box;
}

html,
body {
  margin: 0;
  padding: 0;
  min-height: 100vh;
}

body {
  font-family: var(--rb-font);
  color: var(--rb-text);
  background-color: var(--rb-bg);
  background-image: radial-gradient(
    ellipse 70% 55% at 15% 0%,
    rgba(201, 184, 236, 0.22),
    transparent 70%
  );
  background-attachment: fixed;
  -webkit-font-smoothing: antialiased;
}

#app {
  min-height: 100vh;
}

h1,
h2,
h3 {
  font-family: var(--rb-font-display);
  font-weight: 700;
  color: var(--rb-text);
}

/* ---------- Utilities ---------- */

.rb-card,
.glass-card {
  background: var(--rb-card-bg);
  border: 1px solid var(--rb-card-border);
  border-radius: var(--rb-radius-lg);
  box-shadow: var(--rb-shadow-soft);
}

.rb-gradient-rose {
  background: var(--rb-gradient-rose);
}

.rb-gradient-peach {
  background: var(--rb-gradient-peach);
}

.rb-gradient-mauve {
  background: var(--rb-gradient-mauve);
}

.rb-gradient-cream {
  background: var(--rb-gradient-cream);
}

.rb-page {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

/* ---------- Scrollbar ---------- */

::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

::-webkit-scrollbar-track {
  background: transparent;
}

::-webkit-scrollbar-thumb {
  background: var(--rb-primary-soft);
  border-radius: 999px;
}

::-webkit-scrollbar-thumb:hover {
  background: var(--rb-primary);
}

/* ---------- PrimeVue tweaks ---------- */

.p-component {
  font-family: var(--rb-font);
}

.p-button {
  border-radius: var(--rb-radius-sm);
}

.p-button:not(.p-button-outlined):not(.p-button-text):not(.p-button-link).p-button-primary,
.p-button:not(.p-button-outlined):not(.p-button-text):not(.p-button-link):not(
    [class*='p-button-sec']
  ):not([class*='p-button-dan']):not([class*='p-button-warn']):not([class*='p-button-help']):not(
    [class*='p-button-info']
  ):not([class*='p-button-succ']):not([class*='p-button-contrast']) {
  background: var(--rb-gradient-brand);
  border: none;
  box-shadow: var(--rb-shadow-brand);
}

.p-button:not(.p-button-outlined):not(.p-button-text):enabled:hover {
  box-shadow: var(--rb-shadow-hover);
}

.p-datatable .p-datatable-thead > tr > th {
  background: transparent;
  color: var(--rb-text-muted);
  font-weight: 500;
  border-color: var(--rb-border);
}

.p-datatable .p-datatable-tbody > tr {
  background: transparent;
  transition: background-color 0.15s ease;
}

.p-datatable .p-datatable-tbody > tr:hover {
  background: var(--rb-primary-faint);
}

.p-datatable .p-datatable-tbody > tr > td {
  border-color: var(--rb-border);
}

.p-paginator {
  background: transparent;
}

.p-tabs,
.p-tablist,
.p-tablist-tab-list {
  background: transparent;
}

.p-toast .p-toast-message {
  border-radius: var(--rb-radius-md);
}

.p-dialog {
  border-radius: var(--rb-radius-lg);
}

/* Drawer のパネルは body へ Teleport されるため preset.ts で着色する。
   閉じるボタンだけはグローバルで白系に上書きする。 */
.p-drawer .p-drawer-close-button {
  color: rgba(255, 255, 255, 0.85);
}

.p-drawer .p-drawer-close-button:hover {
  background: rgba(255, 255, 255, 0.16);
  color: #fff;
}

/* ---------- Legacy theme（公開予約ページ専用・ADR-027） ---------- */

:root.rb-legacy-theme {
  --rb-pink: #d86c8a;
  --rb-pink-strong: #c25373;
  --rb-pink-deep: #a2415d;
  --rb-pink-soft: #f6c9d6;
  --rb-pink-tint: #fbe4eb;
  --rb-pink-faint: #fdf2f5;
  --rb-beige: #e9dac8;
  --rb-beige-soft: #f3e9dd;
  --rb-beige-deep: #d9c3aa;
  --rb-bg: #fdf9f6;
  --rb-border: #f0e4e8;
  --rb-text: #4b4247;
  --rb-text-muted: #9a8d91;
  --rb-gradient-brand: linear-gradient(135deg, #e48aa3 0%, #d86c8a 55%, #c25373 100%);
  --rb-radius-lg: 20px;
  --rb-radius-md: 14px;
  --rb-radius-sm: 12px;
  --rb-font-display: 'Zen Maru Gothic', 'Noto Sans JP', sans-serif;
  --rb-shadow-soft: 0 8px 32px rgba(216, 108, 138, 0.1);
  --rb-shadow-hover: 0 12px 40px rgba(216, 108, 138, 0.18);
  --rb-shadow-brand: 0 4px 14px rgba(216, 108, 138, 0.35);
}

:root.rb-legacy-theme body {
  background-image:
    radial-gradient(ellipse 60% 50% at 12% 0%, rgba(246, 201, 214, 0.55), transparent 65%),
    radial-gradient(ellipse 55% 45% at 95% 12%, rgba(243, 233, 221, 0.9), transparent 70%),
    radial-gradient(ellipse 50% 45% at 80% 95%, rgba(251, 228, 235, 0.6), transparent 65%),
    radial-gradient(ellipse 45% 40% at 5% 90%, rgba(233, 218, 200, 0.5), transparent 65%);
}

:root.rb-legacy-theme .glass-card {
  background: rgba(255, 255, 255, 0.72);
  backdrop-filter: blur(18px);
  -webkit-backdrop-filter: blur(18px);
  border: 1px solid rgba(255, 255, 255, 0.65);
}

:root.rb-legacy-theme .p-toast .p-toast-message {
  backdrop-filter: blur(18px);
}
```

- [ ] **Step 6: preset.ts をパープル化**

`frontend/src/theme/preset.ts` を以下で置換:

```ts
import { definePreset } from '@primeuix/themes'
import Aura from '@primeuix/themes/aura'

/**
 * Realize Beauty theme preset.
 * ラベンダー/パープルを基調とした管理画面テーマ。
 * 色の値は main.css の --rb-* トークンと対応させること（二重管理のため）。
 * docs/ui/design-system.md / ADR-027 参照。
 */
export const RealizePreset = definePreset(Aura, {
  primitive: {
    borderRadius: {
      none: '0',
      xs: '6px',
      sm: '10px',
      md: '12px',
      lg: '16px',
      xl: '20px',
    },
  },
  semantic: {
    primary: {
      50: '#F5F1FD',
      100: '#ECE5FA',
      200: '#C9B8EC',
      300: '#AC95E0',
      400: '#9478D2',
      500: '#7C5CBF',
      600: '#6D4FA8',
      700: '#59408C',
      800: '#473370',
      900: '#382959',
      950: '#221935',
    },
    colorScheme: {
      light: {
        surface: {
          0: '#FFFFFF',
          50: '#F7F6FB',
          100: '#F1EFF8',
          200: '#EEEBF5',
          300: '#D9D4E6',
          400: '#B4AEC6',
          500: '#6F6A7D',
          600: '#5C5768',
          700: '#494553',
          800: '#2E2A38',
          900: '#241F2C',
          950: '#17131D',
        },
        text: {
          color: '#2E2A38',
          hoverColor: '#17131D',
          mutedColor: '#6F6A7D',
          hoverMutedColor: '#5C5768',
        },
        content: {
          background: '#FFFFFF',
          hoverBackground: '#F5F1FD',
          borderColor: '#EEEBF5',
          color: '#2E2A38',
          hoverColor: '#17131D',
        },
        highlight: {
          background: '#ECE5FA',
          focusBackground: '#C9B8EC',
          color: '#59408C',
          focusColor: '#473370',
        },
      },
    },
  },
  components: {
    // Drawer のパネルは body へ Teleport されるため scoped CSS が届かない。
    // サイドバーと同じグラデーションをここで指定する。
    drawer: {
      root: {
        background: 'linear-gradient(160deg, #9b7bd6 0%, #7c5cbf 55%, #6d4fa8 100%)',
        borderColor: 'transparent',
        color: '#ffffff',
      },
    },
  },
})
```

注: 旧 `components.datepicker` のハック(半透明 `content.background` 対策)は `content.background` を不透明にしたため削除する。

- [ ] **Step 7: テストと型チェック**

```bash
cd frontend && npm run test:unit && npm run type-check
```

Expected: 全て PASS(App.spec の4テストを含む)

- [ ] **Step 8: 整形してコミット**

```bash
cd frontend && npm run format
git add frontend/src/assets/main.css frontend/src/theme/preset.ts frontend/src/router/index.ts frontend/src/App.vue frontend/src/App.spec.ts
git commit -m "feat: デザイントークンをパープル基調に刷新し公開ページを旧テーマで隔離

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: AppLayout のフル高サイドバー化

**Files:**
- Modify: `frontend/src/layouts/AppLayout.vue`(template と style を全面書き換え、script は据え置き)

**Interfaces:**
- Consumes: Task 2 の `--rb-gradient-brand` / `--rb-surface` / `--rb-shadow-brand` と `preset.ts` の drawer 設定
- Produces: `.app-shell`(grid) / `.app-sidebar` / `.app-header` / `.app-main` の新構造

- [ ] **Step 1: template を書き換え**

`frontend/src/layouts/AppLayout.vue` の `<template>` を以下で置換(script は変更しない):

```vue
<template>
  <div class="app-shell">
    <aside class="app-sidebar">
      <RouterLink to="/dashboard" class="brand">
        <span class="brand-icon"><i class="pi pi-sparkles" /></span>
        <span class="brand-name">Realize Beauty</span>
      </RouterLink>
      <nav class="nav-list">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="nav-item"
          :class="{ active: $route.path.startsWith(item.to) }"
        >
          <i :class="item.icon" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>
    </aside>

    <Drawer v-model:visible="menuOpen" header="メニュー" class="mobile-drawer">
      <nav class="nav-list">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="nav-item"
          :class="{ active: $route.path.startsWith(item.to) }"
        >
          <i :class="item.icon" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>
    </Drawer>

    <div class="app-column">
      <header class="app-header">
        <div class="header-left">
          <Button
            icon="pi pi-bars"
            severity="secondary"
            text
            rounded
            class="menu-button"
            aria-label="メニューを開く"
            @click="menuOpen = true"
          />
          <RouterLink to="/dashboard" class="brand brand-compact">
            <span class="brand-icon"><i class="pi pi-sparkles" /></span>
            <span class="brand-name">Realize Beauty</span>
          </RouterLink>
        </div>
        <div class="header-right">
          <div class="user-chip">
            <Avatar :label="auth.user?.name?.charAt(0) ?? '?'" shape="circle" class="user-avatar" />
            <span class="user-name">{{ auth.user?.name ?? '' }}</span>
          </div>
          <Button
            icon="pi pi-sign-out"
            severity="secondary"
            text
            rounded
            aria-label="ログアウト"
            @click="confirmLogout"
          />
        </div>
      </header>

      <main class="app-main">
        <RouterView />
      </main>
    </div>
  </div>
</template>
```

- [ ] **Step 2: style を書き換え**

同ファイルの `<style scoped>` を以下で置換:

```css
<style scoped>
.app-shell {
  min-height: 100vh;
  min-height: 100dvh;
  display: grid;
  grid-template-columns: 220px 1fr;
}

.app-sidebar {
  position: sticky;
  top: 0;
  align-self: start;
  height: 100vh;
  height: 100dvh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.25rem 0.9rem;
  background: var(--rb-gradient-brand);
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0 0.35rem;
  text-decoration: none;
}

.brand-icon {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.2);
  color: #fff;
}

.brand-name {
  font-family: var(--rb-font-display);
  font-weight: 700;
  font-size: 1.1rem;
  color: #fff;
  white-space: nowrap;
}

.nav-list {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.7rem 0.9rem;
  border-radius: var(--rb-radius-md);
  color: rgba(255, 255, 255, 0.82);
  text-decoration: none;
  font-weight: 500;
  font-size: 0.95rem;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}

.nav-item i {
  font-size: 1.05rem;
}

.nav-item:hover {
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
}

.nav-item.active {
  background: rgba(255, 255, 255, 0.18);
  color: #fff;
  font-weight: 700;
}

.app-column {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.app-header {
  position: sticky;
  top: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.7rem 1.5rem;
  background: var(--rb-surface);
  border-bottom: 1px solid var(--rb-border);
}

.header-left {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.brand-compact {
  display: none;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.user-chip {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0.85rem 0.25rem 0.3rem;
  border-radius: 999px;
  background: var(--rb-primary-faint);
  border: 1px solid var(--rb-border);
}

.user-avatar {
  background: var(--rb-gradient-brand);
  color: #fff;
  font-weight: 700;
}

.user-name {
  font-size: 0.9rem;
  font-weight: 500;
}

.app-main {
  flex: 1;
  min-width: 0;
  width: 100%;
  max-width: 1240px;
  padding: 1.5rem;
}

.menu-button {
  display: none;
}

@media (max-width: 1023px) {
  .app-shell {
    grid-template-columns: 1fr;
  }

  .app-sidebar {
    display: none;
  }

  .menu-button,
  .brand-compact {
    display: inline-flex;
  }

  .app-header {
    padding: 0.7rem 1rem;
  }

  .app-main {
    padding: 1rem;
  }
}

@media (max-width: 599px) {
  .user-name {
    display: none;
  }

  .user-chip {
    padding: 0.15rem;
    background: transparent;
    border: none;
  }
}
</style>
```

- [ ] **Step 3: 検証してコミット**

```bash
cd frontend && npm run type-check && npm run test:unit && npm run lint && npm run format
git add frontend/src/layouts/AppLayout.vue
git commit -m "feat: 管理画面をフル高パープルサイドバーのレイアウトに変更

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

Expected: type-check / test:unit / lint すべて成功

---

### Task 4: KpiCard の白カード化

**Files:**
- Modify: `frontend/src/components/common/KpiCard.vue`
- Test: `frontend/src/components/common/KpiCard.spec.ts`(既存。変更しない)

**Interfaces:**
- Consumes: `--rb-surface` / `--rb-border` / `--rb-shadow-soft` / `--rb-success` / `--rb-danger` / `--rb-gradient-*`
- Produces: props インターフェースは不変(`label` / `value` / `icon` / `variant` / `prefix` / `suffix` / `delta` / `deltaSuffix`)

- [ ] **Step 1: 既存テストが通ることを確認(ベースライン)**

```bash
cd frontend && npm run test:unit -- KpiCard
```

Expected: PASS(3 tests)。この後の変更でも同じ結果でなければならない。

- [ ] **Step 2: template を書き換え**

`frontend/src/components/common/KpiCard.vue` の `<template>` を以下で置換(`<script setup>` は変更しない)。`kpi-deco` を削除し、グラデーションをカード全体からアイコンタイルへ移す:

```vue
<template>
  <div class="kpi-card">
    <div class="kpi-icon" :class="`rb-gradient-${variant}`"><i :class="icon" /></div>
    <div class="kpi-body">
      <span class="kpi-label">{{ label }}</span>
      <span class="kpi-value">
        <span v-if="prefix" class="kpi-prefix">{{ prefix }}</span
        >{{ displayValue }}<span v-if="suffix" class="kpi-suffix">{{ suffix }}</span>
      </span>
      <span v-if="delta !== null" class="kpi-delta" :class="delta >= 0 ? 'is-up' : 'is-down'">
        <i :class="delta >= 0 ? 'pi pi-arrow-up-right' : 'pi pi-arrow-down-right'" />
        {{ delta >= 0 ? '+' : '' }}{{ delta }}{{ deltaSuffix }}
      </span>
    </div>
  </div>
</template>
```

- [ ] **Step 3: style を書き換え**

同ファイルの `<style scoped>` を以下で置換:

```css
<style scoped>
.kpi-card {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.2rem 1.35rem;
  border-radius: var(--rb-radius-lg);
  background: var(--rb-surface);
  border: 1px solid var(--rb-border);
  box-shadow: var(--rb-shadow-soft);
  color: var(--rb-text);
  transition:
    transform 0.18s ease,
    box-shadow 0.18s ease;
}

.kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--rb-shadow-hover);
}

.kpi-icon {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  flex-shrink: 0;
  border-radius: var(--rb-radius-md);
  color: #fff;
  font-size: 1.15rem;
}

.kpi-body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.kpi-label {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--rb-text-muted);
}

.kpi-value {
  font-size: 1.75rem;
  font-weight: 700;
  font-family: var(--rb-font-display);
  line-height: 1.2;
  color: var(--rb-text);
}

.kpi-suffix {
  font-size: 0.9rem;
  margin-left: 0.15rem;
  font-weight: 500;
  color: var(--rb-text-muted);
}

.kpi-prefix {
  font-size: 0.95rem;
  margin-right: 0.1rem;
  font-weight: 700;
}

.kpi-delta {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  align-self: flex-start;
  margin-top: 0.15rem;
  font-size: 0.78rem;
  font-weight: 700;
}

.kpi-delta i {
  font-size: 0.62rem;
}

.kpi-delta.is-up {
  color: var(--rb-success);
}

.kpi-delta.is-down {
  color: var(--rb-danger);
}
</style>
```

- [ ] **Step 4: テストを再実行**

```bash
cd frontend && npm run test:unit -- KpiCard && npm run test:unit -- DashboardPage
```

Expected: 両方 PASS。落ちた場合はテストではなくコンポーネント側を直す。

- [ ] **Step 5: コミット**

```bash
cd frontend && npm run format
git add frontend/src/components/common/KpiCard.vue
git commit -m "feat: KPIカードを白カード+パープルアイコンタイルに変更

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: ダッシュボード各コンポーネントの配色更新

**Files:**
- Modify: `frontend/src/components/dashboard/SalesTrendChart.vue`
- Modify: `frontend/src/components/dashboard/TodayReservationList.vue`
- Modify: `frontend/src/components/dashboard/PopularMenuList.vue`
- Modify: `frontend/src/components/dashboard/CustomerSegmentList.vue`
- Modify: `frontend/src/pages/dashboard/DashboardPage.vue`(`.card-link` のみ)

**Interfaces:**
- Consumes: Task 2 のトークン
- Produces: 見た目のみ変更。props・テキスト・クラス名は不変

- [ ] **Step 1: SalesTrendChart の色定数を差し替え**

`frontend/src/components/dashboard/SalesTrendChart.vue` の色定数ブロック(`const ROSE = …` から `const GRID = …` まで)を以下で置換し、参照箇所(`borderColor: ROSE` / `backgroundColor: ROSE_FILL` / `pointBorderColor: ROSE`)の識別子も合わせて変更する:

```ts
// chart.js は CSS 変数を解釈できないため、main.css の --rb-* と同値をここに持つ（二重管理）
const PURPLE = '#7c5cbf'
const PURPLE_FILL = 'rgba(124, 92, 191, 0.14)'
const TEXT_MUTED = '#6f6a7d'
const GRID = '#eeebf5'
```

`datasets` 内は `borderColor: PURPLE`、`backgroundColor: PURPLE_FILL`、`pointBorderColor: PURPLE` にする。`pointBackgroundColor: '#fff'` は据え置き。

- [ ] **Step 2: TodayReservationList の色を更新**

`frontend/src/components/dashboard/TodayReservationList.vue` の `<style scoped>` で以下を置換:

- `.time` の `background: var(--rb-pink-tint);` → `background: var(--rb-primary-tint);`
- `.time` の `color: var(--rb-pink-deep);` → `color: var(--rb-primary-deep);`
- `.reservation-row:hover` の `background: var(--rb-pink-faint);` → `background: var(--rb-primary-faint);`
- `.status.is-reserved` の `background: var(--rb-pink-tint);` → `var(--rb-primary-tint)`、`color: var(--rb-pink-deep);` → `var(--rb-primary-deep)`
- `.status.is-visited` の `background: var(--rb-beige-soft);` → `var(--rb-accent-soft)`、`color: #7a6a4f;` → `color: var(--rb-accent-deep);`

- [ ] **Step 3: PopularMenuList の色を更新**

`frontend/src/components/dashboard/PopularMenuList.vue` の `<style scoped>` で以下を置換:

- `.menu-bar-track` の `background: var(--rb-pink-faint);` → `var(--rb-primary-faint)`
- `.menu-count` の `color: var(--rb-pink-deep);` → `var(--rb-primary-deep)`

`.menu-bar` の `background: var(--rb-gradient-brand);` は据え置き(トークン値の変更で追従)。

- [ ] **Step 4: CustomerSegmentList の色を更新**

`frontend/src/components/dashboard/CustomerSegmentList.vue` の `<style scoped>` で以下を置換:

- `.segment.is-new` の `background: var(--rb-pink-faint);` → `var(--rb-primary-faint)`
- `.segment.is-repeat` の `background: var(--rb-pink-tint);` → `var(--rb-primary-tint)`
- `.segment.is-dormant` の `background: var(--rb-beige-soft);` → `var(--rb-accent-soft)`
- `.segment.is-other` の `background: #fff;` → `background: var(--rb-surface-subtle);`

- [ ] **Step 5: DashboardPage の `.card-link` を更新**

`frontend/src/pages/dashboard/DashboardPage.vue` の `<style scoped>` の `.card-link` にある `color: var(--rb-pink);` を `color: var(--rb-primary);` に変更する。`kpiCards` の `variant`(`'rose'`/`'peach'`/`'mauve'`/`'cream'`)は変更しない。

- [ ] **Step 6: 検証してコミット**

```bash
cd frontend && npm run test:unit && npm run type-check && npm run format
git add frontend/src/components/dashboard/ frontend/src/pages/dashboard/DashboardPage.vue
git commit -m "feat: ダッシュボード各コンポーネントをパープル配色に更新

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

Expected: テスト全件 PASS(DashboardPage.spec を含む)

---

### Task 6: 共通コンポーネントとログイン画面

**Files:**
- Modify: `frontend/src/components/common/GlassCard.vue`
- Modify: `frontend/src/components/common/PageHeader.vue`
- Modify: `frontend/src/components/common/EmptyState.vue`
- Modify: `frontend/src/components/common/StatusTag.vue`
- Modify: `frontend/src/pages/auth/LoginPage.vue`

**Interfaces:**
- Consumes: Task 2 のトークン
- Produces: `.status-tag` / `.completed` / `.draft` のクラス名は不変(StatusTag.spec の契約)

- [ ] **Step 1: 共通コンポーネント3つの色トークンを新名に置換**

- `GlassCard.vue` の `.card-icon`: `background: var(--rb-pink-tint);` → `var(--rb-primary-tint)`、`color: var(--rb-pink-strong);` → `var(--rb-primary-strong)`
- `EmptyState.vue` の `.empty-icon`: `background: var(--rb-pink-tint);` → `var(--rb-primary-tint)`、`color: var(--rb-pink);` → `var(--rb-primary)`
- `StatusTag.vue`: `.completed` の `background: var(--rb-pink-tint);` → `var(--rb-primary-tint)`、`color: var(--rb-pink-deep);` → `var(--rb-primary-deep)`。`.draft` の `background: var(--rb-beige-soft);` → `var(--rb-accent-soft)`、`color: #8a7566;` → `color: var(--rb-accent-deep);`

- [ ] **Step 2: PageHeader のピンク影をトークン化**

`frontend/src/components/common/PageHeader.vue:48` の `box-shadow: 0 6px 18px rgba(216, 108, 138, 0.3);` を `box-shadow: var(--rb-shadow-brand);` に置換する。

- [ ] **Step 3: LoginPage を新テーマに合わせる**

`frontend/src/pages/auth/LoginPage.vue` を以下のように変更する:

- `.blob-rose` / `.blob-beige` / `.blob-mauve` の `background` をそれぞれ `var(--rb-gradient-rose)` / `var(--rb-gradient-peach)` / `var(--rb-gradient-mauve)` に統一し、各 `opacity` を `0.35`/`0.35`/`0.3` から `0.24` に下げる(パープルは重く出るため)。クラス名は変更しない
- `.brand-mark` の `box-shadow: 0 8px 24px rgba(216, 108, 138, 0.35);` → `box-shadow: var(--rb-shadow-brand);`
- `.field-error` の `color: var(--rb-pink-strong);` → `color: var(--rb-danger);`
- `.login-card`(`class="glass-card login-card"` の scoped 定義)に `box-shadow: 0 16px 48px rgba(90, 70, 150, 0.14);` を追加し、白背景でも浮遊感を保つ

- [ ] **Step 4: 検証してコミット**

```bash
cd frontend && npm run test:unit && npm run type-check && npm run format
git add frontend/src/components/common/ frontend/src/pages/auth/LoginPage.vue
git commit -m "feat: 共通コンポーネントとログイン画面をパープルテーマに更新

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

Expected: StatusTag.spec を含む全テスト PASS

---

### Task 7: 管理ページの残存ハードコード一掃

**Files:**
- Modify: `frontend/src/pages/settings/SettingsPage.vue`
- Modify: `frontend/src/pages/settings/BusinessHoursSettingsPage.vue`
- Modify: `frontend/src/pages/settings/LineSettingsPage.vue`
- Modify: `frontend/src/pages/settings/GoogleCalendarSettingsPage.vue`
- Modify: `frontend/src/pages/settings/MenuSettingsPage.vue`
- Modify: `frontend/src/pages/customer/CustomerDetailPage.vue`
- Modify: `frontend/src/pages/customer/CustomerFormPage.vue`
- Modify: `frontend/src/pages/record/RecordFormPage.vue`
- Modify: `frontend/src/pages/record/RecordDetailPage.vue`
- Modify: `frontend/src/pages/record/RecordListPage.vue`
- Modify: `frontend/src/components/reservation/ReservationFormDialog.vue`

**Interfaces:**
- Consumes: `--rb-surface-subtle` / `--rb-danger` / `--rb-accent-deep` / `--rb-shadow-brand`

- [ ] **Step 1: 入れ子パネルの半透明白を不透明トークンに置換**

以下の8箇所の `background` を `var(--rb-surface-subtle)` に置換する(白カード上では半透明白が見えなくなるため):

- `pages/settings/SettingsPage.vue:311`(`rgba(255, 255, 255, 0.55)`)
- `pages/settings/GoogleCalendarSettingsPage.vue:725`(同上)
- `pages/settings/BusinessHoursSettingsPage.vue:278`(同上)
- `pages/settings/LineSettingsPage.vue:636`(`rgba(255, 255, 255, 0.6)`)
- `pages/record/RecordFormPage.vue:538`(`rgba(255, 255, 255, 0.55)`)
- `pages/record/RecordDetailPage.vue:370`(同上)
- `pages/customer/CustomerDetailPage.vue:399`(同上)
- `pages/customer/CustomerDetailPage.vue:411`(`rgba(255, 255, 255, 0.85)` — こちらはホバー時なので `var(--rb-surface)` に置換)

- [ ] **Step 2: `.field-error` をエラー色に分離**

以下7箇所の `.field-error` セレクタ内の `color: var(--rb-pink-strong);` を `color: var(--rb-danger);` に置換する:

- `pages/settings/BusinessHoursSettingsPage.vue:340`
- `pages/settings/MenuSettingsPage.vue:523`
- `pages/settings/LineSettingsPage.vue:592`
- `pages/record/RecordFormPage.vue:518`
- `pages/customer/CustomerFormPage.vue:379`
- `components/reservation/ReservationFormDialog.vue:544`

(`pages/auth/LoginPage.vue:276` は Task 6 で対応済み)

**置換しないもの**: `.role-badge.staff`(SettingsPage:257)、`:deep(.p-tab-active)`(CustomerDetailPage:287)、`.records-link`(CustomerDetailPage:365)、`.no-menu-link`(ReservationFormDialog:612)、`.salon-link:hover`(SettingsPage:332)、`GlassCard.vue` の `.card-icon` — これらはアクセント用途でありエラーではない。

- [ ] **Step 3: ベージュ由来のハードコード文字色を置換**

以下3箇所の `color: #8a7566;` を `color: var(--rb-accent-deep);` に置換する:

- `pages/settings/BusinessHoursSettingsPage.vue:307`
- `pages/settings/GoogleCalendarSettingsPage.vue:764`
- `pages/settings/LineSettingsPage.vue:521`

(`components/common/StatusTag.vue:42` は Task 6 で対応済み)

- [ ] **Step 4: 残りのピンク影とピンク生値を置換**

- `pages/settings/SettingsPage.vue:198`(`.account-avatar`)の `box-shadow: 0 6px 18px rgba(216, 108, 138, 0.3);` → `box-shadow: var(--rb-shadow-brand);`
- `pages/record/RecordListPage.vue:254-255`(`.timeline-dot`)の `box-shadow` にある `rgba(246, 201, 214, …)` と `rgba(216, 108, 138, 0.35)` を、それぞれ `rgba(201, 184, 236, 0.55)` と `rgba(124, 92, 191, 0.35)` に置換
- `pages/customer/CustomerDetailPage.vue:316`(`.info-item`)の `background: rgba(253, 242, 245, 0.5);` → `background: var(--rb-primary-faint);`

- [ ] **Step 5: 置換漏れがないことを確認**

```bash
cd frontend/src && grep -rn 'rgba(216, 108, 138\|#8a7566\|#d86c8a\|#b98aa6' --include='*.vue' . | grep -v pages/public
```

Expected: 出力は `pages/reservation/ReservationCalendarPage.vue` の行のみ(Task 8 で対応)。それ以外が出たら置換する。

- [ ] **Step 6: 検証してコミット**

```bash
cd frontend && npm run test:unit && npm run type-check && npm run lint && npm run format
git add frontend/src/pages/settings/ frontend/src/pages/customer/ frontend/src/pages/record/ frontend/src/components/reservation/ReservationFormDialog.vue
git commit -m "feat: 管理ページの色指定をパープルトークンに統一しエラー色を分離

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: 予約カレンダーの配色刷新

**Files:**
- Modify: `frontend/src/pages/reservation/ReservationCalendarPage.vue`(`<style scoped>` のみ)

**Interfaces:**
- Consumes: `--rb-primary` / `--rb-primary-deep` / `--rb-text-muted` / `--rb-accent` / `--rb-surface` / `--rb-surface-subtle` / `--rb-shadow-brand`

- [ ] **Step 1: ヘッダ・時刻列・営業時間外セルの生値を置換**

`frontend/src/pages/reservation/ReservationCalendarPage.vue` の `<style scoped>` で以下を置換:

- `.head-cell` の `background: #fdf5f7;` → `background: var(--rb-primary-faint);`
- `.time-col` の `background: #fffdfc;` → `background: var(--rb-surface);`
- `.slot-cell:hover` の `background: var(--rb-pink-faint);` → `background: var(--rb-primary-faint);`
- `.slot-cell.outside` の `background: rgba(240, 228, 232, 0.55);` → `background: var(--rb-surface-subtle);`
- `.slot-cell.outside:hover` の `background: rgba(240, 228, 232, 0.9);` → `background: var(--rb-primary-faint);`
- `.staff-head i` の `color: var(--rb-pink);` → `color: var(--rb-primary);`
- `.block-source` の `color: var(--rb-pink-deep);` → `color: var(--rb-primary-deep);`

- [ ] **Step 2: 予約ブロックのステータス色をトークン化**

同ファイルで以下を置換する。白文字を載せるためコントラスト比 4.5:1 以上の色を選定している:

```css
.reservation-block.reserved {
  background: var(--rb-primary);
}

.reservation-block.visited {
  background: var(--rb-primary-deep);
}

.reservation-block.cancelled {
  background: rgba(111, 106, 125, 0.55);
  text-decoration: line-through;
}

.reservation-block.no_show {
  background: var(--rb-accent-deep);
}
```

あわせて `.reservation-block` の `box-shadow: 0 2px 8px rgba(216, 108, 138, 0.25);` を `box-shadow: 0 2px 8px rgba(124, 92, 191, 0.25);` に置換する。

- [ ] **Step 3: 外部予定ブロックをニュートラルグレーに置換**

`.external-block` の色をパープル基調の背景から浮かないグレーに合わせる:

- `border-left: 3px solid rgba(154, 141, 145, 0.6);` → `border-left: 3px solid rgba(111, 106, 125, 0.55);`
- `repeating-linear-gradient` の `rgba(154, 141, 145, 0.2)` 2箇所を `rgba(111, 106, 125, 0.16)` に、`rgba(154, 141, 145, 0.32)` 2箇所を `rgba(111, 106, 125, 0.26)` に置換
- `color: #63585c;` → `color: var(--rb-text);`
- `.reservation-block.cancelled` と同様、外部予定の `rgba(154, 141, 145, 0.55)` が他に残っていないか確認する

- [ ] **Step 4: 置換漏れの確認**

```bash
cd frontend/src && grep -n '#d86c8a\|#b98aa6\|#fdf5f7\|#fffdfc\|#63585c\|216, 108, 138\|154, 141, 145\|240, 228, 232\|203, 169, 109' pages/reservation/ReservationCalendarPage.vue
```

Expected: 出力なし

- [ ] **Step 5: 検証してコミット**

```bash
cd frontend && npm run type-check && npm run test:unit && npm run format
git add frontend/src/pages/reservation/ReservationCalendarPage.vue
git commit -m "feat: 予約カレンダーの配色をパープルトークンに統一

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 9: 総合検証

- [ ] **Step 1: 自動チェックの通過を確認**

```bash
cd frontend && npm run test:unit && npm run build && npm run lint
```

Expected: テスト全件 PASS、build(type-check 込み)成功、lint 差分なし

- [ ] **Step 2: 残存する旧テーマ色の最終確認**

```bash
cd frontend/src && grep -rn 'rgba(216, 108, 138\|#d86c8a\|#c25373\|#a2415d\|#f6c9d6\|#fbe4eb\|#fdf2f5\|#e9dac8\|#f3e9dd\|#d9c3aa\|#8a7566\|#b98aa6\|#7a6a4f' --include='*.vue' --include='*.ts' . | grep -v 'pages/public/'
```

Expected: 出力なし(公開ページとレガシーテーマ定義以外にピンク/ベージュの生値が残っていない)。`assets/main.css` の `:root.rb-legacy-theme` ブロックは意図的な定義なので対象外。

- [ ] **Step 3: モックモードで目視確認(メインセッションで実施)**

```bash
cd frontend && npm run dev:mock
```

確認項目:
- `/dashboard`: 白カード、パープルのKPIアイコンタイル、緑の増減、パープルの売上推移グラフ、フル高サイドバー
- `/customers`(一覧)・`/records`・`/settings`: カードとテーブルがパープル基調で崩れていない
- `/reservations`(予約カレンダー): ステータス色が読みやすく、営業時間外セル・外部予定が判別できる
- `/login`: 背景ブロブがパープルで、カードが浮いて見える
- 幅 1280px / 900px / 500px: レイアウトが崩れず、`<1024px` でハンバーガー → Drawer がパープル背景で開き、遷移で閉じる
- `/booking/<slug>`(公開ページ): **現行のピンク/グラスデザインのまま**であること。管理画面 → 公開ページ → 管理画面と遷移してもテーマが正しく切り替わること

- [ ] **Step 4: 仕上げ**

spec(`docs/superpowers/specs/2026-08-30-purple-theme-design.md`)のステータス行を `- ステータス: 実装済み (2026-08-30)` に更新してコミットする。

```bash
git add docs/superpowers/specs/2026-08-30-purple-theme-design.md
git commit -m "docs: パープルテーマ刷新specを実装済みに更新

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
