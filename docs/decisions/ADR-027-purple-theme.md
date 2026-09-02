# ADR-027: 管理画面パープルテーマ刷新（ADR-020 を supersede）

## Status

Accepted

---

## Date

2026-08-30

---

## Context

現行の管理画面は ADR-020 で定めた「白×くすみピンク（#D86C8A）×ベージュ + Glassmorphism（半透明白カード + backdrop-filter）」で構成されている。

参考デザイン（ラベンダー/パープル基調・不透明の白カード・フル高のグラデーションサイドバー）に合わせ、配色と質感の両方を刷新する要望が生じた。配色変更だけでなく **質感の方針転換**（ガラス風 → 不透明の白カード）と **レイアウト構造の変更**（浮くカード型サイドバー → フル高サイドバー）を伴うため、単なるトークン差し替えではなく ADR-020 を supersede する意思決定として記録する。

`--rb-pink-*` 系トークンは実装コード全体で 128 箇所から参照されており、トークン名を一斉に改名すると全ファイルに機械的差分が広がりレビュー不能になる。既存コードを壊さずに移行する方式もあわせて決定する必要がある。

---

## Decision

以下を決定する。

### 1. 基調の転換とADR-020のsupersede

管理画面の基調を「白×くすみピンク×ベージュ + Glassmorphism」から「ラベンダー/パープル + 不透明の白カード」へ転換する。本ADRは [ADR-020](ADR-020-frontend-theme.md) を supersede する。

### 2. 新パレット

以下を新パレットの正典とする。

| トークン | 値 | 用途 |
|---|---|---|
| `--rb-primary` | `#7c5cbf` | プライマリ（ボタン・リンク・アクティブ） |
| `--rb-primary-strong` | `#6d4fa8` | ホバー/押下 |
| `--rb-primary-deep` | `#59408c` | 淡色背景上のアクセント文字 |
| `--rb-primary-soft` | `#c9b8ec` | ボーダー強調・スクロールバー |
| `--rb-primary-tint` | `#ece5fa` | ピル/バッジ背景 |
| `--rb-primary-faint` | `#f5f1fd` | ホバー背景・淡いゾーン |
| `--rb-bg` | `#f7f6fb` | ページ背景（ほぼ無地） |
| `--rb-surface` | `#ffffff` | カード面 |
| `--rb-surface-subtle` | `#faf9fd` | カード内の入れ子パネル |
| `--rb-border` | `#eeebf5` | 境界線 |
| `--rb-text` | `#2e2a38` | 本文 |
| `--rb-text-muted` | `#6f6a7d` | 補助テキスト（白背景でコントラスト比 5:1 以上） |
| `--rb-success` | `#067647` | 増加デルタ |
| `--rb-danger` | `#b42318` | エラー・減少デルタ |
| `--rb-accent` / `-soft` / `-deep` | `#c9a227` / `#f6efdc` / `#8a6d1f` | 第2アクセント（ベージュの後継） |
| `--rb-shadow-soft` | `0 2px 8px rgba(90,70,150,0.06)` | カード標準影 |
| `--rb-shadow-hover` | `0 8px 24px rgba(90,70,150,0.12)` | ホバー |
| `--rb-shadow-brand` | `0 4px 14px rgba(124,92,191,0.32)` | ブランド色ボタン/アクティブ項目 |
| `--rb-radius-lg` / `-md` / `-sm` | `16px` / `12px` / `10px` | 角丸 |
| `--rb-font-display` | Noto Sans JP 系 | 見出し・数値 |

グラデーションは以下を正典とする。`rose`/`peach`/`mauve`/`cream` は名前を据え置いたまま値のみ淡いパープル4トーンに差し替える。

| トークン | 値 |
|---|---|
| `--rb-gradient-brand` | `linear-gradient(160deg, #9b7bd6 0%, #7c5cbf 55%, #6d4fa8 100%)` |
| `--rb-gradient-rose` | `linear-gradient(135deg, #b9a3e8 0%, #7c5cbf 100%)` |
| `--rb-gradient-peach` | `linear-gradient(135deg, #a7b6ef 0%, #6473c9 100%)` |
| `--rb-gradient-mauve` | `linear-gradient(135deg, #d5aae2 0%, #9b5cbf 100%)` |
| `--rb-gradient-cream` | `linear-gradient(135deg, #a9c9ea 0%, #5f86bf 100%)` |

### 3. トークン戦略（既存コードを壊さない移行）

意味的な新名（上記2.）を正典とし、旧名（`--rb-pink-*` / `--rb-beige-*`）はエイリアスとして残す。旧トークン名から新トークンの値を参照する形にすることで、既存128箇所の参照はそのまま動作する。改名は既存コードへの波及が大きいため今回は行わない（エイリアスの解消は別タスク）。

### 4. Glassmorphismの廃止とカード仕様

`backdrop-filter` を管理画面から全廃する。カードは不透明白（`--rb-surface`）+ 1px境界線（`--rb-border`）+ 控えめな影（`--rb-shadow-soft`）とする。

`.glass-card` クラスはクラス名を据え置いたまま定義のみ上記の不透明白カードに差し替える（既存の利用箇所が一括で追従する）。以後の新規実装向けに、同義の新クラス `.rb-card` を追加する。

### 5. レイアウト構造の変更

レイアウトを「フル高（100dvh）のパープルグラデーションサイドバー + 白ヘッダー」に変更する。[ADR-026](ADR-026-dashboard-analytics.md) で定めたレスポンシブ挙動（`<1024px` でサイドバーが Drawer に切り替わる / `<600px` でユーザー名を非表示にする）は維持する。

### 6. 状態色の用途別分離

状態を表す色をブランド色から独立させる。

- エラー: `--rb-danger`（従来 `--rb-pink-strong` を流用していた `.field-error` 7箇所を置き換える）
- 増加（デルタ）: `--rb-success`
- 第2アクセント（情報ボックス・土曜・manager ロール・draft・外部予定・no_show の識別）: `--rb-accent-*`（ベージュの後継）

### 7. フォントの統一

見出し・数値のフォントを `Zen Maru Gothic`（丸ゴシック）から `Noto Sans JP` に統一する。本文はすでに Noto Sans JP のため、これにより管理画面は本文・見出し・数値すべて Noto Sans JP に統一される。

公開Web予約ページは `Zen Maru Gothic` を継続利用するため、`index.html` でのフォント読み込みは維持する。

### 8. 公開Web予約ページのデザイン維持

公開Web予約ページ（`/booking/*`）は現行デザイン（ADR-020時点のピンク×ベージュ + Glassmorphism）を維持する。

`documentElement`（`html` 要素）に `rb-legacy-theme` クラスを付与する方式で管理画面のテーマから隔離する。判定キーは既存の `meta.public` ではなく、新設する `meta.legacyTheme` で行う。`/login` も `meta.public: true` だが管理画面側の新テーマを適用する必要があるため、判定キーを分離する。

---

## Alternatives Considered

### 既存トークン名を新パレットに合わせて一斉改名する

意味と名前が一致し理想的だが、`--rb-pink-*` 系が128箇所から参照されており、改名すると全ファイルに機械的差分が広がりレビュー不能になる。エイリアス方式を採用し、改名は別タスクへ切り出す。

### `meta.public` を流用して公開ページ判定を行う

追加の実装が不要だが、`/login` も `meta.public: true` であり管理画面の新テーマを適用したいページと公開ページを区別できない。新設の `meta.legacyTheme` で判定する方式を採用する。

### ラッパー`<div>`でテーマを分離する

`App.vue` にラッパー要素を挟む方式も検討したが、body 背景・body へ Teleport される Toast / ConfirmDialog / DatePicker パネル・スクロールバーがスコープ外になり不十分。`documentElement` へのクラス付与方式を採用する。

---

## Consequences

### Advantages

- 参考デザインに沿ったブランド刷新を、既存128箇所の参照を壊さずに実現できる
- 状態色（成功/エラー/第2アクセント）がブランド色から独立し、warning/errorの視認性が保たれる
- `backdrop-filter` 廃止により iOS での描画負荷が下がり、`preset.ts` の datepicker 半透明対策ハックが不要になる

### Disadvantages

- 旧トークン名（`--rb-pink-*` / `--rb-beige-*`）と実際の色が乖離する（例: `--rb-pink` が実際にはパープル値を指す）。エイリアス解消は別タスクとする
- 公開ページ（レガシーテーマ）と管理画面（新テーマ）でデザインが二系統になり、`main.css` の二層構成の保守コストが発生する

---

## References

- docs/ui/design-system.md
- docs/ui/layout.md
- docs/decisions/ADR-020-frontend-theme.md
- docs/decisions/ADR-026-dashboard-analytics.md
- docs/superpowers/specs/2026-08-30-purple-theme-design.md
- frontend/src/theme/preset.ts
- frontend/src/assets/main.css
