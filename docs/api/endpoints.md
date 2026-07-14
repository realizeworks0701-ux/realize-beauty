# API Endpoints

## API Information

| Item | Value |
|------|-------|
| Base URL | `/api/v1` |
| Authentication | Laravel Sanctum |
| Response Format | JSON |
| API Style | RESTful |

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
      "role": "owner"
    }
  }
}
```

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
    "role": "owner"
  }
}
```

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
    "today_customers": 8,
    "new_customers": 2,
    "total_customers": 152,
    "records_this_month": 94,
    "today_reservations": 5,
    "recent_customers": [],
    "recent_records": []
  }
}
```

### Notes

- `today_reservations` は当日（Asia/Tokyo の日付境界）の予約件数。status が `reserved` / `visited` のみ集計する
- 既存指標（today_customers 等）はアプリTZ（UTC）ベースのままとする（既知の不整合。[ADR-023](../decisions/ADR-023-reservation-core.md) 参照）

---

# Customers

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
      "note": null,
      "created_at": "2026-07-13T18:00:00+09:00",
      "updated_at": "2026-07-13T18:00:00+09:00"
    }
  ]
}
```

### Notes

- from / to は Asia/Tokyo の日付境界で解釈する（`[from 00:00, to 24:00)` JST）
- start_at 昇順で返す
- ページネーションなし

### Errors

| Code | 条件 |
|------|------|
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

# HTTP Status Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 201 | Created |
| 204 | No Content |
| 400 | Bad Request |
| 401 | Unauthorized |
| 403 | Forbidden |
| 404 | Not Found |
| 409 | Conflict |
| 422 | Validation Error |
| 500 | Internal Server Error |
