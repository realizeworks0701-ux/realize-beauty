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
    "recent_customers": [],
    "recent_records": []
  }
}
```

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

## POST /records/{record}/ai-summary

### Purpose

カルテ内容をAIで要約する。

### Access

| Item | Value |
|------|-------|
| Authentication | Required |
| Roles | owner, manager, staff |

### Response

```json
{
  "data": {
    "summary": "..."
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
