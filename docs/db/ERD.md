# Database Design v2.0

## Design Policy

Realize Beautyは美容サロン向け業務支援システムである。

以下を設計方針とする。

- Laravel 12 標準設計
- PostgreSQL
- REST API
- SaaS化を前提としたマルチテナント設計
- MVPを優先し、将来の拡張性を確保する
- 過剰設計は行わない
- 店舗（Salon）を親エンティティとする
- 顧客に担当スタッフは持たせず、カルテに施術担当者を保持する
- Laravel Eloquentのリレーションを活かした設計とする

---

# salons

店舗情報

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK, Auto Increment |
| name | string | 店舗名 |
| phone | string | 電話番号 |
| postal_code | string | 郵便番号 |
| address | string | 住所 |
| business_hours | text | nullable, **deprecated**（自由記述。`business_hours` テーブルへ移行。削除はしない） |
| booking_slug | string | nullable, Unique。公開Web予約ページURL用の16文字英数小文字ランダム（フェーズ2 Web予約で追加）。新規サロンは Salon モデルの creating フックで自動生成（unique 衝突時はリトライ）。マイグレーションで既存サロンにもバックフィルし、以後は NOT NULL 相当で運用。再生成（ローテーション）はスコープ外（当面はサポートによる手動更新） |
| is_active | boolean | default true |
| created_at | timestamp | |
| updated_at | timestamp | |

---

## users

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK, Auto Increment |
| salon_id | bigint | FK → salons.id |
| name | string | スタッフ名 |
| email | string | Unique |
| password | string | ハッシュ化パスワード |
| role | string | owner / manager / staff |
| is_active | boolean | 在籍中フラグ |
| last_login_at | timestamp | nullable |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | nullable, Soft Delete |

---

# customers

顧客情報

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK |
| salon_id | bigint | FK → salons.id |
| name | string | |
| kana | string | |
| gender | tinyInteger | 0:未回答 1:男性 2:女性 9:その他 |
| birthday | date | nullable |
| phone | string | nullable |
| email | string | nullable |
| line_user_id | string | nullable。LINEユーザーID（フェーズ2 LINE連携で追加） |
| line_linked_at | timestamptz | nullable。LINE連携完了日時 |
| line_link_code | string | nullable。ワンタイム連携コード。6文字（A-Z, 2-9 のうち曖昧な I / O を除外）。未連携顧客（line_user_id が null）のWeb予約完了時に毎回新規生成して上書き（旧コードは即失効）。連携成立時に null クリア（単回使用） |
| line_link_code_expires_at | timestamptz | nullable。連携コードの有効期限（発行から72時間）。連携成立時に null クリア |
| memo | text | nullable |
| first_visit_at | date | 初回来店日 |
| last_visit_at | date | 最終来店日 |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | Soft Delete |

---

## records

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK |
| salon_id | bigint | FK → salons.id |
| customer_id | bigint | FK → customers.id |
| user_id | bigint | FK → users.id（作成スタッフ） |
| ai_summary | text | nullable |
| status | string | draft / completed |
| visited_at | timestamp | 来店日時 |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | nullable |

---

## record_blocks

カルテを構成する入力ブロック

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK |
| record_id | bigint | FK → records.id |
| label | string | 項目名（例：薬剤、放置時間、次回提案） |
| content | text | 入力内容 |
| sort_order | integer | 表示順 |
| created_at | timestamp | |
| updated_at | timestamp | |

---

## record_block_templates

店舗ごとのカルテ項目テンプレート

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK |
| salon_id | bigint | FK → salons.id |
| label | string | 項目名 |
| placeholder | string | nullable / 入力例・補足 |
| sort_order | integer | 表示順 |
| is_default | boolean | 新規カルテ作成時に初期表示する項目 |
| created_at | timestamp | |
| updated_at | timestamp | |

---

# Relationships

```text
Salon
├── Users
├── Customers
│   ├── Records
│   │   ├── RecordBlocks
│   │   └── Photos
│   └── Reservations
├── Menus
├── BusinessHours
├── LineSetting（1:1）
└── RecordBlockTemplates
```
---

# photos

施術写真

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK |
| record_id | bigint | FK → records.id |
| path | string | Cloudflare R2保存キー |
| caption | string | nullable |
| sort_order | integer | 表示順 |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | Soft Delete |

---

# menus

施術メニュー（v0.3 予約コアで正式化）

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK, Auto Increment |
| salon_id | bigint | FK → salons.id |
| name | string | メニュー名 |
| price | integer | 税込価格（円） |
| duration_minutes | smallint | 施術時間（分）5〜480 |
| display_order | integer | 表示順, default 0 |
| is_active | boolean | default true。false は新規予約の選択肢から除外 |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | nullable, Soft Delete |

---

# business_hours

曜日別の営業時間（v0.3 予約コアで追加）

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK, Auto Increment |
| salon_id | bigint | FK → salons.id |
| day_of_week | smallint | 0=日曜〜6=土曜 |
| is_closed | boolean | default false（定休日フラグ） |
| open_time | time | 開店時刻 |
| close_time | time | 閉店時刻（open_time より後を検証） |
| created_at | timestamp | |
| updated_at | timestamp | |

- 行が存在しない曜日は「09:00〜19:00 営業」をデフォルトとしてAPIが返す（DBには保存しない）
- `salons.business_hours`（自由記述text）は本テーブルへの移行に伴い deprecated

---

# reservations

予約（v0.3 予約コアで正式化）

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK, Auto Increment |
| salon_id | bigint | FK → salons.id |
| customer_id | bigint | FK → customers.id |
| menu_id | bigint | FK → menus.id |
| user_id | bigint | FK → users.id（担当スタッフ） |
| start_at | timestamptz | 予約開始日時 |
| end_at | timestamptz | 予約終了日時（サーバ導出。API入力では受け取らない） |
| status | string | reserved / visited / cancelled / no_show, default 'reserved' |
| source | string | staff / web, default 'staff'（フェーズ2 Web予約で追加） |
| booking_token | string | nullable, Unique（unique index）。Web予約時のみ生成（キャンセルページURL用）。CSPRNG 由来の `Str::random(32)`（英数大小32文字、128bit 超のエントロピー）で生成する |
| reminder_sent_at | timestamptz | nullable。前日リマインダー送信日時（フェーズ2 LINE連携で追加） |
| note | text | nullable |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | nullable, Soft Delete |

- end_at は `start_at + menu.duration_minutes` から導出（start_at または menu_id 変更時に再計算）

---

# line_settings

サロンごとの LINE Messaging API チャネル設定（フェーズ2 LINE連携で追加）

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK, Auto Increment |
| salon_id | bigint | FK → salons.id, Unique（1サロン1設定） |
| channel_id | string | Messaging API チャネルID |
| channel_secret | text | **暗号化保存**（Laravel `encrypted` cast） |
| channel_access_token | text | **暗号化保存**（Laravel `encrypted` cast）。長期チャネルアクセストークン |
| bot_user_id | string | nullable, Unique。接続確認時に GET /v2/bot/info から取得。webhook の destination 照合キー |
| bot_basic_id | string | nullable。友だち追加URL用（`https://line.me/R/ti/p/{basicId}`） |
| bot_display_name | string | nullable。接続確認時に GET /v2/bot/info の displayName を保存（設定画面で表示） |
| is_active | boolean | default false。接続確認成功で true |
| connected_at | timestamptz | nullable |
| last_webhook_at | timestamptz | nullable。webhook の**署名検証成功時のみ**更新（設定画面に「最終Webhook受信」として表示） |
| created_at | timestamp | |
| updated_at | timestamp | |

- Soft Delete は採用しない（連携解除時は物理削除）
- 連携解除（DELETE）時は、当該サロンの customers の `line_user_id` / `line_linked_at` / `line_link_code` / `line_link_code_expires_at` を一括クリアする（LINE の userId はチャネルのプロバイダー単位スコープのため、別チャネル再接続後は無効になる）

---

# Index

## customers

- salon_id
- phone
- email

## records

- customer_id
- user_id
- visited_at

## photos

- record_id

## menus

- (salon_id, display_order)

## reservations

- (salon_id, start_at)
- (salon_id, user_id, start_at)
- customer_id

---

# Unique

## users

- email

## business_hours

- (salon_id, day_of_week)

## salons

- booking_slug

## customers

- (salon_id, line_user_id) — 部分 Unique Index（`WHERE line_user_id IS NOT NULL`）
- (salon_id, line_link_code)

## reservations

- booking_token

## line_settings

- salon_id
- bot_user_id

---

# Foreign Keys

基本方針

- cascadeOnUpdate()
- restrictOnDelete()

誤って親データを削除してカルテが消えないようにする。

---

# Enum

Laravel PHP Enumを採用する。

## Role

- owner
- manager
- staff

## RecordStatus

- draft
- completed

## ReservationStatus

- reserved
- visited
- cancelled
- no_show

## ReservationSource

- staff
- web

---

# Soft Delete

以下のテーブルで採用する。

- customers
- records
- photos
- menus
- reservations

line_settings は採用しない（連携解除時は物理削除）。

---

# Relations

```text
Salon
│
├── Users
│
├── Customers
│     │
│     ├── Records
│     │      │
│     │      └── Photos
│     │
│     └── Reservations
│
├── Menus
│
├── BusinessHours
│
└── LineSetting（1:1）
```

---

# Laravel Eloquent

Salon

- hasMany(User)
- hasMany(Customer)
- hasMany(Menu)
- hasMany(BusinessHour)
- hasMany(Reservation)
- hasOne(LineSetting)
- hasMany(RecordBlockTemplate)

User

- belongsTo(Salon)
- hasMany(Record)
- hasMany(Reservation)

Customer

- belongsTo(Salon)
- hasMany(Record)
- hasMany(Reservation)

Record

- belongsTo(Customer)
- belongsTo(User)
- belongsTo(Salon)
- hasMany(RecordBlock)
- hasMany(Photo)

Photo

- belongsTo(Record)

Menu

- belongsTo(Salon)
- hasMany(Reservation)

BusinessHour

- belongsTo(Salon)

LineSetting

- belongsTo(Salon)

Reservation

- belongsTo(Customer)
- belongsTo(Menu)
- belongsTo(User)
- belongsTo(Salon)

---

# Design Decisions

## マルチテナント

店舗単位でデータを管理する。

全テーブルに必要な `salon_id` を保持し、将来的なSaaS化に対応する。

---

## 担当スタッフ

顧客に担当者は保持しない。

施術したスタッフは `records.user_id` に保存する。

---

## 来店日時

`visited_at` は実際の来店日時。

`created_at` はシステム登録日時。

入力日と来店日を分離する。

---

## 写真

Cloudflare R2へ保存する。

DBには保存キー（path）のみ保持する。

公開URLはアプリケーション側で生成する。

---

## 予約の終了時刻（end_at の永続化）

`reservations.end_at` はカラムとして永続化する。

理由

- メニューの `duration_minutes` を後から変更しても、既存予約の終了時刻が変わらない
- 期間検索（時間帯の重複判定・カレンダー表示）が単純になる

`end_at` はAPI入力では受け取らず、`start_at + menu.duration_minutes` からサーバが導出する。

`start_at` または `menu_id` の変更時に再計算する。

詳細は [ADR-023](../decisions/ADR-023-reservation-core.md)。

---

## ダブルブッキング防止

同一サロン・同一担当スタッフ（user_id）で、
status が reserved / visited の予約と時間帯 `[start_at, end_at)` が重なる登録・変更は 422 エラーとする。

cancelled / no_show は重複判定から除外する。

判定は ReservationService 内で `DB::transaction` を開始し、
`pg_advisory_xact_lock`（キー: `reservation:{salonId}:{userId}` のハッシュ）で
同一サロン・同一スタッフへの登録・変更を直列化したうえで、重複行を `lockForUpdate` で確認してから行う。
advisory lock はトランザクション終了時に自動解放される。

空き時間帯には行ロック対象の行が存在しないため、`lockForUpdate` のみでは同時 INSERT のすり抜けを防げない。
advisory lock により、サロン手動予約 × Web予約を含む同時登録のダブルブッキングを防止する
（フェーズ2の公開Web予約も同じ Service 経由で同一のロックを通す）。

DB制約（EXCLUDE制約等）は採用しない（過剰設計を避ける）。

---

## 営業時間

営業時間（business_hours）はサロン側の手動予約をブロックしない。

時間外の特別対応・レコード目的の事後入力を許容するため。

カレンダーUIでの時間外グレーアウト表示と、
フェーズ2の顧客向けWeb予約の制約に使用する。

---

## 日付境界（Asia/Tokyo）

予約の from/to、ダッシュボードの「今日の予約」の日付境界は Asia/Tokyo で解釈する。

既存ダッシュボード指標（today_customers 等）はアプリTZ（UTC）ベースのままであり、
既知の不整合として [ADR-023](../decisions/ADR-023-reservation-core.md) に注記する（今回は変更しない）。

---

## LINE チャネル認証情報の暗号化保存

LINE Messaging API の接続情報はサロンごとに `line_settings` へ保存するデータ駆動方式とする（サロン追加時のコード変更・デプロイは不要）。

`channel_secret` と `channel_access_token` は Laravel の `encrypted` cast でDBに暗号化保存する。

webhook は全サロン共通の1エンドポイントとし、リクエストボディの `destination`（bot user ID）を
`line_settings.bot_user_id` と照合してサロンを特定する。

詳細は [ADR-024](../decisions/ADR-024-line-integration.md)（Web予約・LINE連携）。

---

## LINE連携の本人紐付け（ワンタイム連携コード）

顧客はログインアカウントを持たないため、Messaging API の account link 機能は使わない。

未連携顧客（`line_user_id` が null）のWeb予約完了時に `customers.line_link_code`
（6文字。A-Z, 2-9 のうち曖昧な I / O を除外）を毎回新規生成して上書きし（旧コードは即失効）、
有効期限（`line_link_code_expires_at` = 発行から72時間）を設定する。

顧客がLINEトークでコードを送信 → webhook で照合して `customers.line_user_id` / `line_linked_at` に保存する。
照合は destination で特定したサロン内の顧客に限定し、`line_user_id` が null かつ期限内のコードのみ対象とする
（既に連携済みの顧客のコードは照合不成立 = 上書き・乗っ取り不可）。
成立時に `line_link_code` / `line_link_code_expires_at` を null クリアする（単回使用）。

unfollow 時は `line_user_id` / `line_linked_at` を null に戻す。

---

## Web予約の公開識別子（booking_slug / booking_token）

公開Web予約ページのURLには連番IDを使わず、`salons.booking_slug`（16文字の英数小文字ランダム）を使用する。
新規サロンは Salon モデルの creating フックで自動生成する（unique 衝突時はリトライ）。

キャンセルページのURLには `reservations.booking_token`（Web予約時のみ生成, Unique）を使用し、
認証なしのエンドポイントでも予約が推測されないようにする。

booking_token は認証代わりの秘密トークンであるため、生成方式を以下のとおり規定する。

- CSPRNG（暗号論的乱数）由来の `Str::random(32)`（英数大小32文字、128bit 超のエントロピー）で生成する
- 連番・タイムスタンプ・短いランダム値など、列挙・推測可能な生成方式は採用してはならない
- OpenAPI では minLength / maxLength: 32、pattern `^[A-Za-z0-9]{32}$` として定義する

---

## 将来の複数店舗対応

MVPでは `users.salon_id` を採用する。

将来的に

- 1ユーザー
- 複数店舗管理

が必要になった場合は、

`salon_user`

中間テーブルへ移行する。

MVPでは実装しない。

---

# Future Roadmap

- AIチャット
- 売上分析
- ダッシュボード
- 在庫管理
- スタッフ権限
- SaaS化
- サブスクリプション
- 添付ファイル管理（attachments）
- API公開
- 外部サービス連携
