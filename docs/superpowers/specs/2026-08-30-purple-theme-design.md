# 管理画面パープルテーマ刷新 設計書

- 日付: 2026-08-30
- ステータス: 実装済み (2026-08-30)
- 関連: ADR-027(新規作成予定、ADR-020 を supersede) / docs/ui/design-system.md / docs/ui/layout.md

## 背景と目的

現行の管理画面は「白×くすみピンク×ベージュ + グラスモーフィズム(半透明カード + 華やかな背景グラデーション)」で構成されている(ADR-020)。参考デザイン(ラベンダー/パープル基調・不透明の白カード・フル高のグラデーションサイドバー)に合わせ、配色と質感の両方を刷新する。

配色変更だけでなく **質感の方針転換**(グラス → フラット白カード)と **レイアウト構造の変更**(浮くカード型サイドバー → フル高サイドバー)を伴うため、ADR-020 を supersede する ADR-027 として記録し、design-system.md を全面改訂してから実装する(Documentation Driven Development)。

## 決定事項(ユーザー承認済み)

1. **カード質感**: 写真どおりのフラット白カード。不透明白 + 1px境界線 + 控えめな影。背景は淡いラベンダーグレーのほぼ無地。グラスモーフィズム(blur/半透明)は管理画面から廃止。
2. **レイアウト構造**: 画面左端にフル高のパープルグラデーションサイドバー(内部にロゴ)、右側に白いヘッダーバー + メイン領域。
3. **KPIカード**: 白カード + グレーのラベル + 大きな濃色数値 + 緑/赤の増減。アイコンタイルのみ4色の淡いパープル系背景を残して項目を区別する。
4. **適用範囲**: 管理画面全ページ(ダッシュボード・顧客・カルテ・予約・設定・ログイン)。公開Web予約ページ(`/booking/*`)は**現行のピンク/グラスデザインを維持**する。

## トークン戦略(既存コードを壊さない移行)

事前調査の結果、`--rb-pink-*` 系トークンは src 全体で 128 箇所から参照されている。トークン名を一斉に改名すると全ファイルに機械的差分が広がりレビュー不能になるため、**「意味のある新トークンを正典とし、旧トークン名はエイリアスとして残す」**方式を採る。

```css
:root {
  /* 正典(新規実装はこちらを使う) */
  --rb-primary: #7c5cbf;
  --rb-primary-strong: #6d4fa8;
  ...
  /* 後方互換エイリアス(既存128箇所の参照がそのまま動く) */
  --rb-pink: var(--rb-primary);
  --rb-pink-strong: var(--rb-primary-strong);
  ...
}
```

エイリアスは今回の刷新では削除しない(削除は別タスク)。ただし **エラー表示に `--rb-pink-strong` を流用している6ファイル**は、パープルではブランド色と同化して警告の意味を失うため、新設する `--rb-danger` に個別に置き換える。

### 新しいトークン

| トークン | 値 | 用途 |
|---|---|---|
| `--rb-primary` | `#7c5cbf` | プライマリ(ボタン・リンク・アクティブ) |
| `--rb-primary-strong` | `#6d4fa8` | ホバー/押下 |
| `--rb-primary-deep` | `#59408c` | 淡色背景上のアクセント文字 |
| `--rb-primary-soft` | `#c9b8ec` | ボーダー強調・スクロールバー |
| `--rb-primary-tint` | `#ece5fa` | ピル/バッジ背景 |
| `--rb-primary-faint` | `#f5f1fd` | ホバー背景・淡いゾーン |
| `--rb-bg` | `#f7f6fb` | ページ背景(ほぼ無地) |
| `--rb-surface` | `#ffffff` | カード面(新規) |
| `--rb-surface-subtle` | `#faf9fd` | カード内の入れ子パネル(新規)。現行の `rgba(255,255,255,0.55)` 9箇所を置換 |
| `--rb-border` | `#eeebf5` | 境界線 |
| `--rb-text` | `#2e2a38` | 本文 |
| `--rb-text-muted` | `#6f6a7d` | 補助テキスト(白背景でコントラスト比 5:1 以上を確保。現行 `#9a8d91` は 3.2:1 で不足) |
| `--rb-success` | `#067647` | 増加デルタ(新規) |
| `--rb-danger` | `#b42318` | エラー・減少デルタ(新規) |
| `--rb-accent` / `-soft` / `-deep` | ソフトゴールド系 `#c9a227` / `#f6efdc` / `#8a6d1f` | ベージュの後継(新規)。情報ボックス・土曜・manager ロール・draft・外部予定・no_show の識別に使う |
| `--rb-gradient-brand` | `linear-gradient(160deg, #9b7bd6 0%, #7c5cbf 55%, #6d4fa8 100%)` | サイドバー・主要ボタン |
| `--rb-gradient-rose/peach/mauve/cream` | 淡いパープル系4トーン(名前は据え置き) | KPIアイコンタイル・アバター等の識別色 |
| `--rb-shadow-soft` | `0 2px 8px rgba(90, 70, 150, 0.06)` | カード標準影(拡散を締める) |
| `--rb-shadow-hover` | `0 8px 24px rgba(90, 70, 150, 0.12)` | ホバー |
| `--rb-shadow-brand` | `0 4px 14px rgba(124, 92, 191, 0.32)` | ブランド色ボタン/アクティブ項目(新規) |
| `--rb-radius-lg/md/sm` | `16px / 12px / 10px` | 角丸 |
| `--rb-font-display` | Noto Sans JP 系 | 見出し・数値(丸ゴシックをやめ直線的な印象に統一) |

**`--rb-gradient-rose|peach|mauve|cream` の名前は変更しない。** これらは `KpiCard.vue` の `variant` 型 `'rose'|'peach'|'mauve'|'cream'`、`PopularMenuList.vue` の `VARIANTS` 配列、`CustomerListPage.vue` の `AVATAR_VARIANTS` と文字列連結で結び付いており、改名すると型定義とテストまで波及するため。値のみパープル4トーンに差し替える。

### ハードコード色の一掃

トークンを経由しない生値が広範囲にある。以下をすべてトークン参照へ置換する(調査で特定済み):

- `rgba(216,108,138,…)`(ピンクの影)15箇所: `main.css:36,37,158` / `PageHeader.vue:48` / `SalesTrendChart.vue:10` / `AppLayout.vue:154,244` / `LoginPage.vue:213` / `RecordListPage.vue:255` / `ReservationCalendarPage.vue:624` / `SettingsPage.vue:198` / 公開4箇所(レガシー側で維持)
- `rgba(255,255,255,0.55)`(入れ子パネル)7ファイル9箇所 → `--rb-surface-subtle`
- `ReservationCalendarPage.vue` のステータス直書き(`#d86c8a`, `#b98aa6`, `#fdf5f7`, `#fffdfc`, `rgba(203,169,109,…)` ほか) — 生値の密度が最も高く、最大の作業対象
- `#8a7566`(StatusTag draft ほか4ファイル)、`#7a6a4f`(TodayReservationList)、`#eafff2`/`#ffe3e3`(KpiCard デルタ)、`main.css:178` の DataTable ホバー
- **例外**: `BookingPage.vue:958` の `#06c755` は LINE ブランドカラーのため変更しない。

## カードとユーティリティ

- `--rb-glass-bg` / `-border` / `-blur` は Vue 側からの参照が 0 件(`main.css` の `.glass-card` と `.p-toast` からのみ)なので、`--rb-card-bg` / `--rb-card-border` に改名し不透明値にする。`backdrop-filter` は管理画面から全廃する。
- `.glass-card` はクラス名を据え置いたまま定義を白カードに差し替える(利用17箇所が一括で追従)。`.rb-card` を同義のエイリアスとして新設し、以後の新規実装はこちらを使う。
- `preset.ts` の `content.background` を不透明の白に戻せるため、半透明対策として入れていた `components.datepicker` のハック(preset.ts:72-80)を削除できる。
- PrimeVue 上書き(`main.css` 末尾12ブロック)を新テーマに合わせて更新。`background: transparent` 系(DataTable/Paginator/Tabs)は白カード上に載る前提で見直す。
- `preset.ts` と `main.css` は同じ色を二重管理している(primary 50〜950 ↔ `--rb-primary-*`、surface ↔ text、content.borderColor ↔ border)。**対応表を作り必ず同時に更新する。**

## レイアウト(AppLayout)

```
┌────────────┬──────────────────────────────────┐
│            │  白ヘッダーバー(ユーザー/ログアウト) │
│  パープル   ├──────────────────────────────────┤
│  グラデ     │                                  │
│  サイド     │   メイン(淡いラベンダーグレー)     │
│  バー220px  │   白カードが並ぶ                   │
│  (ロゴ内包) │                                  │
└────────────┴──────────────────────────────────┘
```

- 現行の「`app-shell` の縦 flex + padding + sticky ヘッダー + `glass-card` サイドバー」を捨て、**body 直下 grid(左: 固定幅サイドバー / 右: ヘッダー+メイン)**へ組み替える。
- サイドバー: `height: 100dvh`(フォールバックに `100vh` 併記) + `position: sticky; top: 0` + `overflow-y: auto`。背景は `--rb-gradient-brand`。上部にロゴ(白文字 + 白半透明タイル)、ナビは白文字、アクティブは `rgba(255,255,255,0.18)` の角丸 + 白文字。
- ヘッダー: 白背景・下境界線。ユーザーチップとログアウトのみ(ページタイトルは従来どおり `PageHeader` が担当し、役割の重複を避ける)。
- レスポンシブ(ADR-026 の挙動を維持): `<1024px` はサイドバー非表示 + ハンバーガー + Drawer、`<600px` はユーザー名非表示。
- **Drawer のパネルは `<Teleport to="body">` されるため scoped CSS(`:deep` 含む)が届かない。** パネルの背景・文字色は `preset.ts` の `components.drawer`(root の background/color/borderColor)で指定し、閉じるボタンは `closeButtonProps` かグローバル CSS で白系にする。一方 Drawer のスロット内容(`.nav-list` / `.nav-item`)は AppLayout の render 由来なので scoped が効く。

## ダッシュボード要素

- **KpiCard**: 濃背景+白文字+`kpi-deco`+`backdrop-filter` の構造を、白カード + 淡いパープルのアイコンタイル(4色で識別) + グレーのラベル + 濃色の大きな数値 + 増減テキスト(増=`--rb-success`/減=`--rb-danger`)に作り替える。
- **SalesTrendChart**: chart.js は CSS 変数を読めないためハードコードのままとし、`#7c5cbf` / `rgba(124,92,191,0.14)` / 新しいミュート色 / 新しい境界線色に更新。定数上のコメントに「main.css のトークンと二重管理」である旨を明記する。
- **TodayReservationList / PopularMenuList / CustomerSegmentList**: 時刻ピル・バー・セグメント枠を `--rb-primary-tint` / `--rb-primary-faint` / `--rb-gradient-brand` 系に置換。

### 既存テストの契約(壊してはいけない)

- `KpiCard.spec.ts`: `.kpi-delta` の有無、`is-up` / `is-down` クラス、テキスト `¥` `324,000` `+8.3%` `-4.2%`。`variant` を渡さずにマウントするため既定値が有効であること。**PrimeVue コンポーネントや RouterLink を KpiCard 内に新規導入しない**(plugins/stubs 無しでマウントされるため)。
- `DashboardPage.spec.ts`: テキスト部分一致のみ(`新規顧客数` `+20%` `324,000` `14件` `リピーター` `本日の予約はありません` ほか)。**`/reservations` 以外の RouterLink を増やさない**(ルート未定義になる)、**SalesTrendChart 以外に重量コンポーネントを追加しない**(stub が必要になる)。
- `wrapper.text()` は textContent 比較のため、`{{ delta }}{{ deltaSuffix }}` や `{{ menu.count }}件` の補間の間に要素や改行を挟むと空白が入って落ちる。デルタの装飾を変える場合もテキストノードは連続させる。

## 公開予約ページの現行デザイン維持

調査の結果、`App.vue` にラッパー `<div>` を挟む方式では**不十分**であることが判明した。(1) body 背景、(2) body へ Teleport される Toast / ConfirmDialog / DatePicker パネル、(3) スクロールバー、がスコープ外になるため。したがって:

- **`documentElement`(html 要素)に `rb-legacy-theme` クラスを付与する方式**を採る。`App.vue` でルート変更を監視し、対象ルートでのみクラスを付け外しする。
- `main.css` を二層構成にする: `:root`(新テーマ) と `:root.rb-legacy-theme`(旧トークン22個 + 旧 body 背景 + 旧 `.glass-card` 定義 + 旧 `.p-button` 系)。エイリアス方式のため、レガシー側では `--rb-pink` 等に旧値を直接代入して上書きする。
- **判定キーは `meta.public` ではなく新設の `meta.legacyTheme: true`**。`/login` も `meta.public: true` だが管理画面側(新テーマ)にするため。`/booking/:slug`・`/booking/cancel/:token`・`/booking/:pathMatch(.*)*` の3ルートにのみ付与する。
- 旧テーマ復元に必要なトークンは22個(公開ページが直接参照する14個 + `.glass-card`/body/PrimeVue 経由の間接依存8個)。`--rb-beige`・`--rb-beige-deep`・`--rb-gradient-rose|peach|mauve|cream` の6個は公開ページ未使用のため新テーマ側で自由に扱える。

## 設計書の更新

- **ADR-027**(新規): パープル + フラットカードへの転換、ADR-020 の supersede、レイアウト構造変更、公開ページのレガシーテーマ分離(html クラス方式)、第2アクセント色とエラー色の分離、フォント統一を記録。
- `docs/ui/design-system.md`: 全面改訂(新パレット・カード仕様・タイポグラフィ・状態色の割り当て)。
- `docs/ui/layout.md`: フル高サイドバー構造に改訂。
- `docs/decisions/ADR-020-frontend-theme.md`: 冒頭に「ADR-027 により置換」の追記のみ(本文は歴史記録として残す)。

## テスト

- 既存 vitest spec は上記「契約」を守れば全て通る想定。落ちた場合はデザイン側を直す(期待値は変えない)。
- `npm run type-check` / `npm run lint` / `npm run build` の通過。
- モックモード(`npm run dev:mock`)で目視確認する画面: ダッシュボード / 顧客一覧 / **予約カレンダー(生値ハードコードの集中箇所)** / 設定 / ログイン、デスクトップ・タブレット・モバイルの3幅、Drawer 表示、および **公開予約ページが現行のピンクデザインのままであること**。
- ステータス色は白文字を載せるためコントラスト比 4.5:1 を実測して確認する(現行の visited `#b98aa6` などは未達の可能性が高い)。

## やらないこと

- ダークモード対応(現行どおりライト固定)
- 公開予約ページのデザイン変更
- 画面構成・機能の変更(レイアウト構造変更を除き表示項目は現状維持)
- 旧トークン名エイリアスの削除(別タスク)
- ブレークポイントの統一(480/520/560/599/640/900/1023 が乱立しているが、配色刷新と混ぜるとレビュー困難になるため別タスク)
- 重複 CSS の共通化(`photo-add-tile` / `state-tag` / 情報ボックスの3組。同上の理由で別タスク)
- 予約カレンダーのモバイル最適化(現状 @media 無し。別タスク)
- バックエンドの変更
