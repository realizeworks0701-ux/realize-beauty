# API Endpoints

## API Information

| Item | Value |
|------|-------|
| Base URL | `/api/v1` |
| Authentication | Laravel Sanctum |
| Response Format | JSON |
| API Style | RESTful |

> 上記は管理側APIの共通情報。フェーズ2で追加した公開Web予約API（`/api/public/v1`・認証なし）と LINE Webhook（`/api/line/webhook`）は後述の各セクションを参照。

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

# LINE Settings

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
| 404 | 認証情報が未登録 |

### Response

204 No Content

---

# Booking Page

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

# Public Booking（公開Web予約）

## API Information

| Item | Value |
|------|-------|
| Base URL | `/api/public/v1` |
| Authentication | 不要（booking_slug / booking_token をURLに含む） |
| Response Format | JSON |
| Rate Limit | 全エンドポイントにIP単位の throttle を適用（超過時 429）。予約作成は加えてサロン（booking_slug）単位 30回/分 |

> 以下のパスは `/api/public/v1` からの相対パス。

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
| 404 | booking_slug に一致する有効なサロン（is_active=true）が存在しない |
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
- business_hours に行が存在しない曜日は「09:00〜19:00 営業」のデフォルト値で補完する（休業扱いは is_closed=true の曜日のみ）
- user_id 省略時は「指名なし」＝有効スタッフの誰か1人でも空いていれば可
- 予約可能範囲は現在時刻+30分以降〜60日先まで
- 休業日（is_closed=true）・予約可能範囲外の日付は空配列を返す

### Errors

| Code | 条件 |
|------|------|
| 404 | booking_slug に一致する有効なサロン（is_active=true）が存在しない |
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

- start_at は空き枠計算（availability）と同一の判定でサーバ側検証する: (1) 該当曜日の営業時間内（欠損曜日はデフォルト 09:00〜19:00 で補完）かつ open_time 起点の30分グリッド上 (2) `start_at + menu.duration_minutes <= close_time` (3) 現在時刻+30分以降かつ salon_timezone の日付で本日+60日後の終日まで (4) 対象スタッフに重複なし（advisory lock 経由）。違反時は 422
- 管理側 /api/v1 の予約 API は従来どおり営業時間外も許容する（ADR-023 の決定を維持。本検証は公開APIのみ）
- end_at は `start_at + menu.duration_minutes` からサーバが導出する
- 二重予約防止は管理側と同じ advisory lock を同じ Service 経由で通す。枠が埋まっている場合は 422
- 指名なし（user_id 省略）は有効スタッフを id 昇順に走査して空いているスタッフへ自動割当。全候補が埋まっていれば 422
- 422 のエラーキー: 時間帯系（枠埋まり・営業時間外・グリッド外・範囲外）= start_at、顧客情報系 = name / kana / phone（同一 phone の未来予約上限超過も phone キー）
- 顧客は同一サロン内の phone 完全一致（未削除、正規化〈ハイフン・空白除去、全角→半角〉後に照合）で既存顧客に紐付け（name / kana は上書きしない）。複数一致時は id 最小の顧客に紐付け、不一致なら新規作成
- 同一サロン内で同一 phone（正規化後）の未来の status=reserved 予約が既に3件ある場合は 422（虚偽予約による枠占拠の緩和）
- 予約は source=web・booking_token 付きで登録される。booking_token は `Str::random(32)`（英数大小32文字・unique）
- line はサロンのLINE連携が有効（is_active=true）かつ顧客が未連携の場合のみ返す（それ以外は null）
- link_code は発行のたびに新規生成して上書きし旧コードは即失効する（有効期限72時間・単回使用）

### Errors

| Code | 条件 |
|------|------|
| 404 | booking_slug に一致する有効なサロン（is_active=true）が存在しない |
| 422 | バリデーションエラー / start_at が空き枠計算で有効な枠に一致しない（枠が埋まっている・営業時間外・休業日・30分グリッド外・予約可能範囲外を含む） / 無効なメニュー・スタッフ / 同一 phone の未来の reserved 予約が既に3件 |
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
