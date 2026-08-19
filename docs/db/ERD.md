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
| google_calendar_mode | string | nullable。per_staff / shared（フェーズ3 Googleカレンダー同期で追加）。null = 未設定（同期を使わない）。モード変更時は当該サロンの `google_calendar_connections` をすべて解除する |
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
├── GoogleCalendarConnections
│   └── GoogleBusyBlocks
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
| google_event_id | string | nullable, index。送信同期で作成した Google カレンダーイベントのID（フェーズ3 Googleカレンダー同期で追加）。未同期・対象接続なしの場合は null。**受信同期での RB 由来イベントの確定はこのカラムとの `(salon_id, google_event_id)` 突合で行う**（per_staff モードでは担当 `user_id` も一致すること）。Google 側の `extendedProperties.private.rb_reservation_id` マーカーは判定の権威ではない（後述） |
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

# google_calendar_connections

Googleカレンダーとの接続（フェーズ3 Googleカレンダー同期で追加）

1接続 = 1 Google アカウントの1カレンダー。同一カレンダーに対して書き込み（RBの予約）と読み取り（RB以外の予定 = busy）の両方を行う。

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK, Auto Increment |
| salon_id | bigint | FK → salons.id |
| user_id | bigint | nullable, FK → users.id。null = サロン共有接続（shared モード） |
| google_account_email | string | 接続した Google アカウント（設定画面の表示用）。宣言スコープ（calendar.events + calendar.calendarlist.readonly）では userinfo を取得できないため、`calendarList` の primary エントリの `id`（= アカウントのメールアドレス）から取得する |
| calendar_id | string | default 'primary'。対象カレンダー。接続後に calendarList から選び直せる。`primary` はエイリアスであり `calendarList` は実 id（メールアドレス）を返すため、値は「`primary` または calendarList に存在する id」を許容する（既定値 `primary` が自らの検証に違反しないため） |
| access_token | text | **暗号化保存**（Laravel `encrypted` cast） |
| refresh_token | text | **暗号化保存**（Laravel `encrypted` cast） |
| token_expires_at | timestamptz | access_token の有効期限。期限切れ時は refresh_token で更新 |
| sync_token | text | nullable。`events.list` の増分同期用 nextSyncToken（最終ページにのみ返るため、全ページ適用・コミット後に更新する）。syncToken は `timeMin` / `timeMax` と併用できず同期窓を紐づけられないため、窓を前に進めるには全同期が要る。全同期の契機は初回接続 / 対象カレンダー変更 / 410 Gone / 日次の同期窓前進の4つで、いずれも保存済み sync_token を破棄して取り直す |
| channel_id | string | nullable, Unique。watch チャネルID（webhook の `X-Goog-Channel-ID` 照合キー）。推測・列挙されないよう CSPRNG 由来のランダム値で生成する（後述） |
| channel_resource_id | string | nullable。`channels.stop` に必要な resourceId |
| channel_token | string | nullable。webhook 検証用の秘密値（`X-Goog-Channel-Token` と照合）。認証なし webhook における唯一の検証手段のため生成方式を規定する（後述） |
| channel_expires_at | timestamptz | nullable。チャネル有効期限。期限前に定期コマンドで張り直す（Google にチャネル更新 API は存在しないため、「更新」＝新しい `channel_id` で watch を張り直し、旧チャネルを `channels.stop` すること） |
| last_synced_at | timestamptz | nullable。最終同期日時（設定画面に表示） |
| status | string | default 'active'。active / needs_reconnect。refresh_token 失効・アクセス取消時は needs_reconnect にして再接続を促す |
| created_at | timestamp | |
| updated_at | timestamp | |

- Soft Delete は採用しない。解除時は次の5手順で物理削除する（`channels.stop` → refresh_token の revoke → busy ブロック削除 → 対象範囲の予約の `google_event_id` を null クリア → 接続レコードの物理削除）。`channels.stop` / revoke の失敗は best-effort（ログのみで続行）とし、RB 側の削除は必ず完遂する。モード変更に伴う一括解除にも同じ副作用セットを適用する（正典は [requirements/google-calendar.md](../requirements/google-calendar.md) の接続節）
- 接続解除では Google 側のイベントは削除しない（サロンの記録として残す）。対象カレンダー変更が旧カレンダーの RB 由来イベントを削除するのと**意図的に非対称**である（変更は同一アカウント内の移し替えであり、孤児イベントを残すと手動削除が生きた予約を cancelled にする事故経路になるため）
- カレンダーの表示名（summary）は保持しない。設定画面は `primary` を「メインカレンダー（{google_account_email}）」、それ以外は `calendar_id` をそのまま表示する（名称は「カレンダーを変更」ダイアログの一覧で確認できるため、陳腐化する写しをDBに持たない）
- トークン（access_token / refresh_token）と channel_token は API レスポンスに含めない
- 部分 Unique Index 2種
  - `(salon_id, user_id)` — `WHERE user_id IS NOT NULL`（per_staff モード: 1スタッフ1接続）
  - `(salon_id)` — `WHERE user_id IS NULL`（shared モード: 1サロン1接続）
- 対象カレンダー変更時は `sync_token` を破棄し、watch チャネルを張り直して busy ブロックを再構築する。あわせて旧カレンダーの RB 由来イベントを削除し、新カレンダーへ初回送信同期で書き直す

---

# google_busy_blocks

Googleカレンダー上の RB 以外の予定（外部予定）を空き枠計算用に取り込んだブロック（フェーズ3 Googleカレンダー同期で追加）

| Column | Type | Note |
|--------|------|------|
| id | bigint | PK, Auto Increment |
| salon_id | bigint | FK → salons.id |
| google_calendar_connection_id | bigint | FK → google_calendar_connections.id, **cascadeOnDelete()** |
| user_id | bigint | nullable, FK → users.id。null = サロン全体を塞ぐ（shared モード） |
| google_event_id | string | 取り込み元イベントID（upsert キー） |
| start_at | timestamptz | 外部予定の開始日時 |
| end_at | timestamptz | 外部予定の終了日時 |
| created_at | timestamp | |
| updated_at | timestamp | |

- **イベントのタイトル・説明・出席者等の内容は一切保存しない**（開始・終了時刻のみ）
  - 理由: スタッフの私用予定が対象であり、サロン側に予定の中身を見せる必要がない（プライバシー配慮）。空き枠計算に必要なのは時間帯だけである
  - RB のカレンダーUI上は「外部予定」という固定表記でグレー表示する
- busy ブロックにしないのは **RB 由来と確定したイベントのみ**。確定は `reservations` の `(salon_id, google_event_id)` 突合で行う（per_staff モードでは担当 `user_id` も一致すること）。`extendedProperties.private.rb_reservation_id` マーカーを持つだけで突合しないイベントは、外部予定として busy ブロックにする（後述）
- busy から除外するイベントは次の3種
  - `transparency=transparent`（予定ありにしない）
  - `eventType` が `workingLocation`（勤務場所）/ `birthday`（連絡先の誕生日）— `primary` に流れる特殊イベントで、`singleEvents=true` により終日イベントとして展開されるため、取り込むと丸1日塞がる
  - 接続アカウント本人の `responseStatus` が `declined`（辞退した会議）— 辞退済みでも `opaque` のまま残るため
- 除外条件に該当するようになった場合は既存の busy ブロックを削除する（幽霊 busy を残さない）
- 終日予定は `start.date` の salon_timezone 00:00 から `end.date`（**排他**）の salon_timezone 00:00 までを **1本の busy ブロック**として取り込む。連休・旅行・全体研修のように複数日にまたがる終日予定も **1レコード**で表現する（unique `(google_calendar_connection_id, google_event_id)` が日ごとの分割を禁じるため）
- Google 側で削除・同期範囲外になった予定は busy ブロックも削除する（全同期は応答に削除イベントを含まないため、応答に現れなかった同期窓内の busy ブロックを照合削除する）
- Soft Delete は採用しない（外部予定の写しであり、削除は物理削除）
- 接続解除時は FK の cascade delete で自動的に消える
- unique: `(google_calendar_connection_id, google_event_id)`
- index: `(salon_id, start_at)`、`(user_id, start_at)`

---

# Index

## customers

- salon_id
- phone
- email

## records

- (salon_id, visited_at)
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
- google_event_id（受信同期の `(salon_id, google_event_id)` 突合の逆引きに使う。検索は必ず salon_id で絞る）

## google_busy_blocks

- (salon_id, start_at)
- (user_id, start_at)

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

## google_calendar_connections

- (salon_id, user_id) — 部分 Unique Index（`WHERE user_id IS NOT NULL`）。per_staff モードで1スタッフ1接続
- (salon_id) — 部分 Unique Index（`WHERE user_id IS NULL`）。shared モードで1サロン1接続
- channel_id — Unique（webhook の `X-Goog-Channel-ID` 照合キー）

## google_busy_blocks

- (google_calendar_connection_id, google_event_id) — Unique（受信同期の upsert キー。複数日にまたがる終日予定を日ごとに分割できないのはこの制約による）
- (salon_id, start_at)
- (user_id, start_at)

---

# Foreign Keys

基本方針

- cascadeOnUpdate()
- restrictOnDelete()

誤って親データを削除してカルテが消えないようにする。

例外

- `google_busy_blocks.google_calendar_connection_id` は **cascadeOnDelete()** とする。
  busy ブロックは接続が読み取った外部予定の写しにすぎず、接続解除後に残す意味がないため
  （接続の解除は物理削除であり、残置すると空き枠を塞ぎ続ける孤児レコードになる）。

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

## GoogleCalendarMode

`salons.google_calendar_mode`（null = 未設定）

- per_staff — スタッフ別。各スタッフが自分の Google アカウントを接続する。RB はそのスタッフ担当の予約のみ書き込み、カレンダー上の外部予定は**そのスタッフの**空き枠を塞ぐ
- shared — サロン共有。オーナーが1アカウントだけ接続する。RB は全スタッフの予約を書き込み（題名に担当スタッフ名を含む）、外部予定は**サロン全体の**空き枠を塞ぐ

## GoogleCalendarConnectionStatus

`google_calendar_connections.status`

- active
- needs_reconnect — refresh_token の失効・ユーザーによるアクセス取消。同期ジョブはリトライせず打ち切り、UIで再接続を促す

---

# Soft Delete

以下のテーブルで採用する。

- customers
- records
- photos
- menus
- reservations

line_settings は採用しない（連携解除時は物理削除）。

google_calendar_connections / google_busy_blocks も採用しない（接続解除時は物理削除。busy ブロックは cascade delete で消える）。

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
├── LineSetting（1:1）
│
└── GoogleCalendarConnections
      │
      └── GoogleBusyBlocks（cascade delete）
```

- Salon 1 – N GoogleCalendarConnection
  - per_staff モード: スタッフ数ぶんの接続（`user_id` あり）
  - shared モード: 1本のみ（`user_id` は null）
- User 1 – 1 GoogleCalendarConnection（per_staff モード時のみ。部分 Unique `(salon_id, user_id) WHERE user_id IS NOT NULL` で担保）
- GoogleCalendarConnection 1 – N GoogleBusyBlock（接続削除時は cascade delete）

---

# Laravel Eloquent

Salon

- hasMany(User)
- hasMany(Customer)
- hasMany(Menu)
- hasMany(BusinessHour)
- hasMany(Reservation)
- hasOne(LineSetting)
- hasMany(GoogleCalendarConnection)
- hasMany(GoogleBusyBlock)
- hasMany(RecordBlockTemplate)

User

- belongsTo(Salon)
- hasMany(Record)
- hasMany(Reservation)
- hasOne(GoogleCalendarConnection)（per_staff モード時）
- hasMany(GoogleBusyBlock)

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

GoogleCalendarConnection

- belongsTo(Salon)
- belongsTo(User)（nullable。null = サロン共有接続）
- hasMany(GoogleBusyBlock)

GoogleBusyBlock

- belongsTo(Salon)
- belongsTo(GoogleCalendarConnection)
- belongsTo(User)（nullable。null = サロン全体）

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

## Google OAuth トークンの暗号化保存

Googleカレンダーの接続情報は接続単位で `google_calendar_connections` へ保存する。

`access_token` と `refresh_token` は Laravel の `encrypted` cast でDBに暗号化保存する
（フェーズ2の `line_settings.channel_secret` / `channel_access_token` と同じ方針）。

これらのトークンと `channel_token`（webhook 検証用の秘密値）はAPIレスポンスに含めない。

接続単位はサロンごとに選ぶ（`salons.google_calendar_mode`）。

- per_staff — 各スタッフが自分のアカウントを接続（`user_id` あり）
- shared — オーナーが1本だけ接続（`user_id` は null）

いずれも部分 Unique Index で1接続に制限する。モード変更時は既存の接続をすべて解除する。

詳細は [ADR-025](../decisions/ADR-025-google-calendar-sync.md)（Googleカレンダー双方向同期）。

---

## watch チャネル識別子（channel_id / channel_token）

`POST /api/google/calendar/webhook` は認証なしのエンドポイントであり、
`channel_id` による接続の特定と `channel_token` の照合だけが検証手段である。
`booking_token`（Web予約のキャンセルページ）と同じく「認証代わりの秘密値」であるため、
同水準の生成方式を規定する。

- `channel_token` は **CSPRNG（暗号論的乱数）由来の32文字以上**（`Str::random(32)` = 英数大小32文字、128bit 超のエントロピー）で生成し、照合は **`hash_equals`**（タイミング安全な比較）で行う
- `channel_id` も推測・列挙できないよう CSPRNG 由来のランダム値（UUIDv4 または `Str::random(32)` 相当）で生成する
- 連番・タイムスタンプ・短いランダム値など、列挙・推測可能な生成方式は採用してはならない
- 両者とも接続ごとに新規生成し、watch チャネルを張り直すたびに新しい値を発行する
  （張り直し = 定期更新・対象カレンダー変更・再接続。旧チャネル宛の通知を新チャネルの値で通してはならない）。
  Google にチャネル更新 API は存在しないため、「更新」とは新しい `channel_id` で watch を張り直し、旧チャネルを `channels.stop` することを指す
- 前述のとおり、いずれもAPIレスポンスに含めない

照合は3段で行い、いずれかに失敗した場合（未知の `channel_id`、`channel_token` 不一致、
`X-Goog-Resource-ID` が `channel_resource_id` と不一致）は
ログのみ残して 200 を返す（Google のリトライ暴走防止。フェーズ2の LINE webhook と同じ方針）。

---

## RB 由来イベントの判定とエコー（無限ループ）防止

RB が作成する Google イベントには `extendedProperties.private.rb_reservation_id` と `rb_salon_id` を必ず付与する。
これは RB 自身が付けた**自己識別のヒント**であり、Google カレンダー上では誰でも編集・複製できる**改竄可能な入力**である。
よってマーカーを判定の権威にしてはならない（他サロンの ID を書いたイベントを作られれば、テナント境界を越えて予約を操作されうる）。

**RB 由来の確定は `reservations` の `(salon_id, google_event_id)` 突合で行う**
（per_staff モードでは担当 `user_id` も一致すること）。

- 突合した = RB 由来の予約イベント → start と end の**両方**が RB の予約と一致すれば no-op（書き戻さない）。
  これにより送信同期 → webhook → 受信同期 → 送信同期… のループが収束する。
  あわせて `event.updated` と `reservations.updated_at` を UTC の instant として比較し、
  RB の方が新しければ no-op とする（キュー待ちの管理画面の変更を古い値で潰さないため。Carbon は `->utc()` 必須）
- 突合しない = 外部予定 → マーカーの有無にかかわらず `google_busy_blocks` へ upsert する（「無視する」ではない）

削除イベント（`status=cancelled`）は Google が `id` 以外のフィールドを返す保証が無く、
`extendedProperties` を持たない。この突合は削除イベントに対する**唯一の判定手段**でもある。

したがって `reservations.google_event_id` は、送信同期でイベントを更新・削除するための対応付けであると同時に、
**受信同期における RB 由来判定の唯一の根拠**である（サロン単位のスコープを DB 側で担保できるため）。

`reservations` は書き込み先の接続（カレンダー）をカラムとして保持しない。
書き込み先は予約の担当スタッフから導出できるためである
（per_staff = `reservations.user_id` の接続、shared = `user_id IS NULL` のサロン接続）。

ただし per_staff モードで**担当スタッフを変更**した場合、書き込み先カレンダーが移動する。
Google のイベントIDはカレンダー単位でユニークであり、
新担当のカレンダーへ旧IDで `events.update` を投げても 404 になる。
また旧担当のカレンダーにイベントを残したまま `google_event_id` を新IDへ差し替えると、
残ったイベントはどの予約とも突合しなくなり、
旧担当側の受信同期で**外部予定として busy 化され、旧担当の枠を永久に塞ぐ**。

送信同期ジョブは実行時点の予約を再読み込みして書くため、
引数を渡さなければ**変更前の担当スタッフを知り得ない**。
よって担当変更時は、ジョブの引数に予約IDとあわせて変更前の `user_id` を渡し、
旧担当のカレンダーからイベントを削除してから新担当のカレンダーへ作成し直す
（`google_event_id` も新IDで上書きする）。
shared モードは接続が1本のため、この移動は発生しない。

送信同期側の規定は [requirements/google-calendar.md](../requirements/google-calendar.md) の「送信同期」を参照。

RB由来イベントが Google 側で移動され、移動先が他の予約・営業時間・busy と競合する場合は、
RB の値で Google 側を巻き戻す（**RB を真実とする**）。
取り込むのは `start_at` のみで、`end_at` は常に `start_at + menu.duration_minutes` から再導出する
（`reservations.end_at` がサーバ導出である原則を受信同期でも崩さない）。

---

## busy ブロックは時刻のみ保存する

`google_busy_blocks` には開始・終了時刻（`start_at` / `end_at`）だけを保存し、
イベントのタイトル・説明・出席者等の内容は保存しない。

理由

- 対象はスタッフの私用予定であり、サロン側に予定の中身を見せる必要がない（プライバシー配慮）
- 空き枠計算に必要な情報は時間帯のみである
- 保存しなければ漏洩しない（保存する情報を最小限にする）

RB のカレンダーUI上は「外部予定」という固定表記でグレー表示する。

空き枠への反映は `AvailabilityService` と `PublicBookingService` の枠検証で行い、
公開Web予約は busy と重なる枠を予約不可とする。
サロン側の手動予約は busy でも登録可能とする（営業時間と同じくサロンの裁量を優先する。ADR-023 と同じ思想）。

詳細は [ADR-025](../decisions/ADR-025-google-calendar-sync.md)（Googleカレンダー双方向同期）。

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
