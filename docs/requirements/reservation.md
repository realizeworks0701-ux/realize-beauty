# 予約コア（Reservation Core）要件書

## Project

Realize Beauty — 予約管理 フェーズ1（ROADMAP v0.3 前倒し）

---

## Purpose

サロンスタッフが紙の予約台帳や外部カレンダーを使わず、
本システム上で予約の登録・変更・確認を完結できるようにする。

顧客・カルテと予約が同一システムに揃うことで、
来店前の準備（前回カルテの確認）と来店後の記録がひとつの流れになる。

---

## Background

- MVP（v0.1〜v0.2）で顧客管理・電子カルテ・写真・AI要約が完成した
- ダッシュボードの「今日の予約」はプレースホルダのままで、実データがない
- Web予約（フェーズ2）の前提として、サロン側の予約管理（本フェーズ）が必要

---

## フェーズ分割

| フェーズ | 内容 | 本書の対象 |
|---------|------|-----------|
| フェーズ1 | 予約コア＝メニュー管理・営業時間・予約CRUD・サロン側予約カレンダー・ダッシュボード「今日の予約」 | ✓ |
| フェーズ2 | 公開Web予約ページ・LINE連携（サロン別チャネル接続）・連携コードによる顧客紐付け・前日リマインダー（詳細は [booking.md](booking.md) / [ADR-024](../decisions/ADR-024-line-integration.md)。LINEミニアプリは不採用） | 対象外 |
| フェーズ3 | Googleカレンダー双方向同期＝OAuth接続（スタッフ別 / サロン共有）・送信同期・受信同期・外部予定の busy 反映（詳細は [google-calendar.md](google-calendar.md) / [ADR-025](../decisions/ADR-025-google-calendar-sync.md)） | 対象外 |

---

## Scope

### In Scope（フェーズ1）

- メニュー管理（CRUD・並び順・有効/無効）
- 営業時間設定（曜日別・定休日）
- 予約CRUD（サロンスタッフによる手動登録・変更・キャンセル・削除）
- 予約カレンダー画面（日表示・スタッフ列）
- ダッシュボード「今日の予約」KPI

### Out of Scope（実装しない）

- 公開Web予約ページ・LINE連携・前日リマインダー（フェーズ2）
- Googleカレンダー同期（フェーズ3）
- スタッフシフト管理
- 複数メニュー同時予約（1予約＝1メニュー）
- 事前決済
- 繰り返し予約

---

# User Stories

- サロンスタッフとして、電話で受けた予約をその場でカレンダーに登録したい
- サロンスタッフとして、今日・指定日の予約をスタッフ別に一覧で確認したい
- サロンスタッフとして、予約の日時・担当・メニューを変更したい
- サロンスタッフとして、キャンセル・無断キャンセルを記録として残したい
- オーナーとして、提供メニュー（名称・価格・所要時間）を管理したい
- オーナーとして、曜日ごとの営業時間・定休日を設定したい
- サロンスタッフとして、ダッシュボードで今日の予約件数を確認したい

---

# Functional Requirements

## Menu（メニュー管理）

- メニュー一覧（display_order 昇順・同値は id 昇順）
- メニュー登録・編集・削除（Soft Delete）
- 並び順の変更（display_order）
- 有効/無効の切り替え（is_active）
- is_active=false のメニューは新規予約の選択肢に表示しない（既存予約は保持する）
- メニュー削除後も、既存予約の menu_id は残る（予約詳細でメニュー名を表示できる）

### バリデーション（決定事項）

| Field | Rule |
|--------|------|
| name | 必須・string・最大100文字 |
| price | 必須・integer・0〜9,999,999（税込・円） |
| duration_minutes | 必須・integer・5〜480（分） |
| display_order | 任意・integer・0以上（省略時はサーバが同一サロン内の max(display_order)+1 を採番する） |
| is_active | 任意・boolean（省略時 true） |

---

## Business Hours（営業時間設定）

- 曜日別（0=日曜〜6=土曜）の営業時間を管理する
- 定休日は is_closed=true で表現する
- DBに行が存在しない曜日は「09:00〜19:00 営業（is_closed=false）」をデフォルトとしてAPIが返す（DBには保存しない）
- 更新は7曜日分の一括置換（PUT）
- 営業時間はサロン側の手動予約を**ブロックしない**（時間外の特別対応・事後入力を許容）。カレンダーの時間外グレーアウト表示と、フェーズ2の顧客向け予約制約に使用する（[ADR-023](../decisions/ADR-023-reservation-core.md)）
- 既存の `salons.business_hours`（自由記述text）は非推奨（deprecated）とし、削除はしない

### バリデーション（決定事項）

| Field | Rule |
|--------|------|
| business_hours | 必須・array・ちょうど7件（day_of_week 0〜6 が各1件） |
| business_hours.*.day_of_week | 必須・integer・0〜6 |
| business_hours.*.is_closed | 必須・boolean |
| business_hours.*.open_time | 必須・`HH:MM` 形式 |
| business_hours.*.close_time | 必須・`HH:MM` 形式・open_time より後 |

- is_closed=true の曜日も open_time / close_time は必須（再度営業日に戻した際の初期値として保持する）
- close_time > open_time は is_closed の値にかかわらず常に検証する

---

## Reservation（予約管理）

- 予約登録（顧客・メニュー・担当スタッフ・開始日時・メモ）
- 予約変更（同項目＋ステータス）
- 予約キャンセル＝status を `cancelled` に変更する（レコードは残る）
- 予約削除（Soft Delete）＝誤登録の取り消し。キャンセルとは区別する
- 期間指定の予約一覧取得（カレンダー用。ページネーションなし）

### ステータス（ReservationStatus）

| Status | 意味 |
|--------|------|
| reserved | 予約済み（初期値） |
| visited | 来店済み |
| cancelled | キャンセル |
| no_show | 無断キャンセル |

- フェーズ1ではステータス遷移を制限しない（PATCHでEnum内の任意の値へ変更可）

### 終了日時（end_at）

- end_at はAPI入力では受け取らない
- サーバが `start_at + menu.duration_minutes` から導出して永続化する
- start_at または menu_id を変更した場合、end_at を再計算する
- メニューの duration_minutes を後から変更しても、既存予約の end_at は変わらない

### バリデーション（決定事項）

| Field | Rule |
|--------|------|
| customer_id | 必須・自サロンの顧客に存在すること |
| menu_id | 必須・自サロンのメニューに存在すること。**新規登録時**および**変更時に menu_id を変える場合**は is_active=true であること |
| user_id | 必須・自サロンの is_active なスタッフに存在すること |
| start_at | 必須・ISO 8601 日時（オフセット付き）。過去日時も許可（事後入力用途） |
| status | PATCHのみ・reserved / visited / cancelled / no_show |
| note | 任意・最大2000文字 |

---

# Business Rules

1. **ダブルブッキング禁止**: 同一サロン・同一担当スタッフ（user_id）で、status が `reserved` / `visited` の予約と時間帯 `[start_at, end_at)` が重なる登録・変更は 422 エラーとする。エラーメッセージ（決定事項）: `指定した時間帯は既に予約が入っています。`（`start_at` フィールドのエラーとして返す）。`cancelled` / `no_show` は重複判定から除外する。判定は ReservationService 内で `DB::transaction` + advisory lock（`pg_advisory_xact_lock`。キー `reservation:{salonId}:{userId}`）を取得してから行う
2. **営業時間は手動予約をブロックしない**: 営業時間外・定休日への予約登録も可能。カレンダーUIではグレーアウト表示のみ行う
3. **ステータス遷移は制限しない**（フェーズ1）
4. **過去日時の予約登録を許可する**（事後入力用途）
5. **日付境界は Asia/Tokyo で解釈する**: 予約一覧の from/to、ダッシュボードの「今日」はJSTの日付境界で判定する。既存ダッシュボード指標（today_customers 等）はアプリTZ（UTC）ベースのままとし、既知の不整合として今回は変更しない（[ADR-023](../decisions/ADR-023-reservation-core.md)）
6. **無効メニューの扱い**: is_active=false のメニューは新規予約に使用不可。既存予約は保持する

---

# API Requirements

エンドポイントの詳細は [docs/api/endpoints.md](../api/endpoints.md) / [docs/api/openapi.yaml](../api/openapi.yaml) を正とする。

- `/api/v1` 配下・`auth:sanctum` 必須・salon_id はログインユーザー由来（リクエストでは受け取らない）
- apiResource `/menus`（一覧は display_order 昇順、`is_active=true` でフィルタ可）
- GET / PUT `/business-hours`（GETは常に7件、PUTは7件一括置換）
- GET `/reservations?from=&to=&user_id=&status=` ＋ POST / GET / PATCH / DELETE
- GET `/users`（担当者選択用。自サロンの is_active なスタッフの id / name / role のみ）
- GET `/dashboard` に `today_reservations`（当日JST・status reserved/visited の件数）を追加

### 予約一覧クエリの解釈（決定事項）

- from / to は `YYYY-MM-DD` 形式。JSTの日付境界で `[from 00:00, to 24:00)` を対象とする
- from 省略時は当日（JST）、to 省略時は from と同日
- to は from 以降、かつ期間は最大31日（超過は 422）
- user_id / status は任意フィルタ。並び順は start_at 昇順
- レスポンスはページネーションなしの `{ "data": [...] }`。customer / menu / user の要約をネストして返す

---

# UI Requirements

画面設計は以下を正とする。

- [docs/ui/reservation.md](../ui/reservation.md) — 予約カレンダー（/reservations）
- [docs/ui/settings-menus.md](../ui/settings-menus.md) — メニュー管理（/settings/menus）
- [docs/ui/settings-business-hours.md](../ui/settings-business-hours.md) — 営業時間設定（/settings/business-hours）
- [docs/ui/dashboard.md](../ui/dashboard.md) — 「今日の予約」KPIカード追加

---

# Out of Scope（再掲・フェーズ2以降の予告）

フェーズ1では実装しない。

- 公開Web予約ページ（フェーズ2）
- LINE連携・前日リマインダー（フェーズ2）
- Googleカレンダー同期（フェーズ3）
- スタッフシフト管理
- 複数メニュー同時予約
- 事前決済
- 繰り返し予約

---

# References

- [docs/db/ERD.md](../db/ERD.md)
- [docs/api/endpoints.md](../api/endpoints.md)
- [docs/decisions/ADR-023-reservation-core.md](../decisions/ADR-023-reservation-core.md)
- [docs/roadmap/ROADMAP.md](../roadmap/ROADMAP.md)
