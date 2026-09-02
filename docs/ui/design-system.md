# Design System

## Theme

美容サロン向けCRMの管理画面として、ラベンダー/パープルを基調とし、不透明の白カードを組み合わせた清潔感のあるデザインを採用する（[ADR-027](../decisions/ADR-027-purple-theme.md)）。

コンセプト

- パープルのグラデーションサイドバーでブランドを表現しつつ、コンテンツ領域は不透明の白カードで情報を読みやすくする
- Glassmorphism（半透明 + backdrop-filter）は用いない。カードは不透明白 + 1px境界線 + 控えめな影で構成する
- PrimeVueの操作感を保ちつつ、丸み・余白・アイコンで柔らかさを出す

本ドキュメントは公開Web予約ページ（`/booking/*`）を除く全ページ（ダッシュボード・顧客・カルテ・予約・設定・ログイン）を対象とする。公開Web予約ページはADR-020時点のデザイン（白×くすみピンク×ベージュ + Glassmorphism）を `rb-legacy-theme` により維持する。詳細は [ADR-027](../decisions/ADR-027-purple-theme.md) を参照。

---

## Color

トークンは意味的な新名を正典とする。旧名（`--rb-pink-*` / `--rb-beige-*`）は既存実装との互換のためエイリアスとして残っているが、新規実装では以下の新トークンを使用する。

### Primary（パープル）

| トークン | 値 | 用途 |
| --- | --- | --- |
| `--rb-primary` | `#7c5cbf` | プライマリ（ボタン・リンク・アクティブ） |
| `--rb-primary-strong` | `#6d4fa8` | ホバー/押下 |
| `--rb-primary-deep` | `#59408c` | 淡色背景上のアクセント文字 |
| `--rb-primary-soft` | `#c9b8ec` | ボーダー強調・スクロールバー |
| `--rb-primary-tint` | `#ece5fa` | ピル/バッジ背景 |
| `--rb-primary-faint` | `#f5f1fd` | ホバー背景・淡いゾーン |

### Background / Surface

| トークン | 値 | 用途 |
| --- | --- | --- |
| `--rb-bg` | `#f7f6fb` | ページ背景（淡いラベンダーグレー、ほぼ無地） |
| `--rb-surface` | `#ffffff` | カード面（不透明白） |
| `--rb-surface-subtle` | `#faf9fd` | カード内の入れ子パネル |
| `--rb-border` | `#eeebf5` | 境界線 |

### Text

| トークン | 値 | 用途 |
| --- | --- | --- |
| `--rb-text` | `#2e2a38` | 本文 |
| `--rb-text-muted` | `#6f6a7d` | 補助テキスト（白背景でコントラスト比 5:1 以上） |

### 状態色

用途ごとに色を分離し、ブランド色（パープル）と衝突させない。

| トークン | 値 | 用途 |
| --- | --- | --- |
| `--rb-success` | `#067647` | 増加デルタ（KPIの前月比など） |
| `--rb-danger` | `#b42318` | エラー表示（`.field-error` 等）・減少デルタ |
| `--rb-accent` / `-soft` / `-deep` | `#c9a227` / `#f6efdc` / `#8a6d1f` | 第2アクセント（ベージュの後継）。情報ボックス・土曜・manager ロール・draft・外部予定・no_show の識別に使う |

---

## Gradient

サイドバーとKPIアイコンタイル・アバター等の識別色に使用する。`rose`/`peach`/`mauve`/`cream` は既存実装（`KpiCard.vue` の `variant` 型、`PopularMenuList.vue` の `VARIANTS`、`CustomerListPage.vue` の `AVATAR_VARIANTS`）との対応を保つため名前を据え置き、値のみ淡いパープル4トーンに差し替えている。

| トークン | 用途 | グラデーション |
| --- | --- | --- |
| `--rb-gradient-brand` | サイドバー・主要ボタン | `linear-gradient(160deg, #9b7bd6 0%, #7c5cbf 55%, #6d4fa8 100%)` |
| `--rb-gradient-rose` | KPIアイコンタイル・アバター | `linear-gradient(135deg, #b9a3e8 0%, #7c5cbf 100%)` |
| `--rb-gradient-peach` | KPIアイコンタイル・アバター | `linear-gradient(135deg, #a7b6ef 0%, #6473c9 100%)` |
| `--rb-gradient-mauve` | KPIアイコンタイル・アバター | `linear-gradient(135deg, #d5aae2 0%, #9b5cbf 100%)` |
| `--rb-gradient-cream` | KPIアイコンタイル・アバター | `linear-gradient(135deg, #a9c9ea 0%, #5f86bf 100%)` |

---

## Border Radius

| トークン | 値 | 用途 |
| --- | --- | --- |
| `--rb-radius-lg` | 16px | カード |
| `--rb-radius-md` | 12px | 入力・ボタン・写真サムネイル |
| `--rb-radius-sm` | 10px | 小要素 |

タグ・バッジは従来どおりピル型（999px）とする。

---

## Shadow

パープル系の控えめな影を使用する。Glassmorphism時代の拡散した影（`0 8px 32px rgba(216,108,138,0.10)`）より抑えたトーンにする。

| トークン | 値 | 用途 |
| --- | --- | --- |
| `--rb-shadow-soft` | `0 2px 8px rgba(90,70,150,0.06)` | カード標準影 |
| `--rb-shadow-hover` | `0 8px 24px rgba(90,70,150,0.12)` | ホバー |
| `--rb-shadow-brand` | `0 4px 14px rgba(124,92,191,0.32)` | ブランド色ボタン/アクティブ項目 |

---

## Card（カード仕様）

管理画面のカードはGlassmorphismを廃止し、不透明白 + 1px境界線 + 控えめな影で構成する。

- 背景: `--rb-surface`（`#ffffff`）
- 境界線: `1px solid var(--rb-border)`
- 影: `--rb-shadow-soft`（ホバー時は `--rb-shadow-hover`）
- 角丸: `--rb-radius-lg`（16px）
- `backdrop-filter` は使用しない

`.glass-card` クラスは名称を据え置いたまま、定義を上記の不透明白カードに差し替えている（後方互換のエイリアス）。以後の新規実装では同義の新クラス `.rb-card` を使用する。両者は見た目・仕様上の差異はない。

---

## Font

見出し・本文・数値のすべてを Noto Sans JP（`--rb-font-display`）に統一する。丸ゴシック（Zen Maru Gothic）は管理画面では使用しない。

- 本文・見出し・数値: Noto Sans JP

公開Web予約ページ（`/booking/*`）は引き続き Zen Maru Gothic を使用するため、`index.html` でのフォント読み込みは維持する。

---

## Icon

PrimeIcons を使用し、ナビゲーション・ボタン・KPI・空状態へ積極的に配置する。

---

## Photo Layout

写真一覧はInstagram風のグリッドレイアウトとする（カルテ写真管理画面が対象。方針は変更なし）。

- 正方形（aspect-ratio 1:1）
- 3カラム
- 間隔 6px
- ホバーでオーバーレイ表示（キャプション・削除）
- クリックで拡大表示
- サムネイル角丸は `--rb-radius-md`（12px）

---

## Components

### PrimeVue コンポーネント

- Button
- Card
- DataTable
- Dialog
- Drawer
- Tabs
- InputText
- Textarea
- DatePicker
- Toast
- FileUpload
- Badge / Tag
- Avatar
- Skeleton

### 共通コンポーネント（`frontend/src/components/common/`）

画面はこれらの共通コンポーネントを組み合わせて構成する。

| コンポーネント | 用途 |
| --- | --- |
| `GlassCard` | カード表示の基本単位（`.glass-card` クラスを使用。定義は不透明白カードに更新済み） |
| `PageHeader` | 画面タイトル・パンくず等のページヘッダー |
| `KpiCard` | ダッシュボードのKPI表示（白カード + アイコンタイル + 増減表示） |
| `EmptyState` | 一覧・検索結果が空のときの表示 |
| `StatusTag` | 予約ステータス・ロール等のタグ表示 |
| `PhotoGrid` | カルテ写真のInstagram風グリッド表示 |
