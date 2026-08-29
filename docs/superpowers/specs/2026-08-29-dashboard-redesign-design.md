# ダッシュボード刷新 + レスポンシブ化 設計書

- 日付: 2026-08-29
- ステータス: 承認済み
- 関連: ADR-026(新規作成予定) / docs/ui/dashboard.md / docs/api/components/schemas/dashboard.yaml / docs/db/ERD.md

## 背景と目的

現行ダッシュボードは件数系 KPI カード5枚と「最近のカルテ/最近の顧客」リストのみで、グラフや傾向情報がない。参考デザイン(サロン向け CRM のビジュアルモック)に合わせ、経営状態がひと目で分かるダッシュボードへ刷新する。同時に、管理画面共通レイアウト(AppLayout)がモバイル非対応である問題を解消する。

ロードマップ上 v0.2 Analytics に位置づけられていた「売上グラフ・人気メニュー」の一部を前倒しする。スコープ変更は ADR-026 として記録し、設計書(UI/API/ERD)を先に更新してから実装する(Documentation Driven Development)。

## 決定事項(ユーザー承認済み)

1. **売上データ源**: `reservations.price`(価格スナップショット)を追加。予約作成時にメニュー価格を記録し、既存予約はメニュー価格でバックフィル。会計テーブルは作らない(v0.2 で検討)。
2. **顧客タグ**: タグ機能は作らず、来店履歴から「新規/リピーター/休眠」を自動算出して表示(VIP は省略)。
3. **レスポンシブ範囲**: AppLayout(全画面共通の骨格)+ 新ダッシュボード。他ページの個別最適化は次ステップ。
4. **KPI 構成**: 「新規顧客数・予約数・売上・リピート率」の4枚に刷新(当月値+前月比)。「今日」の情報は「本日の来店予約」リストでカバー。

## 画面設計

```
┌─ KPI×4 ─────────────────────────────────────┐
│ 新規顧客数   予約数   売上   リピート率        │ ← 当月値 + 前月比%(増=緑/減=赤)
├─ 売上推移(直近6ヶ月・面グラフ) ─┬─ 本日の来店予約 ─┤
│                                │ 時刻・顧客・メニュー │
├─ 人気メニュー(当月上位5)        ─┴─ 顧客セグメント ─┤
│ 予約件数バー + 価格             │ 新規/リピーター/休眠 │
└─────────────────────────────────────────────┘
```

- 既存の「最近のカルテ/最近の顧客」セクションは**廃止**(一覧ページで代替)。
- 本日の来店予約: 行クリックで予約詳細へ遷移。0件時は EmptyState。
- 人気メニュー: メニュー写真は存在しないため、グラデーション背景のアイコンタイルで代替。予約件数バー(当月上位1位を100%とする相対バー)+ 税込価格を表示。
- 顧客セグメント: 件数付きのピル型タグ表示(StatusTag と同系の見た目)。
- KPI カードは既存 KpiCard を拡張し前月比デルタ(矢印+%)を追加。グラデーション variant(rose/peach/mauve/cream)は維持。

### ブレークポイント

| 幅 | KPI | 下段 |
|---|---|---|
| ≥1024px | 4カラム | 2カラム(売上推移+来店予約 / 人気メニュー+セグメント) |
| 600–1023px | 2×2 | 1カラム |
| <600px | 1カラム | 1カラム |

### AppLayout のレスポンシブ化

- `<1024px`: サイドバー非表示。ヘッダーにハンバーガーボタンを表示し、PrimeVue **Drawer** で既存 nav 5項目(ダッシュボード/顧客/カルテ/予約/設定)を表示。ルート遷移で自動クローズ。
- `<600px`: ヘッダーのユーザー名テキストを非表示(アバターのみ)。
- `≥1024px`: 現状の 220px サイドバーを維持。
- docs/ui/README.md の方針を「PC優先」→「PC最適 + モバイル対応(共通レイアウト)」に改訂。

## データ定義(すべて JST 境界に統一)

| 項目 | 定義 |
|---|---|
| 新規顧客数 | `customers.first_visit_at` が当月(JST)の顧客数 |
| 予約数 | 当月(JST)に `start_at` がある予約のうちキャンセル系 status を除いたもの |
| 売上 | 当月の来店済み(status=visited)予約の `price` 合計 |
| リピート率 | 当月来店顧客のうち、当月より前にも来店歴(first_visit_at < 当月初)のある顧客の割合。前月比はポイント差 |
| 売上推移 | 直近6ヶ月(当月含む)の月次売上(visited 予約の price 合計) |
| 人気メニュー | 当月の visited 予約をメニュー別に件数集計、上位5件 |
| セグメント | 判定順: 休眠=最終来店(last_visit_at)から90日超 → 新規=初来店が当月 → リピーター=来店2回以上 → その他。対象は来店歴のある顧客のみ |

- 既存実装の TZ 不整合(today 系=UTC、予約=JST)は、本刷新で **JST に統一**する。ADR-026 に明記。
- 前月比(%またはポイント差)の計算はフロントエンドで行う(API は current/previous を返す)。
- しきい値(休眠90日、上位5件、6ヶ月)は定数として実装し、変更容易にする。

## API 設計

`GET /api/v1/dashboard` のレスポンスを刷新する(利用者はこのフロントのみのため互換維持不要)。1リクエストで全データを返す。

```yaml
data:
  kpis:
    new_customers:  { current: int, previous: int }
    reservations:   { current: int, previous: int }
    sales:          { current: int, previous: int }      # 円
    repeat_rate:    { current: float, previous: float }  # 0-100(%)
  sales_trend:            # 直近6ヶ月、古い順
    - { month: "2026-03", sales: int }
  today_reservations:     # 本日(JST)、start_at 昇順、キャンセル系除く
    - { id, start_at, status, customer: {id, name}, menu: {id, name} }
  popular_menus:          # 当月 visited 件数上位5
    - { menu_id, name, price, count }
  customer_segments:
    { new: int, repeat: int, dormant: int, other: int }
```

- Backend は Controller → Service → Repository 構成を維持し、`DashboardRepository` に集計クエリを追加。レスポンスは Resource で整形。
- OpenAPI(`docs/api/components/schemas/dashboard.yaml`)と `docs/api/endpoints.md` を先に更新。

## DB 変更

- `reservations.price`(integer, nullable, 税込円)を追加するマイグレーション。
  - 予約作成時(管理・公開Web予約とも)にメニューの現在価格をスナップショット。
  - 既存予約は `menus.price`(SoftDelete 済み含む)でバックフィル。
  - メニュー変更(予約更新で menu_id が変わる場合)は価格を再スナップショット。
- ERD.md に反映。会計・決済テーブルは作らない。

## フロントエンド実装

- **チャート**: chart.js + PrimeVue `Chart` コンポーネントを採用。軸・ツールチップ・面グラデーションは `--rb-*` トークンに合わせる。
- 新規コンポーネント(`components/dashboard/`): `SalesTrendChart` / `TodayReservationList` / `PopularMenuList` / `CustomerSegments`。
- `KpiCard` に前月比デルタ表示(delta props)を後方互換で追加。
- `DashboardPage.vue` を新レイアウトに書き換え。Skeleton ローディング・Toast エラー・EmptyState は既存パターンを踏襲。
- `types/dashboard.ts` を新レスポンスに合わせて刷新。`dashboardService` は変更最小。
- `mockAdapter.ts` に新レスポンスのモックを実装(`npm run dev:mock` で確認可能)。
- AppLayout: ハンバーガー+Drawer 追加、メディアクエリ導入。

## テスト

- Backend(Feature): 新ダッシュボード API の集計(JST 境界・前月値・セグメント分類・人気メニュー順位)、予約作成時の price スナップショット、バックフィルマイグレーション。
- Frontend(vitest): DashboardPage の表示(モックサービス)、CustomerSegments・KpiCard デルタ表示の spec。
- 既存テストの回帰(composer test / npm run type-check / lint)。

## やらないこと

- タグ機能・VIP 表示(DB/API 新設が必要な独立機能のため)
- サイドバーへの「売上分析」「メッセージ」メニュー追加、サロン切替・通知アイコン(MVP 外)
- ダークモード(現行のライト固定を維持)
- ダッシュボード以外のページのモバイル最適化(次ステップに分割)
- 会計・決済テーブルの新設(v0.2 で検討)
