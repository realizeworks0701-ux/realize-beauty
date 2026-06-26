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
| business_hours | text | nullable |
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
| memo | text | nullable |
| first_visit_at | date | 初回来店日 |
| last_visit_at | date | 最終来店日 |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | Soft Delete |

---

# records

電子カルテ

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK |
| salon_id | bigint | FK → salons.id |
| customer_id | bigint | FK → customers.id |
| user_id | bigint | FK → users.id |
| visited_at | datetime | 来店日時 |
| treatment | text | 施術内容 |
| memo | text | カルテメモ |
| ai_summary | text | nullable |
| status | string | draft / completed |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | Soft Delete |

---

# photos

施術写真

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK |
| record_id | bigint | FK → records.id |
| path | string | Cloudflare R2保存キー |
| caption | string | nullable |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | Soft Delete |

---

# menus（Future）

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK |
| salon_id | bigint | FK |
| name | string | |
| price | integer | |
| duration | integer | 分 |
| display_order | integer | 表示順 |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | Soft Delete |

---

# reservations（Future）

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK |
| salon_id | bigint | FK |
| customer_id | bigint | FK |
| menu_id | bigint | FK |
| user_id | bigint | FK |
| start_at | datetime | 予約開始日時 |
| status | string | reserved / visited / cancelled / no_show |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | Soft Delete |

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

---

# Unique

## users

- email

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

## ReservationStatus（Future）

- reserved
- visited
- cancelled
- no_show

---

# Soft Delete

以下のテーブルで採用する。

- customers
- records
- photos
- menus
- reservations

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
│     └── Reservations（Future）
│
└── Menus（Future）
```

---

# Laravel Eloquent

Salon

- hasMany(User)
- hasMany(Customer)
- hasMany(Menu)

User

- belongsTo(Salon)
- hasMany(Record)

Customer

- belongsTo(Salon)
- hasMany(Record)
- hasMany(Reservation)

Record

- belongsTo(Customer)
- belongsTo(User)
- belongsTo(Salon)
- hasMany(Photo)

Photo

- belongsTo(Record)

Menu

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

- Web予約
- LINE連携
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