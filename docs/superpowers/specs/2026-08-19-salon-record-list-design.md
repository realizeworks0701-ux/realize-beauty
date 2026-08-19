# サロン全体のカルテ一覧 — 設計書

作成日: 2026-08-19
対象フェーズ: MVP（Medical Record）の実装漏れ補完

---

## Purpose

サイドメニューから、サロン全体のカルテを横断して閲覧・検索できるようにする。

現状カルテへの導線は「顧客を特定してから顧客詳細 → カルテ一覧」の一本のみで、
ダッシュボードの「最近のカルテ」に出る直近5件を除き、顧客名を思い出せないカルテには到達できない。

---

## Background

`docs/ui/wireframe.md` の共通レイアウトにはサイドメニュー項目 `📝 Records` が定義されているが、
`AppLayout.vue` の `navItems` には存在しない。設計書にある項目の実装漏れである。

さらにサイドメニューの定義は3箇所で食い違っている。

| 定義元 | 現状 |
|---|---|
| `docs/ui/wireframe.md:5-19` | Dashboard / Customers / **Records** / Settings（Reservations なし） |
| `docs/ui/layout.md:27-32` | Dashboard / Customers / **Reservations** / Settings（Records なし） |
| `frontend/src/layouts/AppLayout.vue:12-17` | ダッシュボード / 顧客 / 予約 / 設定（Records なし） |

本設計で3者を `ダッシュボード / 顧客 / カルテ / 予約 / 設定` に統一する。

`docs/requirements/MVP.md` の Medical Record 節には「カルテ一覧」の記載がない一方、
`docs/roadmap/ROADMAP.md` には `[x] カルテ一覧` がある。MVP.md 側にも追記して根拠を揃える。

---

## Scope

### In Scope

1. サイドメニューに「カルテ」を追加する（顧客と予約の間）
2. サロン全体のカルテ一覧ページ `/records` を新設する（ステータス絞り込み・顧客名キーワード検索・ページネーション）
3. `GET /api/v1/records` を追加する
4. 論理削除済み顧客のカルテによる 500 を解消する（既存バグ。ダッシュボードで既に発生している）
5. `records` テーブルに `(salon_id, visited_at)` 複合インデックスを追加する
6. 上記に対応する設計書の更新

### Out of Scope

- サロン全体一覧からのカルテ新規作成（顧客コンテキストが必須のため導線を置かない）
- サロン全体一覧からのカルテ削除
- 絞り込み状態の URL クエリ同期（コードベースに前例がなく、新パターンの持ち込みを避ける）
- `sort` パラメータ（並び順は来店日降順に固定）
- スタッフ（`users`）論理削除への対処。スタッフ削除の導線は MVP に存在しない（`GET /api/v1/users` のみ）
- Role による閲覧制御（MVP 未実装。`auth:sanctum` のみ）

---

## 決定事項

| 論点 | 決定 | 理由 |
|---|---|---|
| パス | `/records` | `/customers/records` は `/customers/:id` に `:id="records"` として食われ、かつサイドメニューが二重に光る |
| ルート名 | 新規に `record-list`、既存の顧客別を `customer-record-list` へ改名 | `record-list` が顧客別を指すのは直感に反する。参照は2箇所のみで改名コストが小さい |
| メニューの active 判定 | 変更しない | 全ルート×全 navItems の一致表を確認済み。`/records` 追加で既存ハイライトは1つも変化せず、いずれのパスでも一致は 0 or 1 個 |
| 表示形式 | DataTable + lazy Paginator | 顧客名の列が増えるため。顧客一覧と同じ流儀に揃える |
| 新規作成ボタン | 置かない | カルテ作成は `/customers/:id/records/create` で顧客が必須。この画面には顧客が定まらない |
| 削除ボタン | 置かない | 閲覧・検索専用とする。削除は顧客文脈のある既存2画面に残す |
| 検索対象 | `customers.name` / `customers.kana` | 電話番号・メールでのカルテ検索は要求にない |
| 検索の照合 | `like` + `'%'.$keyword.'%'`（`CustomerRepository` と同一） | 片方だけ `ilike` にすると「顧客一覧では出るがカルテ一覧では出ない」差が生まれる |
| 並び順 | `visited_at DESC, id DESC` | タイブレークがないとページ跨ぎで重複・欠落が起きる |
| `per_page` | FormRequest で `max:100` | 既存の一覧APIは全て素通しで上限なし。本エンドポイントを前例とする |
| Resource | `RecordResource` を共用 | 一覧・詳細で共用済み。フロントの `TreatmentRecord` 型をそのまま流用できる |

---

## 論理削除済み顧客の扱い（既存バグの解消）

`RecordResource` は `$this->customer->id` を null チェックなしで参照する。
`Customer` は SoftDeletes を使い、`CustomerService::delete` は `records` に何もしない。
`belongsTo` の eager load には `SoftDeletingScope` が効くため、`customer` が null になり 500 になる。

これは新機能固有のリスクではなく、`DashboardRepository::getSummary` の `recent_records` が
既にサロン全体スコープで Record を引いているため **`GET /api/v1/dashboard` で既に発生している**。

### 方針

| 対象 | 扱い | 理由 |
|---|---|---|
| 一覧（サロン全体・ダッシュボードの最近のカルテ） | `whereHas('customer')` で除外 | 顧客一覧から消えた顧客のカルテが一覧に出るのは不整合 |
| 詳細（`GET /api/v1/records/{id}`） | `customer` を `withTrashed()` で eager load して表示 | 既知のURL・ブックマークが 500 にならないようにする |
| 顧客別一覧（`GET /api/v1/customers/{id}/records`） | 対処不要 | `CustomerRepository::findOrFail` が先に 404 を返す |

新一覧だけ塞ぐと同じデータでダッシュボードだけ 500 のまま残るため、一貫して適用する。

---

## API

### GET /api/v1/records

サロン全体のカルテ一覧を取得する。

#### Query Parameters

| Name | Type | Required | Description |
|---|---|---|---|
| status | string | | `draft` / `completed`。空文字は 422 |
| keyword | string | | 顧客の氏名・フリガナの部分一致 |
| page | integer | | 既定 1 |
| per_page | integer | | 既定 20、最大 100 |

#### Response

`200 OK`。`RecordResource` のページネーション封筒（`data` / `links` / `meta`）。
一覧では `blocks` / `photos` はロードしないため、`whenLoaded` によりキー自体が出力されない。

#### Errors

| Code | Condition |
|---|---|
| 401 | 未認証 |
| 422 | `status` が enum 外、`per_page` が範囲外 |

#### Notes

- `salon_id` は `$request->user()->salon_id` を Controller → Service → Repository へ渡し、Repository の全クエリ先頭で絞る（既存の3層リレー方式）
- 論理削除済み顧客のカルテは含まない
- `customer.phone` を含む `RecordResource` を返すため、全スタッフに全顧客の電話番号が渡る。Role 制御は MVP 未実装

---

## Backend 実装

既存のカルテ関連メソッド名（`index` / `list` / `paginate`）は顧客別で埋まっているため、3層すべてで別名を用意する。

| 層 | 追加・変更 |
|---|---|
| `routes/api.php` | `Route::get('records', [RecordController::class, 'indexAll'])` を `// Records` ブロック先頭へ |
| `RecordController` | `indexAll(ListRecordsRequest $request): JsonResponse` |
| `ListRecordsRequest`（新規） | `status` / `keyword` / `page` / `per_page` の検証 |
| `RecordService` | `listBySalon(int $salonId, array $filters): LengthAwarePaginator` |
| `RecordRepository` | `paginateBySalon(int $salonId, array $filters): LengthAwarePaginator` を追加。`findOrFail` の eager load を `customer` は `withTrashed()` に変更 |
| `DashboardRepository` | `recent_records` に `whereHas('customer')` を追加 |
| マイグレーション（新規） | `records` に `(salon_id, visited_at)` インデックスを追加 |

### インデックス

`records` には `salon_id` 単体のインデックスすら存在しない（`customers` にはある）。
サロン全体×来店日降順はフルスキャン＋ソートになるため複合インデックスを追加する。
前例として `reservations` に `(salon_id, start_at)` がある。`docs/db/ERD.md` の Index 節も同時に更新する。

---

## Frontend 実装

| ファイル | 追加・変更 |
|---|---|
| `router/index.ts` | `/records` を `name: 'record-list'` で追加。既存 `/customers/:id/records` を `customer-record-list` へ改名 |
| `layouts/AppLayout.vue` | `navItems` の顧客と予約の間に `{ label: 'カルテ', icon: 'pi pi-file-edit', to: '/records' }` |
| `pages/record/RecordListAllPage.vue`（新規） | サロン全体のカルテ一覧 |
| `services/recordService.ts` | `list(params: RecordListParams)` を追加 |
| `types/record.ts` | `RecordListParams` を追加 |
| `pages/record/RecordDetailPage.vue` | 削除後の遷移先ルート名を `customer-record-list` へ |
| `pages/customer/CustomerDetailPage.vue` | `RouterLink` のルート名を `customer-record-list` へ |
| `services/mock/mockAdapter.ts` | `GET /records` のハンドラを追加 |

### 一覧ページの実装方針

`CustomerListPage.vue` の定型に従う。

- `fetchSeq` によるレスポンス追い越し対策
- `keyword` は 300ms デバウンス（`watch` → `clearTimeout` / `setTimeout`、`onBeforeUnmount` でクリア）
- フィルタ変更時は `page.value = 1` にリセット
- 空文字は `undefined` に落として送らない（`?status=` は Enum 検証で 422 になる）
- `initialized` フラグで初回のみスケルトン
- `EmptyState` は「検索ヒット0」と「データ0」で文言を分岐
- `first` はローカル `page` ref 由来で算出する
- `per_page` は送らずサーバ既定（20）に従う。`mockAdapter` の `/records` も 20 件返し、モック開発と本番で表示件数が割れないようにする

### 列構成

| 列 | 内容 |
|---|---|
| 来店日 | `formatDate(visited_at)` |
| 顧客名 | `customer.name`（`customer.kana` を副次表示） |
| 担当 | `user.name` |
| ステータス | `StatusTag` |

行クリックで `/records/:id` へ遷移する。

---

## テスト

### Backend（`tests/Feature/`）

`use CreatesSalonUsers, RefreshDatabase;` ＋ `$this->actingAsSalonUser()` の既存作法に従う。

- 封筒（`data` / `links` / `meta`）を返す
- 他サロンのカルテが含まれない
- 未認証は 401
- `status` で絞り込める
- `keyword` で顧客の氏名・フリガナに部分一致する
- `visited_at` 降順で返る
- 論理削除済み顧客のカルテが含まれない（500 にならない）
- `status` が enum 外なら 422、`per_page` が 100 超なら 422
- ダッシュボードが論理削除済み顧客のカルテで 500 にならない（回帰テスト）

`Record::factory()->for($user->salon)->create()` は `customers.salon_id` が別サロンになる不整合データを作るため、
顧客のサロンと `visited_at` を必ず明示する。

### Frontend

`RecordListAllPage.spec.ts` を対象ファイルの隣に置く。`recordService` を `vi.hoisted` + `vi.mock` で差し替える。

- 一覧を描画する
- ステータス絞り込みで API パラメータが変わる
- キーワード検索がデバウンスされ、ページが1に戻る

---

## 更新する設計書

| ファイル | 内容 |
|---|---|
| `docs/requirements/MVP.md` | Medical Record 節に「カルテ一覧」を追記 |
| `docs/db/ERD.md` | Index 節の `records` に `(salon_id, visited_at)` を追記 |
| `docs/api/endpoints.md` | Records 節に `GET /records` を追加 |
| `docs/api/openapi.yaml` | `paths` に `/records` の `$ref` を追加 |
| `docs/api/paths/records.yaml` | トップレベルキー `records:` を追加（`operationId: listSalonRecords`） |
| `docs/api/components/parameters.yaml` | `RecordStatusFilter` を追加 |
| `docs/ui/wireframe.md` | 共通レイアウトのサイドメニューを5項目に |
| `docs/ui/layout.md` | Sidebar を5項目に |
| `docs/ui/screen-list.md` | No 07 を `Customer Record List` に改名、No 19 に `Record List (Salon)` を追加 |
| `docs/ui/navigation.md` | `Dashboard --> RecordListAll` / `RecordListAll --> RecordDetail` を追加 |
| `docs/ui/record/list-all.md` | 新設 |

ADR は不要。既存の設計方針（3層構造・`RecordResource` 共用・サイドメニュー構成）の枠内であり、
新たなトレードオフの選択を伴わないため。
