# API Endpoints

## API Information

| Item | Value |
|------|-------|
| Base URL | `/api/v1` |
| Authentication | Laravel Sanctum |
| Response Format | JSON |
| API Style | RESTful |

> 上記は管理側APIの共通情報。フェーズ2で追加した公開Web予約API（`/api/public/v1`・認証なし）と LINE Webhook（`/api/line/webhook`）、フェーズ3で追加した Googleカレンダー Webhook（`/api/google/calendar/webhook`・認証なし）、サブスクリプション課金で追加した Stripe Webhook（`/api/webhooks/stripe`・認証なし）は後述の各セクションを参照。
> Googleカレンダー連携のうち `GET /google-calendar/callback` のみ、`/api/v1` 配下だが認証なし（Google からのブラウザリダイレクトのため）。

---

# Authorization

## Roles

| Role | Description |
|------|-------------|
| owner | 店舗オーナー（全権限） |
| manager | 店舗管理者 |
| staff | 一般スタッフ |

> MVPでは全ロール同一権限とし、将来的にRole Middlewareを導入する。

---

# Plan / Feature 制限

契約プランに含まれない機能のエンドポイントは 403（Feature Required）で遮断する（[ADR-029](../decisions/ADR-029-subscription-billing.md) 参照）。判定は認証の内側で行うため、未認証の場合は 401 が優先する。

## Plans

| プラン | 月額（税込・円） | 利用できる機能 |
|--------|------------------|----------------|
| Lite | 980 | 顧客管理 / カルテ管理 / 写真管理 |
| Standard | 1,980 | Lite の全機能 + 予約管理 / Googleカレンダー連携 / LINE連携 |
| Pro | 3,980 | Standard の全機能 + AI要約 / 高度な分析 |

## Features

| 機能キー | 機能名 | 対象エンドポイント | 必要プラン |
|----------|--------|--------------------|------------|
| customer | 顧客管理 | `/customers` 系すべて | Lite |
| medical_record | カルテ管理 | `/records` 系・`/customers/{customer}/records` 系 | Lite |
| photo | 写真管理 | `POST /records/{record}/photos`・`DELETE /photos/{id}` | Lite |
| reservation | 予約管理 | `/menus` 系・`/reservations` 系・`GET /booking-page`・公開Web予約の booking_slug 経路 | Standard |
| line | LINE連携 | `/line-settings` 系すべて | Standard |
| google_calendar | Googleカレンダー連携 | `/google-calendar` 系すべて（認証なしの `GET /google-calendar/callback` を除く） | Standard |
| ai_summary | AI要約 | `POST /records/{record}/summarize`（カルテ管理と両方が必要） | Pro |
| analytics | 高度な分析 | `GET /dashboard` の sales_trend / popular_menus / customer_segments（403 ではなく null を返す） | Pro |

### Notes

- プラン→機能の対応表は backend の `config/billing.php` を単一の正とする（プラン名で分岐する判定はコードに書かない）
- **制限しないエンドポイント**: `/auth` 系・`GET /dashboard`（本体）・`/business-hours`（営業時間はサロンの基本情報として全プランで編集できる）・`GET /users`・`/subscription` 系すべて・公開Web予約の booking_token 経路（`GET /bookings/{booking_token}` / `POST /bookings/{booking_token}/cancel`）
- 契約が無い、または契約状態が利用を許さないサロンは**プランなし＝全機能 403**（fail closed）。既定プランによる救済はしない
- 利用できるのは契約状態が trialing / active / past_due のいずれかの場合のみ。past_due（支払い失敗〜Stripe の自動再試行中）では止めず、回収フローが尽きて unpaid になった時点で 403 になる。解約申請中（cancel_at_period_end=true）は Stripe 上 active のままのため期間終了まで利用できる
- `POST /auth/login` / `GET /auth/me` が返す `features` は画面の出し分けのための情報であり、遮断は常にサーバ側の 403 が担う
- 例外1: 公開Web予約の booking_slug 経路は 403 ではなく **404** を返す（スラッグの実在を外部に知らせないため。後述の「Public Booking（公開Web予約）」参照）
- 例外2: LINE Webhook / Googleカレンダー Webhook はプラン対象外のサロン宛でもログのみで **200** を返す（非 2xx を返すと外部が再送を繰り返し、最終的にエンドポイントを無効化するため）
- 例外3: `GET /dashboard` は全プランで開ける。analytics を含まないプランでは該当3キーが null になるだけで 403 にはしない
- ダウングレード・解約でデータは削除しない。顧客・カルテ・写真に加えて LINE連携情報・Googleカレンダー接続も保持し、再契約時にそのまま復帰できる

---

# Authentication

## POST /auth/login

### Purpose

ログインし、アクセストークンを取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | 不要 |
| Roles | - |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| email | string | ✓ | メールアドレス |
| password | string | ✓ | パスワード |

### Response

```json
{
  "data": {
    "token": "xxxxxxxx",
    "user": {
      "id": 1,
      "name": "山田 太郎",
      "email": "owner@example.com",
      "role": "owner",
      "plan": "standard",
      "subscription_status": "active",
      "features": {
        "customer": true,
        "medical_record": true,
        "photo": true,
        "reservation": true,
        "google_calendar": true,
        "line": true,
        "ai_summary": false,
        "analytics": false
      }
    }
  }
}
```

### Notes

- `user` は `GET /auth/me` と同形式
- `plan` / `subscription_status` / `features` は契約プランに応じた画面の出し分けのために返す（前述の「Plan / Feature 制限」参照）

---

## POST /auth/logout

### Purpose

ログアウトする。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

204 No Content

---

## GET /auth/me

### Purpose

ログイン中のユーザー情報を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

```json
{
  "data": {
    "id": 1,
    "name": "山田 太郎",
    "email": "sample@example.com",
    "role": "owner",
    "plan": "standard",
    "subscription_status": "active",
    "features": {
      "customer": true,
      "medical_record": true,
      "photo": true,
      "reservation": true,
      "google_calendar": true,
      "line": true,
      "ai_summary": false,
      "analytics": false
    }
  }
}
```

### Notes

- `plan` は現在有効なプラン。契約が無い、または契約状態が利用を許さない場合は null（`features` も全 false）
- `subscription_status` は契約状態（trialing / active / past_due / canceled / unpaid / incomplete / incomplete_expired / paused）。契約行が無い場合は null
- `features` は全機能キーを漏れなく列挙する。SPA はこれでメニュー・ボタンを出し分けるが、遮断そのものはサーバ側の 403 が担う（前述の「Plan / Feature 制限」参照）
- 契約状態の詳細・プランカタログは `GET /subscription` を使う

---

# Dashboard

## GET /dashboard

### Purpose

ダッシュボード情報を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

```json
{
  "data": {
    "kpis": {
      "new_customers": { "current": 12, "previous": 10 },
      "reservations": { "current": 28, "previous": 25 },
      "sales": { "current": 324000, "previous": 300000 },
      "repeat_rate": { "current": 78.0, "previous": 74.5 }
    },
    "sales_trend": [
      { "month": "2026-03", "sales": 210000 }
    ],
    "today_reservations": [],
    "popular_menus": [
      { "menu_id": 1, "name": "プレミアムフェイシャル", "price": 12000, "count": 14 }
    ],
    "customer_segments": { "new": 28, "repeat": 42, "dormant": 6, "other": 4 }
  }
}
```

### Notes

- 集計はすべてサロンTZ（Asia/Tokyo）の日付境界で行う（従来の UTC 境界との混在は解消済み。[ADR-026](../decisions/ADR-026-dashboard-analytics.md) 参照）
- `kpis` は当月（current）と前月（previous）の値。増減率の計算はフロントエンドで行う
- `sales` / `sales_trend` は status=visited の予約の `price`（予約時点のメニュー価格スナップショット）合計
- `repeat_rate` は当月来店顧客のうち当月より前に初来店していた顧客の割合（%、小数1桁）
- `today_reservations` は当日の予約（status reserved / visited、start_at 昇順）。要素は Reservation と同形
- `popular_menus` は当月の visited 予約のメニュー別件数上位5件（price は現在のメニュー価格）
- `customer_segments` は来店歴のある顧客の分類。判定順: dormant（最終来店から90日超）→ new（初来店が当月）→ repeat（来店2回以上）→ other
- ダッシュボード本体は**全プランで利用できる**（プラン制限の対象外。403 にはしない）
- **高度な分析（analytics）を含まないプランでは `sales_trend` / `popular_menus` / `customer_segments` が null になる**。キー自体は残し、レスポンスの形は変えない（前述の「Plan / Feature 制限」参照）
- フロントは該当キーが null のとき、そのカードの代わりにアップグレード導線を表示する

---

# Customers

本グループのエンドポイントは、契約プランに**顧客管理**（`customer`）が含まれない場合すべて 403 を返す（前述の「Plan / Feature 制限」参照）。

## GET /customers

### Purpose

顧客一覧を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Query Parameters

| Name | Type | Description |
|------|------|-------------|
| keyword | string | 名前・フリガナ・電話番号・メールアドレスを横断検索 |
| page | integer | ページ番号 |
| per_page | integer | 取得件数 |
| sort | string | 並び替え |
| gender | integer | 性別 |
| visited_after | date | 来店日（以降） |
| visited_before | date | 来店日（以前） |

### Response

200 OK

### Notes

- キーワード検索は部分一致
- ページネーション対応

---

## POST /customers

### Purpose

顧客を登録する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Request

| Field | Type | Required |
|--------|------|----------|
| name | string | ✓ |
| kana | string | ✓ |
| gender | integer | |
| birthday | date | |
| phone | string | |
| email | string | |
| memo | text | |

### Response

201 Created

---

## GET /customers/{id}

### Purpose

顧客詳細を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

---

## PUT /customers/{id}

### Purpose

顧客情報を更新する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

---

## DELETE /customers/{id}

### Purpose

顧客を削除する（Soft Delete）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner |

### Response

204 No Content

---

# Records

本グループのエンドポイントは、契約プランに**カルテ管理**（`medical_record`）が含まれない場合すべて 403 を返す（前述の「Plan / Feature 制限」参照）。`POST /records/{record}/summarize` はさらに**AI要約**（`ai_summary`）を必要とする。

## GET /records

### Purpose

サロン全体のカルテ一覧を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Query Parameters

| Name | Type | Description |
|------|------|-------------|
| status | string | draft / completed |
| keyword | string | 顧客の氏名・フリガナを横断検索 |
| page | integer | ページ番号 |
| per_page | integer | 取得件数（既定20、最大100） |

### Response

200 OK

### Notes

- キーワード検索は部分一致
- visited_at 降順で返す
- ページネーション対応
- 論理削除済み顧客のカルテは含まない
- 一覧では blocks / photos を含まない

### Errors

| Code | 条件 |
|------|------|
| 401 | 未認証 |
| 403 | 契約プランにカルテ管理が含まれない |
| 422 | status が enum 外 / per_page が範囲外 |

---

## GET /customers/{customer}/records

### Purpose

顧客のカルテ一覧を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

---

## POST /customers/{customer}/records

### Purpose

カルテを登録する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| visited_at | datetime | ✓ | 来店日時 |
| status | string | ✓ | draft / completed |
| blocks | array | ✓ | カルテブロックの配列 |

#### blocks 要素

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| label | string | ✓ | 項目名（例：薬剤、放置時間） |
| content | string | ✓ | 入力内容 |
| sort_order | integer | ✓ | 表示順 |

### Response

201 Created

---

## GET /records/{id}

### Purpose

カルテ詳細を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

---

## PUT /records/{id}

### Purpose

カルテを更新する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

---

## DELETE /records/{id}

### Purpose

カルテを削除する（Soft Delete）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Response

204 No Content

---

## POST /records/{record}/summarize

### Purpose

カルテのテキストブロックをAIで要約し、`records.ai_summary` へ保存する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Notes

- 要約対象は内容のあるテキストブロックのみ（写真・日付・ステータス・内容が空のブロックは対象外）
- ボタン押下時のみ生成・保存する（自動生成はしない）
- 要約はDBに保持し、以降のGETでそのまま返す

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランにカルテ管理またはAI要約が含まれない |
| 422 | 要約対象のテキストが無い |

### Response

```json
{
  "data": {
    "summary": "前回よりカラーの色味を落ち着かせ..."
  }
}
```

---

# Photos

本グループのエンドポイントは、契約プランに**写真管理**（`photo`）が含まれない場合すべて 403 を返す（前述の「Plan / Feature 制限」参照）。

## POST /records/{record}/photos

### Purpose

カルテ写真をアップロードする。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Content-Type

multipart/form-data

### Request

| Field | Type | Required |
|--------|------|----------|
| image | file | ✓ |
| caption | string | |

### Response

201 Created

---

## DELETE /photos/{id}

### Purpose

写真を削除する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Response

204 No Content

---

# Menus

本グループのエンドポイントは、契約プランに**予約管理**（`reservation`）が含まれない場合すべて 403 を返す（前述の「Plan / Feature 制限」参照）。メニューは施術時間・料金の定義であり予約と公開Web予約からのみ参照されるため、予約と同じ機能として扱う。

## GET /menus

### Purpose

メニュー一覧を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Query Parameters

| Name | Type | Description |
|------|------|-------------|
| is_active | boolean | true 指定で有効なメニューのみ取得 |

### Response

```json
{
  "data": [
    {
      "id": 1,
      "name": "カット",
      "price": 5500,
      "duration_minutes": 60,
      "display_order": 1,
      "is_active": true,
      "created_at": "2026-07-14T10:00:00+09:00",
      "updated_at": "2026-07-14T10:00:00+09:00"
    }
  ]
}
```

### Notes

- display_order 昇順（同値は id 昇順）で返す
- ページネーションなし

---

## POST /menus

### Purpose

メニューを登録する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| name | string | ✓ | メニュー名（最大100文字） |
| price | integer | ✓ | 税込価格（円）0〜9,999,999 |
| duration_minutes | integer | ✓ | 施術時間（分）5〜480 |
| display_order | integer | | 表示順（省略時はサーバが同一サロン内の max(display_order)+1 を採番） |
| is_active | boolean | | 有効フラグ（省略時 true） |

### Response

201 Created

---

## GET /menus/{id}

### Purpose

メニュー詳細を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

---

## PUT /menus/{id}

### Purpose

メニューを更新する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Notes

- duration_minutes を変更しても、既存予約の end_at は変わらない

---

## DELETE /menus/{id}

### Purpose

メニューを削除する（Soft Delete）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Notes

- 既存予約の menu_id は残る（予約側の表示は保持される）

### Response

204 No Content

---

# Business Hours

## GET /business-hours

### Purpose

曜日別の営業時間を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

```json
{
  "data": [
    {
      "day_of_week": 0,
      "is_closed": false,
      "open_time": "09:00",
      "close_time": "19:00"
    }
  ]
}
```

### Notes

- 常に7件（day_of_week 0=日曜〜6=土曜）を返す
- DBに行が存在しない曜日は「09:00〜19:00 営業（is_closed=false）」のデフォルト値を返す
- レスポンスに id は含めない（デフォルト曜日は未保存のため）

---

## PUT /business-hours

### Purpose

営業時間を7曜日分まとめて更新する（一括置換）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| business_hours | array | ✓ | ちょうど7件（day_of_week 0〜6 が各1件） |

#### business_hours 要素

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| day_of_week | integer | ✓ | 0=日曜〜6=土曜 |
| is_closed | boolean | ✓ | 定休日フラグ |
| open_time | string | ✓ | `HH:MM` 形式 |
| close_time | string | ✓ | `HH:MM` 形式。open_time より後 |

### Response

200 OK（GETと同形式で7件を返す）

### Errors

| Code | 条件 |
|------|------|
| 422 | 7件でない / day_of_week が重複 / close_time が open_time 以前 |

---

# Reservations

本グループのエンドポイントは、契約プランに**予約管理**（`reservation`）が含まれない場合すべて 403 を返す（前述の「Plan / Feature 制限」参照）。

## GET /reservations

### Purpose

期間内の予約一覧を取得する（カレンダー用）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Query Parameters

| Name | Type | Description |
|------|------|-------------|
| from | date | 期間開始日（YYYY-MM-DD）。省略時は当日（JST） |
| to | date | 期間終了日（YYYY-MM-DD）。省略時は from と同日 |
| user_id | integer | 担当スタッフで絞り込み |
| status | string | reserved / visited / cancelled / no_show |

### Response

```json
{
  "data": [
    {
      "id": 1,
      "customer": { "id": 1, "name": "山田 花子", "kana": "ヤマダ ハナコ", "phone": "09012345678" },
      "menu": { "id": 1, "name": "カット", "price": 5500, "duration_minutes": 60, "is_active": true },
      "user": { "id": 1, "name": "田中 美咲" },
      "start_at": "2026-07-14T10:00:00+09:00",
      "end_at": "2026-07-14T11:00:00+09:00",
      "status": "reserved",
      "source": "staff",
      "note": null,
      "created_at": "2026-07-13T18:00:00+09:00",
      "updated_at": "2026-07-13T18:00:00+09:00"
    }
  ]
}
```

### Notes

- from / to は Asia/Tokyo の日付境界で解釈する（`[from 00:00, to 24:00)` JST）
- source はフェーズ2で追加（staff=サロン側で登録、web=公開Web予約。予約カレンダーのWeb予約バッジ表示に使用可）
- start_at 昇順で返す
- ページネーションなし

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランに予約管理が含まれない |
| 422 | to が from より前 / 期間が31日を超える / 日付形式不正 |

---

## POST /reservations

### Purpose

予約を登録する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| customer_id | integer | ✓ | 顧客ID（自サロン） |
| menu_id | integer | ✓ | メニューID（自サロン・is_active=true） |
| user_id | integer | ✓ | 担当スタッフID（自サロン・is_active=true） |
| start_at | datetime | ✓ | 予約開始日時（ISO 8601 オフセット付き）。過去日時も可 |
| note | string | | メモ（最大2000文字） |

### Notes

- end_at はリクエストで受け取らず、`start_at + menu.duration_minutes` からサーバが導出する
- 同一担当スタッフの reserved / visited の予約と時間帯が重なる場合は 422（メッセージ: `指定した時間帯は既に予約が入っています。`）
- 営業時間外・定休日でも登録可能（手動予約はブロックしない）

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランに予約管理が含まれない |
| 422 | ダブルブッキング / 無効なメニュー / バリデーションエラー |

### Response

201 Created

---

## GET /reservations/{id}

### Purpose

予約詳細を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

---

## PATCH /reservations/{id}

### Purpose

予約を更新する（日時・担当・メニュー・メモ・ステータス）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| customer_id | integer | | 顧客ID |
| menu_id | integer | | メニューID（変更する場合は is_active=true のみ） |
| user_id | integer | | 担当スタッフID |
| start_at | datetime | | 予約開始日時 |
| status | string | | reserved / visited / cancelled / no_show |
| note | string | | メモ |

### Notes

- start_at または menu_id を変更した場合、end_at を再計算する
- ステータス遷移の制限はない（キャンセル＝status を cancelled に変更）
- ダブルブッキング判定は POST と同様（自身のレコードは判定から除外）

---

## DELETE /reservations/{id}

### Purpose

予約を削除する（Soft Delete）。誤登録の取り消し用途で、キャンセルとは区別する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Response

204 No Content

---

# Users

## GET /users

### Purpose

自サロンの在籍スタッフ一覧を取得する（予約の担当者選択用）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

```json
{
  "data": [
    {
      "id": 1,
      "name": "田中 美咲",
      "role": "staff"
    }
  ]
}
```

### Notes

- is_active=true のスタッフのみ返す
- 返却項目は id / name / role のみ
- id 昇順・ページネーションなし

---

# Subscription

サブスクリプション契約の確認・開始・変更・解約API（[ADR-029](../decisions/ADR-029-subscription-billing.md) 参照）。決済・請求の Source of Truth は Stripe に置き、アプリは機能制御に必要な項目だけを同期する。

本グループはプラン制限の対象外（契約が切れていても開ける。塞ぐと再契約できなくなるため）。

## 共通事項

### 二重契約を防ぐ2段構え

Checkout 完了から Webhook 到着までの数秒、アプリDBは「未契約」のままになる。この窓で
2本目の契約が成立すると、アプリから見えないまま二重に課金される。

1. `POST /subscription/sync-checkout` … 戻り先URLの `session_id` を渡すと、Stripe から結果を
   取り直して即座に反映する。他サロンのセッションを渡された場合は 403。
2. `POST /subscription/checkout` … 下記のとおり Stripe 側も確認する（初回契約は Customer が
   まだ無いため 1 が担う）。

### 二重契約を防ぐため Stripe 側も確認する

`POST /subscription/checkout` は、DBの契約状態に加えて Stripe 側の Customer に有効な
サブスクリプションが無いかも確認する。Checkout 完了から Webhook 到着までの数秒はDBが
「未契約」のままであり、その窓で2本目の契約が成立すると二重課金になるため。
有効な契約が見つかった場合はそれをDBへ取り込んだうえで 422 を返す。

### 契約を動かせるのはオーナーとマネージャーだけ

契約状態の**参照**（`GET /subscription`）は全ロールに開く（プラン制限の理由を知る必要があるため）。一方で契約開始・プラン変更・解約・解約取消・カスタマーポータルの発行は `owner` / `manager` に限り、`staff` には 403 を返す。一般スタッフの操作でサロンの請求が変わらないようにするためで、Googleカレンダーの連携モード変更と同じ扱いとする。

### Price ID をクライアントから受け取らない

契約の開始・プラン変更で受け取るのは**アプリのプランキー（`lite` / `standard` / `pro`）のみ**とし、Stripe の Price ID をリクエストで受け取らない。Price ID はサーバが `config/billing.php` から引くため、クライアントが指定した値が Stripe へ渡ることはない（任意の価格での契約を防ぐ）。

### カード情報は Backend に到達しない

Stripe Checkout / カスタマーポータルへの**リダイレクト方式**を採用し、Stripe.js / Elements は導入しない。カード番号・有効期限・セキュリティコードは Stripe がホストする画面で入力され、**Backend には一切到達しない**。DBにもカード情報は保存せず、保持するのは `stripe_customer_id` / `stripe_subscription_id` のみ。

### DEV は Test Mode / 本番は Live Mode

`APP_ENV=local` は Stripe の Test Mode（`sk_test_` / Test Mode の price / Test の webhook secret）、`APP_ENV=production` は Live Mode（`sk_live_` / Live Mode の price / Live の webhook secret）を使う。すべての Stripe API 呼び出しの手前でキーのモードと実行環境を突き合わせ、取り違えていれば例外にする（500）。事前診断は `php artisan stripe:check`（秘密鍵は表示せず、モードと設定の有無のみ出力する）。

### 契約状態と利用可否

`status` は Stripe の値をそのまま保持する（trialing / active / past_due / canceled / unpaid / incomplete / incomplete_expired / paused）。機能を利用できるのは **trialing / active / past_due** の3状態（前述の「Plan / Feature 制限」参照）。

### 反映経路

契約内容の反映は**フロントの申告ではなく Stripe Webhook（後述の「Stripe Webhook」）でのみ**行う。プラン変更・解約・解約取消の各APIは Stripe への操作結果をその場で同期して返すが、その後の状態変化（支払い失敗・期間終了・カード更新）は Webhook 経由で届く。

---

## GET /subscription

### Purpose

自サロンの契約状況・利用できる機能・プランカタログを取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

```json
{
  "data": {
    "subscription": {
      "plan": "standard",
      "plan_label": "Standard",
      "monthly_price": 1980,
      "status": "active",
      "status_label": "利用中",
      "is_active": true,
      "needs_payment_attention": false,
      "cancel_at_period_end": false,
      "current_period_start": "2026-09-01T00:00:00+00:00",
      "current_period_end": "2026-10-01T00:00:00+00:00",
      "canceled_at": null,
      "ended_at": null,
      "trial_ends_at": null,
      "has_payment_method": true,
      "is_subscribed": true
    },
    "plan": "standard",
    "features": {
      "customer": true,
      "medical_record": true,
      "photo": true,
      "reservation": true,
      "google_calendar": true,
      "line": true,
      "ai_summary": false,
      "analytics": false
    },
    "plans": [
      {
        "code": "lite",
        "label": "Lite",
        "monthly_price": 980,
        "features": ["customer", "medical_record", "photo"],
        "is_purchasable": true
      },
      {
        "code": "standard",
        "label": "Standard",
        "monthly_price": 1980,
        "features": ["customer", "medical_record", "photo", "reservation", "google_calendar", "line"],
        "is_purchasable": true
      },
      {
        "code": "pro",
        "label": "Pro",
        "monthly_price": 3980,
        "features": ["customer", "medical_record", "photo", "reservation", "google_calendar", "line", "ai_summary", "analytics"],
        "is_purchasable": true
      }
    ]
  }
}
```

### Notes

- 契約が無い場合は `subscription: null` / `plan: null`、`features` は全 false を返す（404 にはしない。プラン選択画面の初期表示用）
- `plan` は**現在有効なプラン**。`subscription.plan` に値があっても、状態が利用を許さない（canceled / unpaid など）場合は null になる
- `subscription.is_active` は利用可否（trialing / active / past_due で true）、`needs_payment_attention` は支払いの注意喚起が必要な状態（past_due / unpaid / incomplete）
- `status_label` / `plan_label` は画面表示用の日本語ラベル（トライアル中 / 利用中 / お支払い確認中 / 解約済み / 利用停止中 / お支払い手続き未完了 / お支払い手続き期限切れ / 一時停止中）
- Stripe の識別子（stripe_customer_id / stripe_subscription_id）はレスポンスに含めず、導線の出し分けに使う真偽値（`has_payment_method` / `is_subscribed`）のみ返す
- `plans` はプランカタログ（`config/billing.php`）の全プラン。`is_purchasable` は Stripe の Price ID が設定されているか
- 期間の日時は保存値（UTC）をそのまま ISO 8601 で返す
- **保存済みの値のみを返し、Stripe API は呼ばない**

---

## POST /subscription/checkout

### Purpose

契約を開始するための Stripe Checkout セッションを作成し、リダイレクト先URLを返す。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| plan | string | ✓ | プランキー（lite / standard / pro）。Stripe の Price ID は受け取らない |

### Notes

- Price ID はサーバが `config/billing.php` から引く。クライアントが指定した値が Stripe へ渡ることはない
- 既存の stripe_customer_id があれば必ず再利用する（1サロン1 Customer）。未作成の場合はログインユーザーのメールアドレスを Checkout に渡す
- `metadata` と `subscription_data.metadata` に salon_id / plan を、`client_reference_id` に salon_id を載せ、Webhook でサロンを解決できるようにする
- 戻り先は `success_url` = `{FRONTEND_URL}{BILLING_RETURN_PATH}?checkout=success&session_id={CHECKOUT_SESSION_ID}` / `cancel_url` = `{FRONTEND_URL}{BILLING_RETURN_PATH}?checkout=cancel`（BILLING_RETURN_PATH の既定は `/settings/plan`）
- カード情報は Stripe がホストする画面で入力され、Backend には到達しない
- **契約内容がDBへ反映されるのは checkout.session.completed の Webhook 受信時**。決済直後のリダイレクト戻りの時点では未反映のことがある

### Errors

| Code | 条件 |
|------|------|
| 422 | plan が lite / standard / pro 以外 / すでに契約中（プラン変更を使う） |
| 500 | Stripe の設定不備（Price ID 未設定・Live/Test の取り違え）/ Stripe API エラー |

### Response

```json
{
  "data": {
    "url": "https://checkout.stripe.com/c/pay/cs_test_xxxxxxxx"
  }
}
```

---

## POST /subscription/portal

### Purpose

支払い方法の変更・請求履歴の確認を行う Stripe カスタマーポータルのセッションを作成し、リダイレクト先URLを返す。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Notes

- 支払い方法・請求書・領収書はアプリで扱わず、すべて Stripe の画面に委ねる
- 戻り先は `{FRONTEND_URL}{BILLING_RETURN_PATH}?checkout=portal`
- リクエストボディは不要

### Errors

| Code | 条件 |
|------|------|
| 422 | Stripe Customer が未作成（まだ一度も契約していない） |
| 500 | Stripe の設定不備 / Stripe API エラー |

### Response

```json
{
  "data": {
    "url": "https://billing.stripe.com/p/session/xxxxxxxx"
  }
}
```

---

## POST /subscription/change-plan

### Purpose

契約中のプランを変更する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| plan | string | ✓ | 変更先のプランキー（lite / standard / pro） |

### Notes

- Stripe の subscription item の price を差し替える（`proration_behavior: create_prorations` / `payment_behavior: error_if_incomplete`）
- **即時反映・日割り精算は Stripe に委ねる**。アプリ側で請求金額を計算することはしない。ダウングレードの差額は次回請求へクレジットとして繰り越される
- 変更結果はレスポンスに反映済み。あわせて customer.subscription.updated の Webhook でも同期される
- ダウングレードでデータは削除しない。LINE連携情報・Googleカレンダー接続も保持し、再アップグレードでそのまま復帰する（能動的な解除処理は行わない）

### Errors

| Code | 条件 |
|------|------|
| 422 | plan が lite / standard / pro 以外 / 契約が無い / すでに同じプランを契約中 |
| 500 | Stripe の設定不備 / Stripe API エラー（支払いが未完了で確定できない場合を含む） |

### Response

200 OK（`GET /subscription` の `subscription` と同形式）

---

## POST /subscription/cancel

### Purpose

解約を申請する（期間終了時に停止する）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Notes

- Stripe の `cancel_at_period_end` を true にする。**即時停止はしない**（期間終了までは利用できる）
- 期間終了で Stripe が status を canceled にし、Webhook 経由で利用停止になる
- **解約しても顧客・カルテ・写真は一切削除しない。** LINE連携情報・Googleカレンダー接続も保持し、再契約時にそのまま復帰できる
- リクエストボディは不要

### Errors

| Code | 条件 |
|------|------|
| 422 | 契約が無い / すでに解約を申請済み |
| 500 | Stripe API エラー |

### Response

200 OK（`GET /subscription` の `subscription` と同形式。cancel_at_period_end=true）

---

## POST /subscription/resume

### Purpose

解約申請を取り消して契約を継続する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Notes

- Stripe の `cancel_at_period_end` を false に戻す
- 期間終了後（status=canceled）は対象外。`POST /subscription/checkout` で契約し直す
- リクエストボディは不要

### Errors

| Code | 条件 |
|------|------|
| 422 | 契約が無い / 解約が申請されていない |
| 500 | Stripe API エラー |

### Response

200 OK（`GET /subscription` の `subscription` と同形式。cancel_at_period_end=false）

---

# LINE Settings

本グループのエンドポイントは、契約プランに**LINE連携**（`line`）が含まれない場合すべて 403 を返す（前述の「Plan / Feature 制限」参照）。プランのダウングレードでも保存済みの認証情報・顧客の連携状態は削除せず、再アップグレードでそのまま復帰する。

## GET /line-settings

### Purpose

自サロンのLINE連携設定・接続状態・webhook URLを取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

```json
{
  "data": {
    "configured": true,
    "channel_id": "1234567890",
    "channel_secret": "****7f3a",
    "channel_access_token": "****Qz8k",
    "bot_user_id": "U4af4980629abcdef1234567890abcdef",
    "bot_basic_id": "@123abcd",
    "bot_display_name": "Realize Beauty 表参道",
    "is_active": true,
    "connected_at": "2026-07-15T10:00:00+09:00",
    "last_webhook_at": "2026-07-15T12:34:56+09:00",
    "webhook_url": "https://example.com/api/line/webhook"
  }
}
```

### Notes

- channel_secret / channel_access_token はマスク表示（末尾4文字のみ）。平文はレスポンスに含めない
- 認証情報が未登録でも 404 にせず、`configured: false`（認証情報系フィールドは null）+ webhook_url を返す
- webhook_url は `{APP_URL}/api/line/webhook`（全サロン共通）
- bot_display_name は接続確認時に bot info の displayName から取得し、設定画面で常時表示する
- last_webhook_at は**署名検証成功時のみ**更新し、設定画面に「最終Webhook受信」として表示する（channel_secret が正しいことの確認手段）

---

## PUT /line-settings

### Purpose

LINE Messaging API の認証情報を保存する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| channel_id | string | ✓ | Messaging API チャネルID |
| channel_secret | string | ✓ | チャネルシークレット |
| channel_access_token | string | ✓ | 長期チャネルアクセストークン |

### Notes

- 1サロン1設定の upsert（既存設定があれば上書き）
- channel_secret / channel_access_token はDBに暗号化保存する（Laravel `encrypted` cast）
- channel_secret / channel_access_token を変更した場合は is_active=false に戻し、再度の接続確認を要求する（未検証の認証情報で「接続済み」表示のまま稼働することを防ぐ。is_active は接続確認でのみ true になる）

### Response

200 OK（GETと同形式。secret / token はマスク表示）

---

## POST /line-settings/verify

### Purpose

保存済みの認証情報でLINEとの接続を確認する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Notes

- LINE の GET /v2/bot/info を呼び出し、成功時は bot_user_id / bot_basic_id / bot_display_name を保存、is_active=true・connected_at を記録する
- GET /v2/bot/info で検証できるのは channel_access_token のみ。channel_secret / channel_id の正しさはこの確認では検証されず、実際の webhook 受信（last_webhook_at の更新）でのみ確認できる（設定手順ガイドで「設定後にトークへテストメッセージを送り、最終Webhook受信が更新されることを確認」するよう案内する）
- リクエストボディは不要

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランにLINE連携が含まれない |
| 404 | 認証情報が未登録 |
| 422 | 接続確認失敗（channel_access_token 不正・LINE API エラー。channel_secret / channel_id の誤りはここでは検出できない） |

### Response

200 OK（GETと同形式。secret / token はマスク表示）

---

## DELETE /line-settings

### Purpose

LINE連携を解除する（物理削除。SoftDelete ではない）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Notes

- あわせて当該サロンの customers の line_user_id / line_linked_at / line_link_code / line_link_code_expires_at を一括クリアする（LINE の userId はチャネルのプロバイダー単位スコープのため、別チャネル再接続後は無効になる）
- UI の確認ダイアログにこの影響を明記する

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランにLINE連携が含まれない |
| 404 | 認証情報が未登録 |

### Response

204 No Content

---

# Booking Page

本エンドポイントは、契約プランに**予約管理**（`reservation`）が含まれない場合 403 を返す（前述の「Plan / Feature 制限」参照）。

## GET /booking-page

### Purpose

自サロンのWeb予約ページ情報（booking_slug と公開URL）を取得する（設定画面のURL表示・コピー用）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

```json
{
  "data": {
    "booking_slug": "a1b2c3d4e5f6g7h8",
    "booking_page_url": "https://example.com/booking/a1b2c3d4e5f6g7h8"
  }
}
```

### Notes

- booking_slug は salons テーブルの値（16文字英数小文字ランダム。新規サロンは Salon モデルの creating フックで自動生成）
- slug の再生成（ローテーション）APIはフェーズ2スコープ外（backlog）。当面はサポートによる手動更新とする

---

# Google Calendar

Googleカレンダー双方向同期の設定API（フェーズ3で追加。[ADR-025](../decisions/ADR-025-google-calendar-sync.md) 参照）。

1接続 = 1 Google アカウントの1カレンダーとし、同一カレンダーに対して書き込み（RB の予約）と読み取り（RB 以外の予定 = busy）の両方を行う。

本グループのエンドポイントは、契約プランに**Googleカレンダー連携**（`google_calendar`）が含まれない場合すべて 403 を返す（認証なしの `GET /google-calendar/callback` を除く。前述の「Plan / Feature 制限」参照）。プランのダウングレードでも接続情報は削除せず、同期を止めるだけとする（再アップグレードでそのまま復帰する）。

## 共通事項

### calendar_id とリテラル `primary`

`calendar_id` の既定値であるリテラル `primary` は、Google の events / calendarList の各 API が**カレンダーIDの代わりに解釈するエイリアス**（予約語）であり、`calendarList.list` が返す id ではない。calendarList.list はメインカレンダーの id を**アカウントのメールアドレス**で返し、メインかどうかは別フィールドの `primary: true` で示す。

RB は既定値としてリテラル `primary` をそのまま保存し、実IDへの正規化は行わない。よって:

- `PUT /google-calendar/connections/{id}` の calendar_id は **リテラル `primary` または calendarList.list が返す id** のいずれかを受け付ける（リテラルを弾かない）
- 選択UIで現在値を照合する際、calendar_id が `primary` なら `primary: true` のエントリが現在の選択に相当する

### 同期窓

**同期窓**は salon_timezone 基準で「現在 〜 **本日+60日の終日終端**（= **本日+61日 00:00 JST**）」とする。RB の予約可能範囲（「本日+60日後の終日まで」。[booking.md](../requirements/booking.md) Business Rules 2）と揃えるため、日付境界で定義する（「現在+60日」という壁時計オフセットは採用しない。最終日の一部が範囲外となり、その枠だけ busy 化されないまま公開Web予約で空きとして売られるため）。

全同期の `timeMax` にはこの終端を用い、busy ブロック・初回送信同期の対象範囲もこの窓に一致させる。`syncToken` は `timeMin` / `timeMax` 等と**併用できない**ため増分同期では窓を動かせず、窓の前進には全同期が必要になる。全同期の契機は4つ（初回接続 / 対象カレンダー変更 / 410 Gone / 日次の同期窓前進〈定期コマンド `google-calendar:refresh-sync`〉）。詳細は [google-calendar.md](../requirements/google-calendar.md) を正とする。

### reservations.google_event_id の無効化

Google のイベントIDは**カレンダー単位のスコープ**であり、グローバルに一意ではない。対象カレンダーが変わる・接続が削除される・別アカウントで再接続される経路では、旧イベントIDは新カレンダーに存在せず events.update / delete が 404 になる。よって以下の各経路で、当該接続の対象範囲の予約（per_staff は当該スタッフ担当、shared はサロン全体）の `google_event_id` を **null にクリアする**:

| 経路 | 契機 |
|------|------|
| `PUT /google-calendar/connections/{id}` | 対象カレンダーの変更 |
| `DELETE /google-calendar/connections/{id}` | 接続の解除 |
| `PUT /google-calendar/mode` | モード切替に伴う全接続の解除 |
| `GET /google-calendar/callback` | 再接続時に google_account_email または calendar_id が変わる場合 |

あわせて送信同期ジョブは、上記のクリアを取りこぼした場合の自己修復として次のエラーハンドリングを行う（詳細は [ADR-025](../decisions/ADR-025-google-calendar-sync.md)）:

- `events.update` が **404 / 410** → `google_event_id` を null にして **insert にフォールバック**する（tries を消化して恒久失敗させない）
- `events.delete` が **404 / 410** → 既に存在しないため **成功扱い**（冪等）とし、`google_event_id` を null にする

## GET /google-calendar

### Purpose

自サロンのGoogleカレンダー連携設定（モード + 接続一覧）を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

```json
{
  "data": {
    "mode": "per_staff",
    "connections": [
      {
        "id": 1,
        "user": { "id": 1, "name": "田中 美咲" },
        "google_account_email": "misaki.tanaka@example.com",
        "calendar_id": "primary",
        "status": "active",
        "last_synced_at": "2026-07-17T12:34:56+09:00"
      }
    ]
  }
}
```

### Notes

- トークン類（access_token / refresh_token）・同期内部状態（sync_token / channel_token 等）はマスク表示も含め一切レスポンスに含めない（DBには `encrypted` cast で暗号化保存する）
- モードが未設定でも 404 にせず、`mode: null` + `connections: []` を返す（設定画面の初期表示用）
- user は per_staff モードでは接続したスタッフ、shared モード（サロン共有接続）では null
- connections は id 昇順。shared モードでは 0 件または 1 件
- status=needs_reconnect は refresh_token の失効・取り消し（ユーザーが Google 側でアクセス解除）を示す。同期ジョブはリトライせず打ち切られるため、UI で再接続を促す
- **保存済みの値のみを返し、Google API は呼ばない**（設定画面を開くたびに接続数ぶんの外部API呼び出しが走るのを避ける。NFR「API クォータへの配慮」）。status=needs_reconnect の接続は Google を呼べないため、この点でもレスポンスは保存値だけで組み立てられる必要がある
- `calendar_id` はリテラル `primary`（メインカレンダーのエイリアス）か、calendarList.list が返す実IDのいずれか。カレンダー名（calendarList の summary）は保持しないため返さない（UI は `primary` を「メインカレンダー（{google_account_email}）」と表示し、それ以外は calendar_id を表示する）
- `google_account_email` は接続時に calendarList.list の `primary: true` のエントリの `id` から取得して保存した値（表示用のみ。認可・照合には使わない）

---

## GET /google-calendar/busy-blocks

### Purpose

期間内の外部予定（busy ブロック）を取得する（予約カレンダーの「外部予定」表示用）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Query Parameters

| Name | Type | Description |
|------|------|-------------|
| from | date | 期間開始日（YYYY-MM-DD）。省略時は当日（JST） |
| to | date | 期間終了日（YYYY-MM-DD）。省略時は from と同日 |

### Response

```json
{
  "data": [
    {
      "id": 1,
      "start_at": "2026-07-18T13:00:00+09:00",
      "end_at": "2026-07-18T14:00:00+09:00",
      "user_id": 1
    }
  ]
}
```

### Notes

- from / to は **GET /reservations と同一の指定**（salon_timezone の日付境界で `[from 00:00, to 24:00)` として解釈する）。予約カレンダーは同じ期間で本APIと GET /reservations を並行して呼ぶ
- **返すのは `id` / `start_at` / `end_at` / `user_id` のみ**で、タイトル・説明・出席者等の**内容は一切返さない**。busy ブロックはそれらを保存していないため（プライバシー配慮。[ADR-025](../decisions/ADR-025-google-calendar-sync.md) §7）、返せる情報が原理的に存在しない（`id` はリソース識別子であり内容ではない）。UI は「外部予定」の固定ラベルで時刻のみ・グレー表示する
- `user_id` は busy が塞ぐスタッフ。per_staff モードは接続の所有スタッフ、**shared モードは null（＝サロン全体を塞ぐ）**
- Googleカレンダー未連携のサロン、および同期窓（現在〜salon_timezone の本日+61日 00:00）の外の期間は常に空配列（busy ブロックは同期窓内しか保持しない）
- **保存済みの値のみを返し、Google API は呼ばない**（カレンダー表示のたびに外部API呼び出しが走るのを避ける。NFR「API クォータへの配慮」）
- GET /reservations に同梱せず独立したエンドポイントとするのは、両者がスキーマも生存期間も異なり（予約は業務レコード、busy は同期窓内のキャッシュ）、予約リソースへ内容の無い行を混ぜないため
- start_at 昇順で返す。ページネーションなし

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランにGoogleカレンダー連携が含まれない |
| 422 | to が from より前 / 期間が31日を超える / 日付形式不正 |

---

## PUT /google-calendar/mode

### Purpose

接続単位のモード（per_staff / shared）を設定する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| mode | string | ✓ | per_staff / shared |

### Notes

- per_staff: 各スタッフが自分の Google アカウントを接続する。RB はそのスタッフ担当の予約のみ当該カレンダーへ書き、カレンダー上の RB 以外の予定は**そのスタッフの**空き枠を塞ぐ
- shared: オーナーが1アカウントだけ接続する。RB は**全スタッフの予約**を1本のカレンダーへ書き（イベント題名に担当スタッフ名を含める）、カレンダー上の RB 以外の予定は**サロン全体（全スタッフ）の**空き枠を塞ぐ
- **現在と異なるモードへ変更する場合、既存の接続をすべて解除する**（各接続で channels.stop → refresh_token の **revoke** → busy ブロック削除 → 対象範囲の予約の `google_event_id` を null にクリア → 接続の物理削除の5手順。channels.stop / revoke の失敗はログのみで続行する〈best-effort〉。DELETE /google-calendar/connections/{id} と同じ副作用セット）。2つのモードは接続の所有者（user_id の有無）と busy の適用範囲が異なり、接続を引き継げないため。UI の確認ダイアログにこの影響を明記する
- google_event_id をクリアするのは、モード切替後に別カレンダーへ接続し直したとき、旧イベントIDが指す先が存在せず（**Google のイベントIDはカレンダー単位のスコープ**）送信同期が 404 で恒久的に失敗するため
- 現在と同一モードの指定は何もしない（解除も行わない）
- salons.google_calendar_mode に保存する（null = 未設定）

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランにGoogleカレンダー連携が含まれない |
| 422 | mode が per_staff / shared 以外 |

### Response

200 OK（GET /google-calendar と同形式。モード変更時の connections は空配列）

---

## POST /google-calendar/auth-url

### Purpose

Google OAuth（認可コードフロー）を開始し、認可URLを取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff（shared モードは owner, manager のみ） |

### Response

```json
{
  "data": {
    "auth_url": "https://accounts.google.com/o/oauth2/v2/auth?client_id=...&state=..."
  }
}
```

### Notes

- 推測不能なランダム値 state を発行し、キャッシュへ `state → {salon_id, user_id, mode}` を **TTL 10分**で保存してから認可URLを返す（SPA はこのURLへブラウザを遷移させる）
- **キャッシュキーは state の生値ではなく `google_oauth_state:{state}` の接頭辞付きとする**（`CACHE_STORE=database` で全用途のキーが同一テーブルに同居するため、他用途のキーとの衝突〈型の異なる値の復元〉を避ける）
- state をキャッシュで持つのは、コールバックが Google からのブラウザリダイレクトで Bearer トークンを持たないため（SPA〈Cloudflare Pages〉と API〈Render〉が別オリジンである前提）
- キャッシュに保存する user_id はモードで決まる: per_staff は認証ユーザーのID、shared は null（サロン共有接続）
- scope は `https://www.googleapis.com/auth/calendar.events`（RB 予約の読み書き）と `https://www.googleapis.com/auth/calendar.calendarlist.readonly`（カレンダー一覧取得）
- refresh_token を確実に取得するため `access_type=offline` + `prompt=consent` を付与する
- redirect_uri は **API 側**の `{API_URL}/api/v1/google-calendar/callback`（client_secret をサーバ側で扱うため、SPA ではなく API に戻す）
- リクエストボディは不要

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランにGoogleカレンダー連携が含まれない |
| 422 | モードが未設定（先に PUT /google-calendar/mode が必要） |

---

## GET /google-calendar/callback

### Purpose

Google OAuth のコールバックを受け、トークン交換・接続保存・watch 開始・初回同期投入を行い、SPA へリダイレクトする。

### Access

| Item | Value |
|------|-------|
| Authentication | 不要（state による検証） |
| Roles | - |

### Query Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| code | string | | 認可コード（同意された場合に Google が付与。error 付きで戻る場合は無い） |
| state | string | ✓ | auth-url が発行しキャッシュへ保存した値（TTL 10分・単回使用）。`{salon_id, user_id, mode}` の復元キー兼 CSRF 対策 |
| error | string | | Google 側のエラー（同意画面で拒否された場合の access_denied 等） |

### Notes

- **認証不要である理由**: Google 同意画面からのブラウザリダイレクトであり Bearer トークンを付けられない。`/api/v1` プレフィックス配下だが auth:sanctum の対象外とし、代わりに state（キャッシュ照合・単回使用）で文脈と正当性を検証する
- **レスポンスは JSON ではなく SPA への 302 リダイレクト**（ブラウザ遷移の終端のため）
- 処理順: (1) state をキャッシュ（`google_oauth_state:{state}`）から引いて `{salon_id, user_id, mode}` を復元（照合後は即削除＝単回使用。不一致・期限切れ・未知は接続せずエラーとして SPA へ戻す） (2) code を access_token / refresh_token に交換（client_secret はサーバ側のみで扱う） (3) calendarList.list を呼び `primary: true` のエントリの `id` を google_account_email として取得 (4) 接続を保存（トークンは `encrypted` cast で暗号化保存・calendar_id はリテラル primary・status=active） (5) 対象カレンダーへ watch チャネルを開始（id / token / address を指定し resourceId / expiration を保存） (6) **初回同期は受信・送信の両方**のジョブを投入
- **(6) の初回同期の内訳**:
  - **受信** = syncToken 無しの全同期（timeMin=現在・timeMax=**同期窓の終端 = salon_timezone の本日+61日 00:00**〈＝本日+60日の終日終端。RB の予約可能範囲と一致〉・`singleEvents=true`）による busy の取り込み。全ページを辿り、適用・コミットの後に nextSyncToken を保存する（nextSyncToken は最終ページにのみ返る）
  - **送信** = **同期窓内の status=reserved な対象予約**（per_staff は当該スタッフ担当、shared は全スタッフ）の書き出し。送信側を投入しないと、接続前に登録済みの未来の予約が Google に一切現れない。既に `google_event_id` を持つ予約は、当該イベントが対象カレンダーに存在すれば更新、存在しなければ作成し直して ID を差し替える
- **(1) では復元した mode を現在の `salons.google_calendar_mode` と再照合し、一致しない場合は接続せず `invalid_state` で SPA へ戻す**: state の TTL 10分の間に PUT /google-calendar/mode でモードが切り替わると、認可中だったリクエストが現行モードと食い違う接続（例: per_staff サロンに `user_id = null` のサロン全体接続）を作ってしまう。部分 unique 制約では弾けず、設定画面はモードに応じた行構成のためどの行にも現れず解除もできない
- (1) では per_staff の場合に限り、復元した user_id が当該サロンの is_active なユーザーであることも確認する（認可中に退職処理された場合に備える。不一致は `invalid_state`）
- `google_account_email` を calendarList.list から取得するのは、要求スコープが calendar.events + calendar.calendarlist.readonly の2つのみであり、id_token（`openid` が必要）も userinfo エンドポイント（`userinfo.email` が必要）も使えないため。**追加スコープは不要**（メインカレンダーの id がアカウントのメールアドレス）。表示用のみで、認可・照合には使わない（Google 側で変更されうるため）
- 同一 (salon_id, user_id) の接続が既にある場合は同じ行を更新する（＝再接続。部分 unique 制約 `(salon_id, user_id) WHERE user_id IS NOT NULL` / `(salon_id) WHERE user_id IS NULL` による）
- **既存行を更新する場合は (4) の前に旧接続の後始末を行う**: 旧チャネルへ `channels.stop`（失敗はログのみで続行。channel_id / channel_resource_id を上書きすると停止手段が永久に失われるため、**上書き前**に打つ）→ `sync_token` を null に → **当該接続の busy ブロックを全削除**。行を再利用するため FK の cascade delete が発火せず、受信同期は upsert のみで旧アカウント由来のイベントは二度と同期応答に現れないため、放置すると実在しない私用予定の busy が恒久的に空き枠を塞ぐ（busy はタイトルを保存しないため UI からも同定・除去できない）
- **再接続で google_account_email または calendar_id が旧値と異なる場合**は、あわせて当該接続の対象範囲の予約（per_staff は当該スタッフ担当、shared はサロン全体）の `google_event_id` を null にクリアする（旧カレンダーのイベントIDで新カレンダーへ events.update を投げると 404 になるため。旧カレンダー上のイベントは削除しない）
- リダイレクト先は成功時 `{FRONTEND_URL}/settings/google-calendar?connected=1`、失敗時 `{FRONTEND_URL}/settings/google-calendar?error={code}`
- error コード: `invalid_state`（state 不一致・期限切れ・認可中のモード変更・接続先スタッフが無効）/ `access_denied`（同意画面で拒否）/ `exchange_failed`（トークン交換失敗）/ `connect_failed`（接続保存・watch 開始の失敗）
- `FRONTEND_URL` 相当の設定値をフェーズ3で新設する（config + .env.example）。別オリジン構成でのサーバ側リダイレクトに必須

### Response

302 Found（`Location: {FRONTEND_URL}/settings/google-calendar?connected=1`）

---

## GET /google-calendar/connections/{id}/calendars

### Purpose

接続アカウントが参照できるカレンダー一覧を取得する（対象カレンダーの選択用）。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

```json
{
  "data": [
    { "id": "misaki.tanaka@example.com", "summary": "田中 美咲", "primary": true },
    { "id": "abc123@group.calendar.google.com", "summary": "サロン共有", "primary": false }
  ]
}
```

### Notes

- Google の calendarList.list から取得する（primary を先頭に、以降は summary 昇順）
- 返る id は実カレンダーID（メインカレンダーはアカウントのメールアドレス）。**リテラル `primary` はこの一覧には現れない**。接続の calendar_id が `primary` の場合、現在の選択は `primary: true` のエントリに相当するものとして選択UIで照合する
- access_token が期限切れの場合は refresh_token で更新してから呼び出す
- **接続の検索条件に salon_id と所有者条件を含める**（他サロンの接続IDは 404）
  - per_staff モードの接続（user_id IS NOT NULL）は **user_id = 認証ユーザー**の接続のみ取得できる。本エンドポイントは他人の OAuth トークンで calendarList.list を代理実行するものであり、カレンダー名には私的な情報（通院・副業等）が含まれうるため同僚には開示しない（busy ブロックにタイトルを保存しないのと同じプライバシー境界。[ADR-025](../decisions/ADR-025-google-calendar-sync.md) §7）
  - shared モードの接続（user_id IS NULL）は PUT /google-calendar/mode と同じく owner / manager のみ
  - 条件に合わない接続IDは **403 ではなく 404**（存在秘匿。403 だと接続の存在が判る）
  - UI 側（[settings-google-calendar.md](../ui/settings-google-calendar.md)）の「本人の行でのみボタンを有効化」は表示制御にすぎず認可ではない。接続IDは GET /google-calendar のレスポンスで全スタッフに配布されるため、サーバ側で同等の制約が必要

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランにGoogleカレンダー連携が含まれない |
| 404 | 自サロンかつ操作権限のある接続が存在しない |
| 422 | 接続が needs_reconnect 状態（再接続が必要）/ Google API エラー |

---

## PUT /google-calendar/connections/{id}

### Purpose

接続の同期対象カレンダー（calendar_id）を変更する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| calendar_id | string | ✓ | 変更後のカレンダーID（リテラル `primary`、または GET /google-calendar/connections/{id}/calendars の id のいずれか） |

### Notes

- **カレンダー変更に伴う副作用**: (1) **syncToken を破棄する**（カレンダーが変わると増分同期の連続性が無くなるため） (2) 旧カレンダーの **watch チャネルを channels.stop で停止し、新カレンダーへ張り直す** (3) 当該接続の **busy ブロックを全削除し、新カレンダーの内容で再構築する**（全同期ジョブを投入。timeMax は同期窓の終端 = salon_timezone の本日+61日 00:00） (4) **旧カレンダーの RB 由来イベントを削除する**（クリア前の `google_event_id` を用いる） (5) 当該接続の対象範囲の予約（per_staff は当該スタッフ担当、shared はサロン全体）の **`google_event_id` を null にクリアする** (6) **新カレンダーへ初回送信同期で書き直す**（同期窓内の status=reserved な対象予約を insert し直す）
- google_event_id をクリアするのは、**Google のイベントIDがカレンダー単位のスコープ**であり、旧カレンダーのIDのまま新カレンダーへ events.update を投げると 404 になって tries=3 を消化し、その予約の送信同期が恒久的に失敗するため（新カレンダーにその予約が現れず、キャンセル時の events.delete も 404 で失敗し続ける）
- **旧カレンダーの RB 由来イベントを削除する点が DELETE /google-calendar/connections/{id}（Google 側のイベントを残す）と異なるのは意図的**: カレンダー変更は同一アカウント内の移し替えであり、旧カレンダーにマーカー付きの孤児イベントが残ると、スタッフがそれを手動削除した際に受信同期が「RB 由来イベントの削除」と解釈して**生きた予約を cancelled にする**事故経路になる（接続解除ではそもそも受信同期が止まるため、この経路は生じない）
- **バリデーション**: calendar_id は **リテラル `primary`（メインカレンダーのエイリアス）** または **calendarList.list が返す id のいずれか**を受け付ける。どちらでもない場合は 422。calendarList.list は `primary` という id を返さない（メインカレンダーの id はアカウントのメールアドレス、メインかどうかは別フィールドの `primary: true`）ため、リテラルを明示的に許可する。リテラル `primary` と `primary: true` のエントリの実IDは同一カレンダーを指すが、**指定された値をそのまま保存し正規化は行わない**（既定値 `primary` との一貫性のため）
- 1接続 = 1カレンダー。同一カレンダーに対して書き込み（RB の予約）と読み取り（RB 以外の予定 = busy）の両方を行う
- 既定かつ推奨は primary。**専用カレンダーを選ぶと私用予定を読めなくなり busy 反映が働かない**旨を UI に明記する
- **接続の検索条件に salon_id と所有者条件を含める**（GET /connections/{id}/calendars と同じ規則）: per_staff モードの接続は user_id = 認証ユーザーのみ変更でき、shared モードの接続は owner / manager のみ。条件に合わない接続IDは 404（存在秘匿）。他スタッフの対象カレンダーを空のカレンダーへ差し替えて busy 反映を黙って無効化されることを防ぐ

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランにGoogleカレンダー連携が含まれない |
| 404 | 自サロンかつ操作権限のある接続が存在しない |
| 422 | calendar_id がリテラル primary でも calendarList の id でもない / 接続が needs_reconnect 状態 / Google API エラー |

### Response

200 OK（`{ "data": { ...接続 } }`）

---

## DELETE /google-calendar/connections/{id}

### Purpose

接続を解除する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Notes

- **副作用は次の5手順**: (1) channels.stop で watch チャネルを停止 → (2) Google の revoke エンドポイント（`https://oauth2.googleapis.com/revoke`）へ **refresh_token を送出して Google 側の grant を失効させる** → (3) 当該接続の busy ブロックを削除 → (4) 対象範囲の予約の `google_event_id` を null にクリア → (5) 接続を**物理削除**する（SoftDelete ではない。busy ブロックは FK の cascade delete でも消えるが明示的に削除する）
- **revoke を行うのは**、RB の DB から消すだけでは発行済み refresh_token が Google 側で有効なまま残り、バックアップ・ログに残った値が後から悪用され得るため（ユーザーの Google アカウントの「サードパーティ アクセス」からも RB が消える）
- **channels.stop / revoke の失敗はログのみで続行し（best-effort）、RB 側のレコード削除（3〜5）は必ず完遂する**。とくに status=needs_reconnect（refresh_token 失効・ユーザーが Google 側でアクセス解除）の接続では (1)(2) は必ず失敗するが、UI は当該状態でも「接続を解除」を提供するため解除は成功しなければならない（失敗で打ち切ると「Google 側でアクセスを取り消した接続は RB からも解除できない」デッドロックになる）
- 解除後、当該スタッフ（shared モードならサロン全体）の空き枠から外部予定由来の制約が消える
- Google カレンダー上に既に作成済みの RB 由来イベントは削除しない（サロン側の記録として残す。不要ならサロン側で手動削除）。**PUT /google-calendar/connections/{id}（旧カレンダーの RB 由来イベントを削除する）との非対称は意図的**であり、解除では受信同期も止まるため、孤児イベントの手動削除が生きた予約を cancelled にする事故経路が生じないことによる
- **`reservations.google_event_id` は null にクリアする**（対象範囲は per_staff なら当該スタッフ担当、shared ならサロン全体）。残置すると、別アカウント・別カレンダーで再接続した際に旧イベントIDのまま events.update / delete を投げて 404 になり、その予約の送信同期が恒久的に失敗する（**イベントIDはカレンダー単位のスコープ**であり、接続が消えた時点で RB 側の参照は無効になる。ERD の「未同期・対象接続なしの場合は null」とも一致する）
- **接続の検索条件に salon_id と所有者条件を含める**（GET /connections/{id}/calendars と同じ規則）: per_staff モードの接続は user_id = 認証ユーザーのみ解除でき、shared モードの接続は owner / manager のみ。条件に合わない接続IDは 404（存在秘匿）

### Errors

| Code | 条件 |
|------|------|
| 403 | 契約プランにGoogleカレンダー連携が含まれない |
| 404 | 自サロンかつ操作権限のある接続が存在しない |

### Response

204 No Content

---

# Public Booking（公開Web予約）

## API Information

| Item | Value |
|------|-------|
| Base URL | `/api/public/v1` |
| Authentication | 不要（booking_slug / booking_token をURLに含む） |
| Response Format | JSON |
| Rate Limit | 全エンドポイントにIP単位の throttle を適用（超過時 429）。予約作成は加えてサロン（booking_slug）単位 30回/分 |

> 以下のパスは `/api/public/v1` からの相対パス。

> **予約管理（`reservation`）を含まないプランのサロンでは、booking_slug 経路（サロン情報・空き枠・予約登録）は 404 を返す。** 403 にするとスラッグの実在を外部に知らせてしまうため、`is_active=false` のサロンと同じ扱いに揃える（前述の「Plan / Feature 制限」参照）。
> booking_token 経路（`GET /bookings/{booking_token}` / `POST /bookings/{booking_token}/cancel`）は**契約プランの影響を受けない**。ダウングレード前に受けた予約は、予約者が最後まで照会・キャンセルできるようにする。

---

## GET /salons/{booking_slug}

### Purpose

公開予約ページ用のサロン情報（サロン名・営業時間・有効メニュー・有効スタッフ）を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | 不要 |
| Roles | - |
| Rate Limit | 60回/分/IP |

### Response

```json
{
  "data": {
    "name": "Realize Beauty 表参道",
    "business_hours": [
      { "day_of_week": 0, "is_closed": false, "open_time": "09:00", "close_time": "19:00" }
    ],
    "menus": [
      { "id": 1, "name": "カット", "price": 5500, "duration_minutes": 60 }
    ],
    "staff": [
      { "id": 1, "name": "田中 美咲" }
    ]
  }
}
```

### Notes

- menus は is_active=true のみ（display_order 昇順）
- staff は is_active=true のみ（id 昇順）。返却項目は id / name のみ
- business_hours は常に7件（day_of_week 0=日曜〜6=土曜）。DBに行が存在しない曜日は「09:00〜19:00 営業」のデフォルト値で補完する

### Errors

| Code | 条件 |
|------|------|
| 404 | booking_slug に一致する有効なサロン（is_active=true）が存在しない / サロンの契約プランに予約管理が含まれない |
| 429 | レート制限超過 |

---

## GET /salons/{booking_slug}/availability

### Purpose

指定日の空き枠（30分刻み）を取得する。

### Access

| Item | Value |
|------|-------|
| Authentication | 不要 |
| Roles | - |
| Rate Limit | 60回/分/IP |

### Query Parameters

| Name | Type | Required | Description |
|------|------|----------|-------------|
| date | date | ✓ | 対象日（YYYY-MM-DD、salon_timezone 基準） |
| menu_id | integer | ✓ | メニューID（対象サロン・is_active=true のみ） |
| user_id | integer | | 担当スタッフID。省略時は「指名なし」 |

### Response

```json
{
  "data": [
    { "start_at": "2026-07-20T10:00:00+09:00" },
    { "start_at": "2026-07-20T10:30:00+09:00" }
  ]
}
```

### Notes

- 空きがある枠のみ・start_at 昇順で返す
- salon_timezone 基準で営業時間の open_time を起点に30分刻みで走査し（09:15 開店なら 09:15, 09:45, …）、`start + menu.duration_minutes <= close_time` かつ対象スタッフに重複予約（cancelled / no_show を除く）がない枠を空きとする
- **Googleカレンダー連携時は、対象スタッフの busy ブロック（外部予定）と重なる枠も除外する**（per_staff モードは当該スタッフの接続由来の busy、shared モードはサロン全体を塞ぐ意味論のため全スタッフに適用）。未連携のサロンでは busy ブロックが無いため従来どおり。管理側 `/api/v1` の予約登録は busy でも登録可能（ADR-023 の「管理側は営業時間外も許容」と同じ思想。公開側のみ不可）。[ADR-025](../decisions/ADR-025-google-calendar-sync.md) 参照
- business_hours に行が存在しない曜日は「09:00〜19:00 営業」のデフォルト値で補完する（休業扱いは is_closed=true の曜日のみ）
- user_id 省略時は「指名なし」＝有効スタッフの誰か1人でも空いていれば可（busy で塞がったスタッフは候補から外れる）
- 予約可能範囲は現在時刻+30分以降〜60日先まで
- 休業日（is_closed=true）・予約可能範囲外の日付は空配列を返す

### Errors

| Code | 条件 |
|------|------|
| 404 | booking_slug に一致する有効なサロン（is_active=true）が存在しない / サロンの契約プランに予約管理が含まれない |
| 422 | 日付形式不正 / 無効なメニュー・スタッフ |
| 429 | レート制限超過 |

---

## POST /salons/{booking_slug}/reservations

### Purpose

Web予約を登録する。

### Access

| Item | Value |
|------|-------|
| Authentication | 不要 |
| Roles | - |
| Rate Limit | 10回/分/IP。加えてサロン（booking_slug）単位 30回/分 |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| menu_id | integer | ✓ | メニューID（対象サロン・is_active=true のみ） |
| user_id | integer | | 担当スタッフID。null / 省略時は「指名なし」（空いている有効スタッフのうち id 最小へ自動割当） |
| start_at | datetime | ✓ | 予約開始日時（ISO 8601 オフセット付き必須。管理側 POST /reservations と同じ date_format 検証）。空き枠計算と同一の判定でサーバ側検証する（下記 Notes 参照） |
| name | string | ✓ | 予約者名（最大100文字） |
| kana | string | ✓ | フリガナ（最大100文字） |
| phone | string | ✓ | 電話番号（最大20文字。正規化〈ハイフン・空白除去、全角→半角〉後に既存顧客と照合） |
| is_first_visit | boolean | ✓ | 「新規ご来店」チェック。true の場合のみ birthday / gender / email を受理する（保存はしない） |
| birthday | date | | 生年月日（`YYYY-MM-DD`・サロンTZの本日以前）。新規顧客作成時のみ `customers.birthday` へ保存 |
| gender | integer | | 性別（0=未設定 / 1=男性 / 2=女性 / 9=その他）。新規顧客作成時のみ `customers.gender` へ保存 |
| email | string | | メールアドレス（最大255文字）。新規顧客作成時のみ `customers.email` へ保存 |
| note | string | | ご要望・気になること（最大500文字）。`reservations.note` へ保存 |

### Response

```json
{
  "data": {
    "booking_token": "Xk9mP2qR7sT4vW1yZ3aB5cD8eF6gH0jQ",
    "start_at": "2026-07-20T10:00:00+09:00",
    "end_at": "2026-07-20T11:00:00+09:00",
    "menu_name": "カット",
    "staff_name": "田中 美咲",
    "line": {
      "add_friend_url": "https://line.me/R/ti/p/@123abcd",
      "link_code": "K7M2P9"
    }
  }
}
```

### Notes

- start_at は空き枠計算（availability）と同一の判定でサーバ側検証する: (1) 該当曜日の営業時間内（欠損曜日はデフォルト 09:00〜19:00 で補完）かつ open_time 起点の30分グリッド上 (2) `start_at + menu.duration_minutes <= close_time` (3) 現在時刻+30分以降かつ salon_timezone の日付で本日+60日後の終日まで (4) 対象スタッフに重複なし（advisory lock 経由） (5) **Googleカレンダー連携時は対象スタッフの busy ブロック（外部予定）と重ならない**（shared モードはサロン全体に適用）。違反時は 422
- **busy 判定は advisory lock 内の重複チェックと同じ箇所で行う**（lock の取得順序は既存を維持〈phone → スタッフ〉）。busy と重なる場合の 422 のエラーキーは枠埋まりと同じ **start_at** 系（外部予定の存在自体は予約者に開示しない）
- 管理側 /api/v1 の予約 API は従来どおり営業時間外も許容し、**busy でも登録可能**とする（ADR-023 の決定を維持。サロンの裁量を優先。本検証は公開APIのみ）
- end_at は `start_at + menu.duration_minutes` からサーバが導出する
- 二重予約防止は管理側と同じ advisory lock を同じ Service 経由で通す。枠が埋まっている場合は 422
- 指名なし（user_id 省略）は有効スタッフを id 昇順に走査して空いているスタッフへ自動割当。全候補が埋まっていれば 422（busy ブロックと重なるスタッフも候補から外す）
- 422 のエラーキー: 時間帯系（枠埋まり・営業時間外・グリッド外・範囲外・**外部予定による埋まり**）= start_at、顧客情報系 = name / kana / phone（同一 phone の未来予約上限超過も phone キー）
- 顧客は同一サロン内の phone 完全一致（未削除、正規化〈ハイフン・空白除去、全角→半角〉後に照合）で既存顧客に紐付け（name / kana は上書きしない）。複数一致時は id 最小の顧客に紐付け、不一致なら新規作成
- 同一サロン内で同一 phone（正規化後）の未来の status=reserved 予約が既に3件ある場合は 422（虚偽予約による枠占拠の緩和）
- 予約は source=web・booking_token 付きで登録される。booking_token は `Str::random(32)`（英数大小32文字・unique）
- line はサロンのLINE連携が有効（is_active=true）かつ顧客が未連携の場合のみ返す（それ以外は null）
- link_code は発行のたびに新規生成して上書きし旧コードは即失効する（有効期限72時間・単回使用）

### Errors

| Code | 条件 |
|------|------|
| 404 | booking_slug に一致する有効なサロン（is_active=true）が存在しない / サロンの契約プランに予約管理が含まれない |
| 422 | バリデーションエラー / start_at が空き枠計算で有効な枠に一致しない（枠が埋まっている・営業時間外・休業日・30分グリッド外・予約可能範囲外・Googleカレンダー連携時の外部予定〈busy〉との重なりを含む） / 無効なメニュー・スタッフ / 同一 phone の未来の reserved 予約が既に3件 |
| 429 | レート制限超過（10回/分/IP または サロン単位 30回/分） |

### Response Code

201 Created

---

## GET /bookings/{booking_token}

### Purpose

予約概要を取得する（キャンセルページ用）。

### Access

| Item | Value |
|------|-------|
| Authentication | 不要 |
| Roles | - |
| Rate Limit | 60回/分/IP |

### Response

```json
{
  "data": {
    "salon_name": "Realize Beauty 表参道",
    "menu_name": "カット",
    "staff_name": "田中 美咲",
    "start_at": "2026-07-20T10:00:00+09:00",
    "end_at": "2026-07-20T11:00:00+09:00",
    "status": "reserved",
    "can_cancel": true
  }
}
```

### Notes

- can_cancel は「status=reserved かつ現在時刻が開始時刻より前（now < start_at・等号不可）」の場合 true
- 認証なしで返すため顧客氏名等の個人情報は含めない（キャンセルページの表示にはサロン名・メニュー名・担当スタッフ・日時・ステータスのみ使用する）

### Errors

| Code | 条件 |
|------|------|
| 404 | booking_token に一致する予約が存在しない（所属サロンが無効〈is_active=false〉の場合を含む） |
| 429 | レート制限超過 |

---

## POST /bookings/{booking_token}/cancel

### Purpose

予約をキャンセルする（status を cancelled に変更）。

### Access

| Item | Value |
|------|-------|
| Authentication | 不要 |
| Roles | - |
| Rate Limit | 10回/分/IP |

### Notes

- キャンセル可能なのは status=reserved かつ現在時刻が開始時刻より前（now < start_at・等号不可）の場合のみ
- status=reserved を WHERE に含む条件付き UPDATE で実装し、更新0件なら 409
- リクエストボディは不要

### Errors

| Code | 条件 |
|------|------|
| 404 | booking_token に一致する予約が存在しない（所属サロンが無効〈is_active=false〉の場合を含む） |
| 409 | キャンセル済み・来店済み・開始時刻を過ぎている（条件付き UPDATE の更新0件） |
| 429 | レート制限超過 |

### Response

200 OK（GET /bookings/{booking_token} と同形式。status=cancelled）

---

# LINE Webhook

## POST /api/line/webhook

### Purpose

LINE プラットフォームからの Webhook イベントを受信する（全サロン共通の1エンドポイント）。

### Access

| Item | Value |
|------|-------|
| Authentication | 不要（x-line-signature による署名検証） |
| Roles | - |
| Rate Limit | なし |

### Request Headers

| Name | Required | Description |
|------|----------|-------------|
| x-line-signature | ✓ | channel_secret を鍵とした HMAC-SHA256 署名（Base64・raw body 対象） |

### Request

| Field | Type | Required | Description |
|--------|------|----------|-------------|
| destination | string | ✓ | 送信先 bot のユーザーID（line_settings.bot_user_id と照合してサロンを特定） |
| events | array | ✓ | Webhook イベントの配列。処理で参照するフィールドは `events[].type` / `events[].replyToken` / `events[].source.userId` / `events[].message.type` / `events[].message.text`（スキーマ定義は openapi.yaml の LineWebhookRequest を参照） |

### Notes

- destination でサロンを特定し、該当サロンの channel_secret で署名検証する
- 未知の destination は署名計算前に即 200 を返す（DB 照会1回のみ・ログ記録）
- 署名検証失敗でもレスポンスは常に 200（LINE 側のリトライ暴走防止。ログには記録する）
- 署名検証はキュー投入前に同期実施し、検証済みイベントのみキューへ投入する。署名検証成功時は line_settings.last_webhook_at を更新する
- イベント処理はキュー経由で非同期に行う
  - follow: 挨拶 reply（連携コードの案内文）
  - message(text): 連携コード照合 → 一致で customers.line_user_id / line_linked_at 保存 + 確認 reply（予約の日時等は含めない。「連携が完了しました。予約前日にリマインダーをお送りします」のみ）。不一致は reply しない（誤爆防止）
    - 照合は destination で特定した**サロン内の顧客に限定**し、line_user_id IS NULL かつ有効期限内（72時間）のコードのみ対象とする
    - 成立時に line_link_code / line_link_code_expires_at を null クリアする（単回使用）
    - 送信者の LINE ユーザーが同一サロン内で既に別顧客と連携済みの場合は保存せず「このLINEアカウントは既に連携済みです。変更はサロンへお問い合わせください」と reply する（事前チェックで unique 制約違反を回避）
    - 既に line_user_id を持つ顧客のコードは照合不成立（上書き＝乗っ取り不可）
  - unfollow: line_user_id / line_linked_at を null に
- follow / message / unfollow 以外のイベントは無視する
- reply 送信ジョブは tries=1（replyToken は短命・単回のためリトライ無意味。失敗はログのみ）

### Response

200 OK（ボディなし）

---

# Google Calendar Webhook

## POST /api/google/calendar/webhook

### Purpose

Google カレンダーの push 通知（watch チャネル）を受信する（全サロン共通の1エンドポイント）。

### Access

| Item | Value |
|------|-------|
| Authentication | 不要（X-Goog-Channel-Token による検証） |
| Roles | - |
| Rate Limit | なし |

### Request Headers

| Name | Required | Description |
|------|----------|-------------|
| X-Goog-Channel-ID | ✓ | watch 作成時に指定したチャネルID（google_calendar_connections.channel_id と照合して接続を特定） |
| X-Goog-Channel-Token | ✓ | watch 作成時に指定した検証用の秘密値（google_calendar_connections.channel_token と照合） |
| X-Goog-Resource-ID | ✓ | 監視対象リソースの識別子（channels.stop に必要な値として channel_resource_id に保存済み。受信時は channel_resource_id と照合する） |
| X-Goog-Resource-State | ✓ | 通知種別（sync = チャネル開設直後の疎通通知、exists = 変更あり） |
| X-Goog-Message-Number | | チャネルごとの通知連番（ログ用） |
| X-Goog-Resource-URI | | 監視対象リソースのバージョン付きURI（ログ用） |

### Request

ボディなし（Google の push 通知は「変更があった」ことのみをヘッダで伝え、変更内容は含まれない）。

### Notes

- watch チャネル作成時に address として `{API_URL}/api/google/calendar/webhook` を登録する
- X-Goog-Channel-ID で接続を特定し、X-Goog-Channel-Token を channel_token と、X-Goog-Resource-ID を channel_resource_id と照合して検証する
- **3段の検証**を行い、いずれかに該当すれば**即 200 で終了**（ログのみ。Google のリトライ暴走防止。LINE Webhook と同じ方針）: (1) 未知の `X-Goog-Channel-ID`（channel_id に一致する接続が無い） (2) `X-Goog-Channel-Token` が channel_token と不一致 (3) `X-Goog-Resource-ID` が channel_resource_id と不一致
- `channel_token` は CSPRNG 由来の32文字以上とし、比較は `hash_equals` で行う
- **X-Goog-Resource-State: sync は何もせず 200**（チャネル開設直後の疎通通知・no-op）
- 検証を通ったら当該接続の増分同期ジョブを投入して 200（ジョブは接続単位で **`ShouldBeUniqueUntilProcessing`**〈`uniqueId` = 接続ID、`uniqueFor` = 10分〉）
- **`ShouldBeUnique` は採用しない**: 同ロックは**ジョブの処理完了まで**保持されるため、同期実行中に届いた push 通知が破棄される。次の変更が起きるまで通知は来ないため最後の変更が反映されないまま滞留し、外部予定が busy にならないまま公開予約が入る（本フェーズが防ごうとしている事故そのもの）。`ShouldBeUniqueUntilProcessing` は処理開始時にロックを解放するため、実行中の通知は次の1本としてキューイングされる
- **`uniqueFor` を必ず定義する**（10分）。未定義だと Laravel はロック期間を 0 と解釈して**無期限ロック**を取得するため、ワーカーの異常終了時に当該接続の同期が恒久的に停止する
- 増分同期は events.list に保存済み syncToken **のみ**を渡して差分取得し（`singleEvents=true` で繰り返し予定を実体展開。syncToken は `timeMin` / `timeMax` / `q` / `orderBy` 等の絞り込みと**併用できない**）、**全ページを辿って適用・コミットした後に** nextSyncToken を保存する（nextSyncToken は最終ページにのみ返る）
- syncToken 失効（HTTP 410 Gone）時は保存済み syncToken を捨てて全同期し直す（timeMin=現在・timeMax=**同期窓の終端 = salon_timezone の本日+61日 00:00**）
- **RB 由来かどうかの判定の権威は RB 側**: `extendedProperties.private.rb_reservation_id` マーカーは改竄可能な入力であり自己識別のヒントに過ぎない。RB 由来の確定は **`reservations` の `(salon_id, google_event_id)` 突合**（per_staff では担当 user_id も一致）で行う。突合しないマーカー付きイベントは**外部予定（busy）として処理する**（無視ではない）。削除イベントは `extendedProperties` を持たないため、この突合が唯一の判定手段でもある。詳細は [ADR-025](../decisions/ADR-025-google-calendar-sync.md) 参照
- レスポンスは検証失敗・処理内容にかかわらず常に 200

### Response

200 OK（ボディなし）

---

# Stripe Webhook

## POST /api/webhooks/stripe

### Purpose

Stripe からの Webhook イベントを受信し、契約状態をDBへ同期する（全サロン共通の1エンドポイント）。

### Access

| Item | Value |
|------|-------|
| Authentication | 不要（Stripe-Signature による署名検証） |
| Roles | - |
| Rate Limit | なし |

### Request Headers

| Name | Required | Description |
|------|----------|-------------|
| Stripe-Signature | ✓ | `t=<timestamp>,v1=<signature>` 形式。`{t}.{raw body}` を STRIPE_WEBHOOK_SECRET で HMAC-SHA256 した署名 |

### Request

Stripe の Event オブジェクト。処理で参照するフィールドは `id` / `type` / `created` / `data.object`（スキーマ定義は openapi.yaml の StripeWebhookEvent を参照）。

### Notes

- パスは `/api/v1` の外（`/api/webhooks/stripe`）。認証なし・throttle なし
- **DEV と本番で別の Webhook エンドポイントを Stripe に登録し**、それぞれ Test / Live の STRIPE_WEBHOOK_SECRET を設定する
- 署名検証は Stripe-Signature の `t` と `v1` を解析し、`{t}.{raw body}` の HMAC-SHA256 を `hash_equals`（タイミング安全）で比較する。あわせて `t` と現在時刻の乖離を STRIPE_WEBHOOK_TOLERANCE 秒（既定300）で検査し、古い署名の再送（リプレイ）を弾く
- **署名検証に失敗した場合は 400**（LINE / Googleカレンダー Webhook の「常に 200」とは異なる方針）。Stripe は 4xx を設定不備として記録しダッシュボードに残すため、秘密鍵の取り違えに気づける
- 正常に処理できた場合・対象外イベントの場合はいずれも 200
- 処理中に例外が出た場合は stripe_webhook_events に failed として記録したうえで投げ直す（500 → Stripe が再送し、再処理できる）
- 冪等性は `stripe_webhook_events.stripe_event_id` の unique 制約で担保する。processed / skipped として記録済みのイベントの再送は何もしない
- 受信内容はイベントIDと種別のみを記録し、**カード情報・請求先などの個人情報は保存しない**（payload をそのまま残さない）
- **契約状態の同期はこの経路でのみ行う**（フロントの申告では更新しない）

### 扱うイベント

| Event | 処理 |
|-------|------|
| checkout.session.completed | サロンと stripe_customer_id を紐づけ、Stripe から subscription を取り直して同期する |
| customer.subscription.created | 契約内容を同期する |
| customer.subscription.updated | 契約内容を同期する（プラン変更・解約申請・状態遷移） |
| customer.subscription.deleted | 契約内容を同期する（期間終了・解約の確定） |
| invoice.payment_failed | subscription_events に payment_failed を記録する（利用停止はしない。回収・再試行は Stripe に委ね、unpaid になった時点で停止） |
| invoice.paid | 受理のみ（状態は customer.subscription.updated で届く） |
| 上記以外 | skipped として記録し 200 を返す |

### Errors

| Code | 条件 |
|------|------|
| 400 | 署名検証失敗（ヘッダ欠落・形式不正・不一致・タイムスタンプの乖離）/ ペイロードを解釈できない・id / type が無い |
| 500 | イベント処理中の例外（stripe_webhook_events に failed を記録。Stripe が再送する） |

### Response

200 OK（ボディなし）

---

# Common Response

## Success

```json
{
  "data": {}
}
```

---

## Validation Error

```json
{
  "message": "Validation failed.",
  "errors": {}
}
```

---

## Feature Required

契約プランに含まれない機能へアクセスした場合のレスポンス（403。[ADR-029](../decisions/ADR-029-subscription-billing.md) 参照）。

```json
{
  "message": "予約管理はStandardプラン以上でご利用いただけます。",
  "feature": "reservation",
  "required_plan": "standard",
  "current_plan": "lite"
}
```

- `feature` は機能キー、`required_plan` はその機能を含む最も安いプラン、`current_plan` は現在有効なプラン（契約が無い・失効している場合は null）
- フロントはこの値からアップグレード導線（`/plan-required/:feature`）の文言を組み立てる
- **401 は返さない**。SPA は 401 を検知するとローカルの認証状態を破棄してログイン画面へ遷移するため、契約起因の遮断を認証切れと誤認させない

---

# HTTP Status Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 201 | Created |
| 204 | No Content |
| 302 | Found（リダイレクト。`GET /google-calendar/callback` のみ） |
| 400 | Bad Request（`POST /api/webhooks/stripe` の署名検証失敗など） |
| 401 | Unauthorized |
| 403 | Forbidden（契約プランに含まれない機能へのアクセス。前述の「Plan / Feature 制限」参照） |
| 404 | Not Found |
| 409 | Conflict |
| 422 | Validation Error |
| 500 | Internal Server Error |
