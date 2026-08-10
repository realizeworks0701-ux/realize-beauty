# 公開Web予約の新規顧客登録 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 公開Web予約ページに「新規ご来店」チェックボックスと顧客情報の追加入力欄を設け、新規顧客をより充実した内容で自動生成できるようにし、あわせて来店確定時に `first_visit_at` / `last_visit_at` を自動記録する。

**Architecture:** バックエンドは Controller → Service → Repository → Model の層構造を守る。公開予約の追加項目は `CreatePublicReservationRequest` の `exclude_unless` で「新規ご来店」チェック時のみ受理し、`PublicBookingService::resolveCustomer` が新規顧客作成時にのみ使う。来店日は `status=visited` の予約から都度 MIN/MAX を引き直す方式とし、`ReservationService` の `update()` / `delete()` から呼ぶ。フロントエンドは公開予約ページのステップ4を `BookingCustomerForm.vue` に切り出し、バリデーションは `utils/publicBooking.ts` の純関数に置く。

**Tech Stack:** Laravel 13 / PHP 8.3+ / PostgreSQL / PHPUnit ／ Vue 3 + TypeScript + PrimeVue / Vitest

## Global Constraints

- 設計書は [docs/superpowers/specs/2026-08-10-public-booking-new-customer-design.md](../specs/2026-08-10-public-booking-new-customer-design.md)。矛盾する場合は設計書を優先する
- Documentation Driven Development。`docs/` の更新（Task 1）を実装より先に行う
- Controller にビジネスロジックを書かない。Service = ビジネスロジック、Repository = 自テーブルのDB操作のみ
- Validation は FormRequest、レスポンスは Resource
- Carbon で時刻を保存する際は `->utc()` を通す。日付の解釈は `config('app.salon_timezone')`（`Asia/Tokyo`）基準
- TypeScript の `any` は使わない。過剰なコメントを避け、命名で意図を表現する
- バックエンドの整形は `./vendor/bin/pint`、フロントエンドは `npm run lint` と `npm run format`
- 既存顧客の `name` / `kana` / `gender` / `birthday` / `email` は公開予約から**一切上書きしない**
- マイグレーションによるスキーマ変更はない（追加するのはデータ移行1本のみ）

---

## File Structure

**バックエンド（作成）**

- `backend/database/migrations/2026_08_10_000001_backfill_customer_visit_dates.php` — 既存 visited 予約からの来店日一括埋め戻し
- `backend/tests/Feature/CustomerVisitDateTest.php` — 来店日の自動記録
- `backend/tests/Feature/BackfillCustomerVisitDatesTest.php` — 埋め戻しSQLの検証

**バックエンド（変更）**

- `backend/app/Repositories/ReservationRepository.php` — `visitDateRange()` を追加
- `backend/app/Repositories/CustomerRepository.php` — `updateVisitDates()` を追加
- `backend/app/Services/ReservationService.php` — `update()` / `delete()` から来店日を引き直す
- `backend/app/Http/Requests/PublicBooking/CreatePublicReservationRequest.php` — 追加項目のルール
- `backend/app/Services/PublicBookingService.php` — 新規顧客への追加項目保存、`note` の保存
- `backend/tests/Feature/PublicReservationApiTest.php` — 追加項目のテストと `book()` ヘルパの更新

**フロントエンド（作成）**

- `frontend/src/components/booking/BookingCustomerForm.vue` — ステップ4のフォーム

**フロントエンド（変更）**

- `frontend/src/types/publicBooking.ts` — リクエスト型の拡張
- `frontend/src/utils/publicBooking.ts` — 顧客情報のバリデーション純関数
- `frontend/src/utils/publicBooking.spec.ts` — 上記のテスト
- `frontend/src/pages/public/BookingPage.vue` — ステップ4の差し替え、確認画面、送信・エラー処理
- `frontend/src/services/mock/mockAdapter.ts` — モックAPIを新契約に追従

**ドキュメント（変更）**

- `docs/requirements/booking.md` / `docs/requirements/reservation.md`
- `docs/api/endpoints.md` / `docs/api/components/schemas/public-booking.yaml` / `docs/api/paths/public-booking.yaml`
- `docs/ui/public-booking.md`

---

## Task 1: ドキュメント更新（正典への反映）

CLAUDE.md の Documentation Driven Development に従い、実装より先に設計書の内容を `docs/` の正典へ反映する。

**Files:**
- Modify: `docs/requirements/booking.md`
- Modify: `docs/requirements/reservation.md`
- Modify: `docs/api/endpoints.md:1569-1576`
- Modify: `docs/api/components/schemas/public-booking.yaml:125-133`
- Modify: `docs/api/paths/public-booking.yaml:146-155`
- Modify: `docs/ui/public-booking.md:62-78`

**Interfaces:**
- Consumes: なし（先頭タスク）
- Produces: 以降のタスクが参照する正典。API のフィールド名は `is_first_visit` / `birthday` / `gender` / `email` / `note` で固定する

- [ ] **Step 1: `docs/requirements/booking.md` のバリデーション表に行を追加**

「### バリデーション（決定事項）」の表（`phone` の行の直後）に追加する。

```markdown
| is_first_visit | 必須・boolean（「新規ご来店」チェック。保存はせず、追加項目を受理するかの制御にのみ使う） |
| birthday | 任意・`Y-m-d`・サロンTZの本日以前。is_first_visit=false のときは検証データから除外する |
| gender | 任意・integer・0/1/2/9 のいずれか。is_first_visit=false のときは検証データから除外する |
| email | 任意・email 形式・最大255文字。is_first_visit=false のときは検証データから除外する |
| note | 任意・string・最大500文字（`reservations.note` へ保存。is_first_visit と無関係に受理する） |
```

- [ ] **Step 2: `docs/requirements/booking.md` の Business Rules 5 に追記**

「5. **顧客マッチング**」の項目の末尾（「なりすまし脅威の注記」の直前）に次の行を追加する。

```markdown
   - **追加項目の扱い**: 新規顧客を作成する場合のみ birthday / gender / email を保存する。既存顧客に紐付いた場合はこれらを**一切反映しない**（空欄の穴埋めも行わない）。他人の電話番号での予約による顧客カルテの改変を防ぐため
```

- [ ] **Step 3: `docs/requirements/booking.md` の 422 エラーキー割当に追記**

「422 のエラーキー割当（決定事項）」の段落の末尾に次の一文を追加する。

```markdown
追加項目（birthday / gender / email / note）のエラーはそれぞれ自身のキーで返し、UI は顧客情報ステップに留まってフィールド単位で表示する。
```

- [ ] **Step 4: `docs/requirements/reservation.md` の Business Rules に来店日の規則を追加**

「# Business Rules」の 6 の直後に 7 として追加する。

```markdown
7. **来店日の自動記録**: `customers.first_visit_at` / `last_visit_at` は、当該顧客の `status=visited` かつ未削除の予約から `MIN(start_at)` / `MAX(start_at)` を salon_timezone の日付に変換して都度引き直す。引き直しの契機は予約の更新（PATCH）と削除（DELETE）の2つで、対象は変更前後の customer_id。visited の予約が1件も無い場合は両方 null に戻す（誤操作の取り消しを自己修復するため）。予約作成時は status が必ず reserved のため引き直さない
```

- [ ] **Step 5: `docs/api/endpoints.md` のリクエスト表に行を追加**

`## POST /salons/{booking_slug}/reservations` の Request 表（`phone` の行の直後）に追加する。

```markdown
| is_first_visit | boolean | ✓ | 「新規ご来店」チェック。true の場合のみ birthday / gender / email を受理する（保存はしない） |
| birthday | date | | 生年月日（`YYYY-MM-DD`・サロンTZの本日以前）。新規顧客作成時のみ `customers.birthday` へ保存 |
| gender | integer | | 性別（0=未設定 / 1=男性 / 2=女性 / 9=その他）。新規顧客作成時のみ `customers.gender` へ保存 |
| email | string | | メールアドレス（最大255文字）。新規顧客作成時のみ `customers.email` へ保存 |
| note | string | | ご要望・気になること（最大500文字）。`reservations.note` へ保存 |
```

- [ ] **Step 6: `docs/api/components/schemas/public-booking.yaml` の `PublicReservationRequest` を拡張**

`required` に `is_first_visit` を追加し、`phone` プロパティの後に4つのプロパティを追加する。

```yaml
  required:
    - menu_id
    - start_at
    - name
    - kana
    - phone
    - is_first_visit
```

```yaml
    is_first_visit:
      type: boolean
      description: >
        「新規ご来店」チェック。true の場合のみ birthday / gender / email を受理する
        （false のときサーバは検証データから除外する）。値自体は保存しない
      example: true

    birthday:
      type: string
      format: date
      nullable: true
      description: 生年月日（サロンTZの本日以前）。新規顧客を作成する場合のみ customers.birthday へ保存する（既存顧客には反映しない）
      example: "1995-04-01"

    gender:
      type: integer
      enum: [0, 1, 2, 9]
      nullable: true
      description: 性別（0=未設定 / 1=男性 / 2=女性 / 9=その他）。新規顧客を作成する場合のみ customers.gender へ保存する（既存顧客には反映しない）
      example: 2

    email:
      type: string
      format: email
      maxLength: 255
      nullable: true
      description: メールアドレス。新規顧客を作成する場合のみ customers.email へ保存する（既存顧客には反映しない）
      example: hanako@example.com

    note:
      type: string
      maxLength: 500
      nullable: true
      description: ご要望・気になること。is_first_visit と無関係に受理し、reservations.note へ保存する
      example: 毛先を少し軽くしてほしいです
```

- [ ] **Step 7: `docs/api/paths/public-booking.yaml` の description に追記**

`publicReservations` の `description` 内、「不一致なら新規作成する。」の直後に次の2行を挿入する。

```yaml
      新規作成する場合のみ birthday / gender / email を顧客レコードへ保存し、既存顧客に紐付いた場合は一切反映しない。
      422 のエラーキーは追加項目（birthday / gender / email / note）についてはそれぞれ自身のキーとする。
```

- [ ] **Step 8: `docs/ui/public-booking.md` の Step 4 / Step 5 を更新**

「### Step 4: お客様情報入力」の表を次の内容に差し替え、注記を追加する。

```markdown
| 項目 | UI | 備考 |
|------|----|------|
| お名前 | InputText | 必須・最大100文字 |
| フリガナ | InputText | 必須・最大100文字。カタカナ入力を促すプレースホルダー（例: ヤマダ ハナコ） |
| 電話番号 | InputText（type=tel） | 必須・最大20文字。ハイフンなし可 |
| 新規ご来店 | Checkbox | 初期状態オフ。「当サロンのご利用が初めての方」の補足を添える |
| 生年月日 | DatePicker | 任意・**チェックオン時のみ表示**。選択可能上限は今日 |
| 性別 | Select | 任意・**チェックオン時のみ表示**。未設定 / 男性 / 女性 / その他（初期選択は未設定＝API へは null） |
| メールアドレス | InputText（type=email） | 任意・**チェックオン時のみ表示**・最大255文字 |
| ご要望・気になること | Textarea | 任意・常時表示・最大500文字。`reservations.note` へ保存する |

* チェックをオフに戻したら、生年月日・性別・メールアドレスの入力値をクリアする（確認画面と送信内容の不一致を防ぐ）
* ステップ4のフォームは `components/booking/BookingCustomerForm.vue` に切り出す（`BookingPage.vue` の肥大化を避けるため）
* 文字数上限等の詳細は API バリデーション（openapi.yaml）に従い、422 のメッセージをフィールド単位で表示する
* 電話番号は既存顧客との照合キーになる旨は表示しない（内部仕様のため）
```

「### Step 5: 確認」の1つ目の箇条書きを次に差し替える。

```markdown
* 選択内容のサマリを表示: メニュー（所要時間・価格）／担当（スタッフ名 または 指名なし）／日時（開始〜終了予定）／お名前・フリガナ・電話番号。「新規ご来店」がオンのときは、入力された生年月日・性別・メールアドレスの行を追加で表示する（未入力の項目は行ごと省略）。ご要望は入力があれば常に表示する
```

- [ ] **Step 9: エラー処理の記述を更新**

`docs/ui/public-booking.md` の「顧客情報のエラー（`name` / `kana` / `phone` キー）」の行を次に差し替える。

```markdown
  * 顧客情報のエラー（`name` / `kana` / `phone` / `birthday` / `gender` / `email` / `note` キー） → Step 4 に戻し、フィールド単位でエラーメッセージを表示する
```

- [ ] **Step 10: コミット**

```bash
git add docs/requirements/booking.md docs/requirements/reservation.md docs/api/endpoints.md docs/api/components/schemas/public-booking.yaml docs/api/paths/public-booking.yaml docs/ui/public-booking.md
git commit -m "docs: 公開Web予約の新規顧客登録と来店日自動記録を設計書へ反映"
```

---

## Task 2: 来店日の自動記録

**Files:**
- Modify: `backend/app/Repositories/ReservationRepository.php`
- Modify: `backend/app/Repositories/CustomerRepository.php`
- Modify: `backend/app/Services/ReservationService.php:98-156`
- Test: `backend/tests/Feature/CustomerVisitDateTest.php`

**Interfaces:**
- Consumes: なし
- Produces:
  - `ReservationRepository::visitDateRange(int $salonId, int $customerId): array{first: ?Carbon, last: ?Carbon}`
  - `CustomerRepository::updateVisitDates(int $salonId, int $customerId, ?string $firstVisitAt, ?string $lastVisitAt): void`
  - Task 3 の埋め戻しSQLは本タスクの再計算ロジックと同じ結果を返さなければならない

- [ ] **Step 1: 失敗するテストを書く**

`backend/tests/Feature/CustomerVisitDateTest.php` を新規作成する。

```php
<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class CustomerVisitDateTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_records_visit_dates_when_status_becomes_visited(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => ReservationStatus::Visited->value,
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('2026-08-10', $customer->first_visit_at?->toDateString());
        $this->assertSame('2026-08-10', $customer->last_visit_at?->toDateString());
    }

    public function test_clears_visit_dates_when_visited_is_reverted(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $reservation = $this->reservationAt(
            $user, $customer, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Visited,
        );
        $customer->update(['first_visit_at' => '2026-08-10', 'last_visit_at' => '2026-08-10']);

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => ReservationStatus::Cancelled->value,
        ])->assertOk();

        $customer->refresh();
        $this->assertNull($customer->first_visit_at);
        $this->assertNull($customer->last_visit_at);
    }

    public function test_uses_min_and_max_of_visited_reservations(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $this->reservationAt($user, $customer, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited);
        $this->reservationAt($user, $customer, $menu, '2026-07-01T10:00:00+09:00', ReservationStatus::Visited);
        $latest = $this->reservationAt($user, $customer, $menu, '2026-08-01T10:00:00+09:00');

        $this->patchJson("/api/v1/reservations/{$latest->id}", [
            'status' => ReservationStatus::Visited->value,
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('2026-06-01', $customer->first_visit_at?->toDateString());
        $this->assertSame('2026-08-01', $customer->last_visit_at?->toDateString());
    }

    public function test_recalculates_after_reservation_is_deleted(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $this->reservationAt($user, $customer, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited);
        $latest = $this->reservationAt($user, $customer, $menu, '2026-08-01T10:00:00+09:00', ReservationStatus::Visited);
        $customer->update(['first_visit_at' => '2026-06-01', 'last_visit_at' => '2026-08-01']);

        $this->deleteJson("/api/v1/reservations/{$latest->id}")->assertNoContent();

        $customer->refresh();
        $this->assertSame('2026-06-01', $customer->first_visit_at?->toDateString());
        $this->assertSame('2026-06-01', $customer->last_visit_at?->toDateString());
    }

    public function test_recalculates_both_customers_when_reservation_is_reassigned(): void
    {
        [$user, $from, $menu] = $this->createSalonContext();
        $to = Customer::factory()->for($user->salon)->create();
        $reservation = $this->reservationAt(
            $user, $from, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Visited,
        );
        $from->update(['first_visit_at' => '2026-08-10', 'last_visit_at' => '2026-08-10']);

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'customer_id' => $to->id,
        ])->assertOk();

        $from->refresh();
        $to->refresh();
        $this->assertNull($from->first_visit_at);
        $this->assertNull($from->last_visit_at);
        $this->assertSame('2026-08-10', $to->first_visit_at?->toDateString());
        $this->assertSame('2026-08-10', $to->last_visit_at?->toDateString());
    }

    public function test_uses_salon_timezone_for_the_date_boundary(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        // UTC 2026-08-09 15:00 = JST 2026-08-10 00:00
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T00:00:00+09:00');

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => ReservationStatus::Visited->value,
        ])->assertOk();

        $this->assertSame('2026-08-10', $customer->refresh()->first_visit_at?->toDateString());
    }

    public function test_ignores_reservations_of_other_customers(): void
    {
        [$user, $customer, $menu] = $this->createSalonContext();
        $other = Customer::factory()->for($user->salon)->create();
        $this->reservationAt($user, $other, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited);
        $reservation = $this->reservationAt($user, $customer, $menu, '2026-08-10T10:00:00+09:00');

        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => ReservationStatus::Visited->value,
        ])->assertOk();

        $this->assertSame('2026-08-10', $customer->refresh()->first_visit_at?->toDateString());
    }

    /**
     * @return array{0: User, 1: Customer, 2: Menu}
     */
    private function createSalonContext(): array
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create([
            'first_visit_at' => null,
            'last_visit_at' => null,
        ]);
        $menu = Menu::factory()->for($user->salon)->create(['duration_minutes' => 60]);

        return [$user, $customer, $menu];
    }

    private function reservationAt(
        User $user,
        Customer $customer,
        Menu $menu,
        string $startAt,
        ReservationStatus $status = ReservationStatus::Reserved,
    ): Reservation {
        $start = Carbon::parse($startAt)->utc();

        return Reservation::factory()->for($user->salon)->create([
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes($menu->duration_minutes),
            'status' => $status,
        ]);
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `cd backend && php artisan test --filter=CustomerVisitDateTest`
Expected: FAIL。`test_records_visit_dates_when_status_becomes_visited` が `Failed asserting that null is identical to '2026-08-10'` で落ちる

- [ ] **Step 3: `ReservationRepository::visitDateRange()` を追加**

`backend/app/Repositories/ReservationRepository.php` の `countFutureReservedByNormalizedPhone()` の直後に追加する。

```php
    /**
     * 顧客の来店日再計算用。status=visited の未削除予約の開始日時の最小・最大を返す。
     * 該当予約が無い場合は両方 null（＝来店日を null に戻す指示）。
     *
     * @return array{first: ?Carbon, last: ?Carbon}
     */
    public function visitDateRange(int $salonId, int $customerId): array
    {
        $row = Reservation::where('salon_id', $salonId)
            ->where('customer_id', $customerId)
            ->where('status', ReservationStatus::Visited->value)
            ->selectRaw('min(start_at) as first_start_at, max(start_at) as last_start_at')
            ->first();

        return [
            'first' => $row?->first_start_at === null ? null : Carbon::parse($row->first_start_at),
            'last' => $row?->last_start_at === null ? null : Carbon::parse($row->last_start_at),
        ];
    }
```

- [ ] **Step 4: `CustomerRepository::updateVisitDates()` を追加**

`backend/app/Repositories/CustomerRepository.php` の `update()` の直後に追加する。

```php
    /**
     * 来店日（visited 予約から再計算した値）だけを更新する。
     * 論理削除済みの顧客は対象外（復元時に予約更新で引き直される）。
     */
    public function updateVisitDates(
        int $salonId,
        int $customerId,
        ?string $firstVisitAt,
        ?string $lastVisitAt,
    ): void {
        Customer::where('salon_id', $salonId)
            ->whereKey($customerId)
            ->update([
                'first_visit_at' => $firstVisitAt,
                'last_visit_at' => $lastVisitAt,
            ]);
    }
```

- [ ] **Step 5: `ReservationService` から引き直す**

`backend/app/Services/ReservationService.php` の `update()` を変更する。`$previousUserId` を取る行の直後に `$previousCustomerId` を追加し、トランザクション内で引き直す。

```php
    public function update(int $salonId, int $id, array $data): Reservation
    {
        $reservation = $this->reservationRepository->findOrFail($salonId, $id);
        $previousUserId = $reservation->user_id;
        $previousCustomerId = $reservation->customer_id;
```

同メソッドの `DB::transaction(...)` の呼び出しを次に差し替える（`$previousCustomerId` を use に追加し、更新後に引き直す）。

```php
        $updated = DB::transaction(function () use ($salonId, $reservation, $attributes, $userId, $startAt, $endAt, $status, $previousCustomerId) {
            if (in_array($status, [ReservationStatus::Reserved, ReservationStatus::Visited], true)) {
                $this->assertNoDoubleBooking($salonId, $userId, $startAt, $endAt, $reservation->id);
            }

            $updated = $this->reservationRepository->update($reservation, $attributes);

            foreach (array_unique([$previousCustomerId, $updated->customer_id]) as $customerId) {
                $this->refreshVisitDates($salonId, $customerId);
            }

            return $updated;
        });
```

`delete()` を次に差し替える。

```php
    public function delete(int $salonId, int $id): void
    {
        $reservation = $this->reservationRepository->findOrFail($salonId, $id);

        DB::transaction(function () use ($salonId, $reservation) {
            $this->reservationRepository->delete($reservation);
            $this->refreshVisitDates($salonId, $reservation->customer_id);
        });

        // 論理削除（誤登録の取り消し）でも Google イベントを削除する（孤児イベントを残さない）
        $this->dispatchGoogleSync($reservation);
    }
```

`dispatchGoogleSync()` の直前に private メソッドを追加する。

```php
    /**
     * 顧客の来店日を status=visited の予約から引き直す。
     * 条件分岐を持たせず更新・削除で常に引き直すことで、visited の取り消し・
     * 予約削除・顧客の付け替えのいずれでも値が自己修復する。
     */
    private function refreshVisitDates(int $salonId, int $customerId): void
    {
        $timezone = config('app.salon_timezone');
        $range = $this->reservationRepository->visitDateRange($salonId, $customerId);

        $this->customerRepository->updateVisitDates(
            $salonId,
            $customerId,
            $range['first']?->copy()->setTimezone($timezone)->toDateString(),
            $range['last']?->copy()->setTimezone($timezone)->toDateString(),
        );
    }
```

- [ ] **Step 6: テストを実行して成功を確認**

Run: `cd backend && php artisan test --filter=CustomerVisitDateTest`
Expected: PASS（7件）

- [ ] **Step 7: 既存の予約テストが壊れていないことを確認**

Run: `cd backend && php artisan test --filter=ReservationApiTest`
Expected: PASS

- [ ] **Step 8: 整形してコミット**

```bash
cd backend && ./vendor/bin/pint app/Repositories/ReservationRepository.php app/Repositories/CustomerRepository.php app/Services/ReservationService.php tests/Feature/CustomerVisitDateTest.php
cd .. && git add backend/app/Repositories/ReservationRepository.php backend/app/Repositories/CustomerRepository.php backend/app/Services/ReservationService.php backend/tests/Feature/CustomerVisitDateTest.php
git commit -m "feat: 来店確定時に顧客の来店日を自動記録する"
```

---

## Task 3: 既存データのバックフィル

**Files:**
- Create: `backend/database/migrations/2026_08_10_000001_backfill_customer_visit_dates.php`
- Test: `backend/tests/Feature/BackfillCustomerVisitDatesTest.php`

**Interfaces:**
- Consumes: Task 2 の再計算ロジックと同一の結果になること（`status=visited` かつ `deleted_at IS NULL` の MIN/MAX をサロンTZの日付へ変換）
- Produces: なし

- [ ] **Step 1: 失敗するテストを書く**

`backend/tests/Feature/BackfillCustomerVisitDatesTest.php` を新規作成する。マイグレーションクラスの `up()` を直接呼んで検証する。

```php
<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BackfillCustomerVisitDatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fills_visit_dates_from_visited_reservations(): void
    {
        [$salon, $user, $menu] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create([
            'first_visit_at' => null,
            'last_visit_at' => null,
        ]);
        $this->reservationAt($salon, $user, $customer, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited);
        $this->reservationAt($salon, $user, $customer, $menu, '2026-08-01T10:00:00+09:00', ReservationStatus::Visited);
        $this->reservationAt($salon, $user, $customer, $menu, '2026-09-01T10:00:00+09:00');

        $this->runBackfill();

        $customer->refresh();
        $this->assertSame('2026-06-01', $customer->first_visit_at?->toDateString());
        $this->assertSame('2026-08-01', $customer->last_visit_at?->toDateString());
    }

    public function test_keeps_existing_values_for_customers_without_visited_reservations(): void
    {
        [$salon] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create([
            'first_visit_at' => '2025-01-01',
            'last_visit_at' => '2025-02-01',
        ]);

        $this->runBackfill();

        $customer->refresh();
        $this->assertSame('2025-01-01', $customer->first_visit_at?->toDateString());
        $this->assertSame('2025-02-01', $customer->last_visit_at?->toDateString());
    }

    public function test_ignores_soft_deleted_reservations(): void
    {
        [$salon, $user, $menu] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create([
            'first_visit_at' => null,
            'last_visit_at' => null,
        ]);
        $this->reservationAt($salon, $user, $customer, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited);
        $this->reservationAt($salon, $user, $customer, $menu, '2026-08-01T10:00:00+09:00', ReservationStatus::Visited)
            ->delete();

        $this->runBackfill();

        $this->assertSame('2026-06-01', $customer->refresh()->last_visit_at?->toDateString());
    }

    public function test_uses_salon_timezone_for_the_date_boundary(): void
    {
        [$salon, $user, $menu] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create([
            'first_visit_at' => null,
            'last_visit_at' => null,
        ]);
        // UTC 2026-08-09 15:00 = JST 2026-08-10 00:00
        $this->reservationAt($salon, $user, $customer, $menu, '2026-08-10T00:00:00+09:00', ReservationStatus::Visited);

        $this->runBackfill();

        $this->assertSame('2026-08-10', $customer->refresh()->first_visit_at?->toDateString());
    }

    private function runBackfill(): void
    {
        (include database_path('migrations/2026_08_10_000001_backfill_customer_visit_dates.php'))->up();
    }

    /**
     * @return array{0: Salon, 1: User, 2: Menu}
     */
    private function createContext(): array
    {
        $salon = Salon::factory()->create();

        return [
            $salon,
            User::factory()->for($salon)->create(),
            Menu::factory()->for($salon)->create(['duration_minutes' => 60]),
        ];
    }

    private function reservationAt(
        Salon $salon,
        User $user,
        Customer $customer,
        Menu $menu,
        string $startAt,
        ReservationStatus $status = ReservationStatus::Reserved,
    ): Reservation {
        $start = Carbon::parse($startAt)->utc();

        return Reservation::factory()->for($salon)->create([
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes($menu->duration_minutes),
            'status' => $status,
        ]);
    }
}
```

`runBackfill()` は無名クラスを返すマイグレーションファイルを `include` して `up()` を直接呼ぶ。`RefreshDatabase` により本体のマイグレーションは既に適用済みで、埋め戻しも1度走っているが、`up()` は冪等なテストデータ作成後にもう一度呼んで差し支えない。

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `cd backend && php artisan test --filter=BackfillCustomerVisitDatesTest`
Expected: FAIL。マイグレーションファイルが存在せず `include` に失敗する

- [ ] **Step 3: マイグレーションを作成**

`backend/database/migrations/2026_08_10_000001_backfill_customer_visit_dates.php` を新規作成する。

```php
<?php

use App\Enums\ReservationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * first_visit_at / last_visit_at は導入以来アプリから書き込まれておらず、
     * 既存顧客の値が空のままになっている。ReservationService の再計算と同じ定義
     * （status=visited・未削除の start_at をサロンTZの日付に変換した MIN/MAX）で埋め戻す。
     * visited 予約を持つ顧客のみ更新するため、既存の値を null で潰すことはない。
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customers c
               SET first_visit_at = v.first_visit_at,
                   last_visit_at  = v.last_visit_at
              FROM (SELECT customer_id,
                           MIN((start_at AT TIME ZONE ?)::date) AS first_visit_at,
                           MAX((start_at AT TIME ZONE ?)::date) AS last_visit_at
                      FROM reservations
                     WHERE status = ? AND deleted_at IS NULL
                     GROUP BY customer_id) v
             WHERE c.id = v.customer_id
        SQL, [
            config('app.salon_timezone'),
            config('app.salon_timezone'),
            ReservationStatus::Visited->value,
        ]);
    }

    /**
     * 埋め戻したデータは以後アプリが維持するため、巻き戻しでは何もしない
     * （null に戻すと正当な来店日まで失われる）。
     */
    public function down(): void {}
};
```

- [ ] **Step 4: テストを実行して成功を確認**

Run: `cd backend && php artisan test --filter=BackfillCustomerVisitDatesTest`
Expected: PASS（4件）

- [ ] **Step 5: マイグレーションが実行できることを確認**

Run: `cd backend && php artisan migrate --pretend`
Expected: エラーなく `2026_08_10_000001_backfill_customer_visit_dates` が一覧に出る

- [ ] **Step 6: 整形してコミット**

```bash
cd backend && ./vendor/bin/pint database/migrations/2026_08_10_000001_backfill_customer_visit_dates.php tests/Feature/BackfillCustomerVisitDatesTest.php
cd .. && git add backend/database/migrations/2026_08_10_000001_backfill_customer_visit_dates.php backend/tests/Feature/BackfillCustomerVisitDatesTest.php
git commit -m "feat: 既存の来店済み予約から顧客の来店日を埋め戻す"
```

---

## Task 4: 公開予約APIの追加項目

**Files:**
- Modify: `backend/app/Http/Requests/PublicBooking/CreatePublicReservationRequest.php`
- Modify: `backend/app/Services/PublicBookingService.php:117-126,220-229`
- Test: `backend/tests/Feature/PublicReservationApiTest.php`

**Interfaces:**
- Consumes: なし
- Produces: `POST /api/public/v1/salons/{booking_slug}/reservations` が `is_first_visit` / `birthday` / `gender` / `email` / `note` を受理する。Task 5・6 のフロントエンドがこの契約に従う

- [ ] **Step 1: 既存テストヘルパに `is_first_visit` を追加**

`is_first_visit` を必須にすると既存の20件超のテストが 422 になる。先に `backend/tests/Feature/PublicReservationApiTest.php` の `book()` の既定値へ追加する。

```php
    private function book(Salon $salon, array $overrides = []): TestResponse
    {
        $menuId = $overrides['menu_id'] ?? Menu::where('salon_id', $salon->id)->where('is_active', true)->value('id');

        return $this->postJson("/api/public/v1/salons/{$salon->booking_slug}/reservations", array_merge([
            'menu_id' => $menuId,
            'start_at' => self::START_AT,
            'name' => '山田 花子',
            'kana' => 'ヤマダ ハナコ',
            'phone' => '09012345678',
            'is_first_visit' => false,
        ], $overrides));
    }
```

- [ ] **Step 2: 失敗するテストを書く**

同ファイルの `test_creates_new_customer_when_phone_does_not_match()` の直後に追加する。

```php
    public function test_saves_additional_fields_to_the_new_customer_when_first_visit_is_checked(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, [
            'is_first_visit' => true,
            'birthday' => '1995-04-01',
            'gender' => 2,
            'email' => 'hanako@example.com',
        ]);

        $response->assertCreated();
        $customer = Customer::where('salon_id', $salon->id)->sole();
        $this->assertSame('1995-04-01', $customer->birthday?->toDateString());
        $this->assertSame(2, $customer->gender);
        $this->assertSame('hanako@example.com', $customer->email);
    }

    public function test_ignores_additional_fields_when_first_visit_is_not_checked(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, [
            'is_first_visit' => false,
            'birthday' => '1995-04-01',
            'gender' => 2,
            'email' => 'hanako@example.com',
        ]);

        $response->assertCreated();
        $customer = Customer::where('salon_id', $salon->id)->sole();
        $this->assertNull($customer->birthday);
        $this->assertNull($customer->gender);
        $this->assertNull($customer->email);
    }

    public function test_does_not_update_existing_customer_with_additional_fields(): void
    {
        [$salon] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create([
            'phone' => '090-1234-5678',
            'birthday' => null,
            'gender' => null,
            'email' => null,
        ]);

        $response = $this->book($salon, [
            'phone' => '09012345678',
            'is_first_visit' => true,
            'birthday' => '1995-04-01',
            'gender' => 2,
            'email' => 'hanako@example.com',
        ]);

        $response->assertCreated();
        $customer->refresh();
        $this->assertNull($customer->birthday);
        $this->assertNull($customer->gender);
        $this->assertNull($customer->email);
    }

    public function test_saves_note_to_the_reservation(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['note' => '毛先を少し軽くしてほしいです']);

        $response->assertCreated();
        $this->assertSame('毛先を少し軽くしてほしいです', Reservation::sole()->note);
    }

    public function test_saves_note_even_when_first_visit_is_not_checked(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['is_first_visit' => false, 'note' => 'アレルギーがあります']);

        $response->assertCreated();
        $this->assertSame('アレルギーがあります', Reservation::sole()->note);
    }

    public function test_requires_is_first_visit(): void
    {
        [$salon, $menu] = $this->createContext();

        $response = $this->postJson("/api/public/v1/salons/{$salon->booking_slug}/reservations", [
            'menu_id' => $menu->id,
            'start_at' => self::START_AT,
            'name' => '山田 花子',
            'kana' => 'ヤマダ ハナコ',
            'phone' => '09012345678',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('is_first_visit');
    }

    public function test_returns_422_for_a_future_birthday(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['is_first_visit' => true, 'birthday' => '2026-12-31']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('birthday');
    }

    public function test_returns_422_for_an_out_of_range_gender(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['is_first_visit' => true, 'gender' => 3]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('gender');
    }

    public function test_returns_422_for_an_invalid_email(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['is_first_visit' => true, 'email' => 'not-an-email']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_returns_422_for_a_note_longer_than_500_characters(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['note' => str_repeat('あ', 501)]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('note');
    }
```

- [ ] **Step 3: テストを実行して失敗を確認**

Run: `cd backend && php artisan test --filter=PublicReservationApiTest`
Expected: FAIL。`test_saves_additional_fields_to_the_new_customer_when_first_visit_is_checked` が `Failed asserting that null is identical to '1995-04-01'`、`test_requires_is_first_visit` が 201 を返して落ちる

- [ ] **Step 4: FormRequest にルールを追加**

`backend/app/Http/Requests/PublicBooking/CreatePublicReservationRequest.php` の `rules()` を差し替える。

```php
    public function rules(): array
    {
        // 「今日」はサロンTZ基準で決める（アプリTZ=UTC で判定すると JST 00:00〜09:00 に前日扱いになる）
        $today = now(config('app.salon_timezone'))->toDateString();

        return [
            'menu_id' => ['required', 'integer'],
            'user_id' => ['nullable', 'integer'],
            // オフセット無しはUTC解釈で意図と9時間ずれるため、ISO 8601 オフセット付きのみ受理
            'start_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP'],
            'name' => ['required', 'string', 'max:100'],
            'kana' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
            // boolean ルールは必須。これが無いと exclude_unless が 'true' を文字列比較して常に除外される
            'is_first_visit' => ['required', 'boolean'],
            'birthday' => ['exclude_unless:is_first_visit,true', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'gender' => ['exclude_unless:is_first_visit,true', 'nullable', 'integer', 'in:0,1,2,9'],
            'email' => ['exclude_unless:is_first_visit,true', 'nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
```

- [ ] **Step 5: Service で追加項目と note を保存**

`backend/app/Services/PublicBookingService.php` の `resolveCustomer()` を差し替える。

```php
    /**
     * 正規化 phone が一致する既存顧客（複数一致は id 最小）に紐付ける。
     * 既存顧客には入力値を反映しない（name / kana に加え、新規ご来店の追加項目も上書きしない）。
     * 他人の電話番号での予約による顧客カルテの改変を防ぐため。
     */
    private function resolveCustomer(int $salonId, string $phone, array $data): Customer
    {
        $customer = $this->customerRepository->findFirstByNormalizedPhone($salonId, $phone);

        if ($customer !== null) {
            return $customer;
        }

        return $this->customerRepository->create($salonId, [
            'name' => $data['name'],
            'kana' => $data['kana'],
            'phone' => $phone,
            'gender' => $data['gender'] ?? null,
            'birthday' => $data['birthday'] ?? null,
            'email' => $data['email'] ?? null,
        ]);
    }
```

同ファイルの `reservationRepository->create()` の引数配列に `note` を追加する（`booking_token` の行の直後）。

```php
                'booking_token' => Str::random(self::BOOKING_TOKEN_LENGTH),
                'note' => $data['note'] ?? null,
```

- [ ] **Step 6: テストを実行して成功を確認**

Run: `cd backend && php artisan test --filter=PublicReservationApiTest`
Expected: PASS（既存＋新規10件）

- [ ] **Step 7: バックエンド全体のテストを実行**

Run: `cd backend && composer test`
Expected: PASS

- [ ] **Step 8: 整形してコミット**

```bash
cd backend && ./vendor/bin/pint app/Http/Requests/PublicBooking/CreatePublicReservationRequest.php app/Services/PublicBookingService.php tests/Feature/PublicReservationApiTest.php
cd .. && git add backend/app/Http/Requests/PublicBooking/CreatePublicReservationRequest.php backend/app/Services/PublicBookingService.php backend/tests/Feature/PublicReservationApiTest.php
git commit -m "feat: 公開Web予約で新規顧客の生年月日・性別・メールと予約メモを受け取る"
```

---

## Task 5: フロントエンドの型・バリデーション・モック

**Files:**
- Modify: `frontend/src/types/publicBooking.ts:38-46`
- Modify: `frontend/src/utils/publicBooking.ts`
- Modify: `frontend/src/services/mock/mockAdapter.ts:1133-1150`
- Test: `frontend/src/utils/publicBooking.spec.ts`

**Interfaces:**
- Consumes: Task 4 の API 契約（`is_first_visit` / `birthday` / `gender` / `email` / `note`）
- Produces:
  - `PublicReservationRequest`（`is_first_visit: boolean` 必須、他4項目は任意）
  - `BookingCustomerFormState` — フォームの状態オブジェクト
  - `BookingCustomerErrors` — サーバのエラーキーと同じ7キーの文字列レコード
  - `emptyBookingCustomerErrors(): BookingCustomerErrors`
  - `validateBookingCustomer(state: BookingCustomerFormState, today?: Date): BookingCustomerErrors`
  - `hasBookingCustomerError(errors: BookingCustomerErrors): boolean`
  - `BOOKING_NOTE_MAX_LENGTH = 500`
  - Task 6 の `BookingCustomerForm.vue` と `BookingPage.vue` がこれらを使う

- [ ] **Step 1: 失敗するテストを書く**

`frontend/src/utils/publicBooking.spec.ts` の import に追加する。

```ts
import {
  BOOKING_NOTE_MAX_LENGTH,
  bookingSelectableRange,
  buildBookingPageUrl,
  buildCancelUrl,
  calcEndAtIso,
  emptyBookingCustomerErrors,
  formatDateTimeRange,
  hasBookingCustomerError,
  isWithinBookingWindow,
  listSlotStartMinutes,
  slotToIso,
  validateBookingCustomer,
} from './publicBooking'
import type { BookingCustomerFormState } from './publicBooking'
```

ファイル末尾に追加する。

```ts
const customerState = (overrides: Partial<BookingCustomerFormState> = {}): BookingCustomerFormState => ({
  name: '山田 花子',
  kana: 'ヤマダ ハナコ',
  phone: '09012345678',
  isFirstVisit: false,
  birthday: null,
  gender: null,
  email: '',
  note: '',
  ...overrides,
})

describe('validateBookingCustomer', () => {
  it('必須3項目が埋まっていればエラーなし', () => {
    expect(hasBookingCustomerError(validateBookingCustomer(customerState()))).toBe(false)
  })

  it('氏名・フリガナ・電話番号の未入力をそれぞれ検出する', () => {
    const errors = validateBookingCustomer(customerState({ name: ' ', kana: '', phone: '' }))
    expect(errors.name).toBe('お名前を入力してください')
    expect(errors.kana).toBe('フリガナを入力してください')
    expect(errors.phone).toBe('電話番号を入力してください')
  })

  it('氏名・フリガナの100文字超と電話番号の20文字超を検出する', () => {
    const errors = validateBookingCustomer(
      customerState({ name: 'あ'.repeat(101), kana: 'ア'.repeat(101), phone: '0'.repeat(21) }),
    )
    expect(errors.name).toBe('お名前は100文字以内で入力してください')
    expect(errors.kana).toBe('フリガナは100文字以内で入力してください')
    expect(errors.phone).toBe('電話番号は20文字以内で入力してください')
  })

  it('新規ご来店がオンのとき未来の生年月日を弾く', () => {
    const today = new Date(2026, 7, 10)
    const errors = validateBookingCustomer(
      customerState({ isFirstVisit: true, birthday: new Date(2026, 7, 11) }),
      today,
    )
    expect(errors.birthday).toBe('生年月日は今日以前の日付を入力してください')
  })

  it('新規ご来店がオンでも今日の生年月日は許容する', () => {
    const today = new Date(2026, 7, 10)
    const errors = validateBookingCustomer(
      customerState({ isFirstVisit: true, birthday: new Date(2026, 7, 10) }),
      today,
    )
    expect(errors.birthday).toBe('')
  })

  it('新規ご来店がオンのときメールアドレスの形式を検証する', () => {
    const invalid = validateBookingCustomer(customerState({ isFirstVisit: true, email: 'not-an-email' }))
    const valid = validateBookingCustomer(customerState({ isFirstVisit: true, email: 'hanako@example.com' }))
    expect(invalid.email).toBe('メールアドレスの形式が正しくありません')
    expect(valid.email).toBe('')
  })

  it('新規ご来店がオフなら追加項目を検証しない', () => {
    const errors = validateBookingCustomer(
      customerState({ isFirstVisit: false, email: 'not-an-email', birthday: new Date(2099, 0, 1) }),
    )
    expect(errors.email).toBe('')
    expect(errors.birthday).toBe('')
  })

  it('ご要望の文字数上限を検証する', () => {
    const errors = validateBookingCustomer(
      customerState({ note: 'あ'.repeat(BOOKING_NOTE_MAX_LENGTH + 1) }),
    )
    expect(errors.note).toBe('ご要望は500文字以内で入力してください')
  })
})

describe('hasBookingCustomerError', () => {
  it('全て空文字なら false', () => {
    expect(hasBookingCustomerError(emptyBookingCustomerErrors())).toBe(false)
  })

  it('1つでもメッセージがあれば true', () => {
    expect(hasBookingCustomerError({ ...emptyBookingCustomerErrors(), gender: 'エラー' })).toBe(true)
  })
})
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `cd frontend && npx vitest run src/utils/publicBooking.spec.ts`
Expected: FAIL。`validateBookingCustomer` などが `publicBooking.ts` から export されていない

- [ ] **Step 3: `utils/publicBooking.ts` にバリデーションを追加**

`frontend/src/utils/publicBooking.ts` の import 行を差し替える。

```ts
import type { BusinessHour, Gender } from '@/types'
import { formatDate, formatTime, toIsoWithOffset, weekdayLabel } from './format'
import { hhmmToMinutes } from './reservationCalendar'
```

ファイル末尾に追加する。

```ts
/** ご要望（reservations.note）の文字数上限。公開APIの max:500 と対応する */
export const BOOKING_NOTE_MAX_LENGTH = 500

/** 公開予約ページ ステップ4 のフォーム状態 */
export interface BookingCustomerFormState {
  name: string
  kana: string
  phone: string
  isFirstVisit: boolean
  birthday: Date | null
  gender: Gender | null
  email: string
  note: string
}

/** フィールド単位のエラーメッセージ（空文字＝エラーなし）。キーはサーバの422エラーキーと揃える */
export type BookingCustomerErrors = Record<
  'name' | 'kana' | 'phone' | 'birthday' | 'gender' | 'email' | 'note',
  string
>

export function emptyBookingCustomerErrors(): BookingCustomerErrors {
  return { name: '', kana: '', phone: '', birthday: '', gender: '', email: '', note: '' }
}

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** 追加項目は「新規ご来店」がオンのときだけ検証する（オフならサーバも受け取らない） */
export function validateBookingCustomer(
  state: BookingCustomerFormState,
  today: Date = new Date(),
): BookingCustomerErrors {
  const errors = emptyBookingCustomerErrors()

  errors.name =
    state.name.trim() === ''
      ? 'お名前を入力してください'
      : state.name.length > 100
        ? 'お名前は100文字以内で入力してください'
        : ''
  errors.kana =
    state.kana.trim() === ''
      ? 'フリガナを入力してください'
      : state.kana.length > 100
        ? 'フリガナは100文字以内で入力してください'
        : ''
  errors.phone =
    state.phone.trim() === ''
      ? '電話番号を入力してください'
      : state.phone.length > 20
        ? '電話番号は20文字以内で入力してください'
        : ''

  if (state.isFirstVisit) {
    const endOfToday = new Date(today)
    endOfToday.setHours(23, 59, 59, 999)
    errors.birthday =
      state.birthday !== null && state.birthday.getTime() > endOfToday.getTime()
        ? '生年月日は今日以前の日付を入力してください'
        : ''
    errors.email =
      state.email.trim() !== '' && !EMAIL_PATTERN.test(state.email.trim())
        ? 'メールアドレスの形式が正しくありません'
        : ''
  }

  errors.note =
    state.note.length > BOOKING_NOTE_MAX_LENGTH
      ? `ご要望は${BOOKING_NOTE_MAX_LENGTH}文字以内で入力してください`
      : ''

  return errors
}

export function hasBookingCustomerError(errors: BookingCustomerErrors): boolean {
  return Object.values(errors).some((message) => message !== '')
}
```

- [ ] **Step 4: テストを実行して成功を確認**

Run: `cd frontend && npx vitest run src/utils/publicBooking.spec.ts`
Expected: PASS

- [ ] **Step 5: リクエスト型を拡張**

`frontend/src/types/publicBooking.ts` の1行目の import に `Gender` を追加し、`PublicReservationRequest` を差し替える。

```ts
import type { BusinessHour } from './businessHour'
import type { Gender } from './customer'
import type { ReservationStatus } from './reservation'
```

```ts
/** POST /salons/{booking_slug}/reservations リクエスト（user_id null/省略は指名なし） */
export interface PublicReservationRequest {
  menu_id: number
  user_id?: number | null
  start_at: string
  name: string
  kana: string
  phone: string
  /** 「新規ご来店」チェック。true のときのみサーバが birthday / gender / email を受理する */
  is_first_visit: boolean
  birthday?: string | null
  gender?: Gender | null
  email?: string | null
  note?: string | null
}
```

`Gender` は `frontend/src/types/index.ts` の `export * from './customer'` 経由でも公開されるが、同一ディレクトリ内なので相対 import にする（既存の `BusinessHour` / `ReservationStatus` と同じ書き方）。

- [ ] **Step 6: モックアダプタを新契約に追従させる**

`frontend/src/services/mock/mockAdapter.ts` の公開予約作成部分、新規顧客を組み立てる箇所を差し替える。

```ts
      if (!customer) {
        const nowIso = new Date().toISOString()
        customer = {
          id: nextCustomerId++,
          name: input.name,
          kana: input.kana,
          gender: input.is_first_visit ? (input.gender ?? null) : null,
          birthday: input.is_first_visit ? (input.birthday ?? null) : null,
          phone: input.phone,
          email: input.is_first_visit ? (input.email ?? null) : null,
          memo: null,
          first_visit_at: null,
          last_visit_at: null,
          created_at: nowIso,
          updated_at: nowIso,
        }
        customers = [customer, ...customers]
      }
```

同じブロックで組み立てている `const reservation: Reservation = { ... }` の `note` プロパティを次に差し替える（`note` プロパティが無ければ `status` の直後に追加する）。

```ts
        note: input.note?.trim() ? input.note.trim() : null,
```

- [ ] **Step 7: 型チェックと lint を通す**

Run: `cd frontend && npm run type-check && npm run lint`
Expected: エラーなし

- [ ] **Step 8: コミット**

```bash
cd frontend && npm run format
cd .. && git add frontend/src/types/publicBooking.ts frontend/src/utils/publicBooking.ts frontend/src/utils/publicBooking.spec.ts frontend/src/services/mock/mockAdapter.ts
git commit -m "feat: 公開予約の顧客情報バリデーションと型を追加項目に対応させる"
```

---

## Task 6: 公開予約ページ ステップ4のフォーム

**Files:**
- Create: `frontend/src/components/booking/BookingCustomerForm.vue`
- Modify: `frontend/src/pages/public/BookingPage.vue`

**Interfaces:**
- Consumes: Task 5 の `BookingCustomerFormState` / `BookingCustomerErrors` / `validateBookingCustomer` / `hasBookingCustomerError` / `emptyBookingCustomerErrors`、Task 4 の API 契約
- Produces: なし（最終タスク）

- [ ] **Step 1: `BookingCustomerForm.vue` を作成**

`frontend/src/components/booking/BookingCustomerForm.vue` を新規作成する。

```vue
<script setup lang="ts">
import { watch } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { genderLabel } from '@/utils/format'
import { BOOKING_NOTE_MAX_LENGTH } from '@/utils/publicBooking'
import type { BookingCustomerErrors, BookingCustomerFormState } from '@/utils/publicBooking'
import type { Gender } from '@/types'

defineProps<{
  errors: BookingCustomerErrors
  submitting: boolean
}>()

const emit = defineEmits<{ submit: [] }>()

const form = defineModel<BookingCustomerFormState>({ required: true })

const genderOptions: { label: string; value: Gender }[] = [
  { label: genderLabel(0), value: 0 },
  { label: genderLabel(1), value: 1 },
  { label: genderLabel(2), value: 2 },
  { label: genderLabel(9), value: 9 },
]

const today = new Date()

// チェックを外したら追加項目を破棄する（確認画面と送信内容の不一致を防ぐ）
watch(
  () => form.value.isFirstVisit,
  (isFirstVisit) => {
    if (isFirstVisit) return
    form.value.birthday = null
    form.value.gender = null
    form.value.email = ''
  },
)
</script>

<template>
  <form class="customer-form" novalidate @submit.prevent="emit('submit')">
    <div class="field">
      <label class="field-label" for="booking-name">お名前</label>
      <InputText
        id="booking-name"
        v-model="form.name"
        autocomplete="name"
        placeholder="山田 花子"
        maxlength="100"
        fluid
        :invalid="errors.name !== ''"
      />
      <small v-if="errors.name" class="field-error">
        <i class="pi pi-exclamation-circle" />
        {{ errors.name }}
      </small>
    </div>

    <div class="field">
      <label class="field-label" for="booking-kana">フリガナ</label>
      <InputText
        id="booking-kana"
        v-model="form.kana"
        placeholder="ヤマダ ハナコ"
        maxlength="100"
        fluid
        :invalid="errors.kana !== ''"
      />
      <small v-if="errors.kana" class="field-error">
        <i class="pi pi-exclamation-circle" />
        {{ errors.kana }}
      </small>
    </div>

    <div class="field">
      <label class="field-label" for="booking-phone">電話番号</label>
      <InputText
        id="booking-phone"
        v-model="form.phone"
        type="tel"
        autocomplete="tel"
        placeholder="09012345678"
        maxlength="20"
        fluid
        :invalid="errors.phone !== ''"
      />
      <small v-if="errors.phone" class="field-error">
        <i class="pi pi-exclamation-circle" />
        {{ errors.phone }}
      </small>
    </div>

    <div class="first-visit">
      <div class="first-visit-head">
        <Checkbox v-model="form.isFirstVisit" input-id="booking-first-visit" binary />
        <label for="booking-first-visit">
          <span class="first-visit-title">新規ご来店</span>
          <span class="first-visit-note">当サロンのご利用が初めての方</span>
        </label>
      </div>

      <div v-if="form.isFirstVisit" class="first-visit-body">
        <div class="field">
          <label class="field-label" for="booking-birthday">生年月日</label>
          <DatePicker
            v-model="form.birthday"
            input-id="booking-birthday"
            date-format="yy/mm/dd"
            :max-date="today"
            show-icon
            icon-display="input"
            fluid
            placeholder="1995/04/01"
            :invalid="errors.birthday !== ''"
          />
          <small v-if="errors.birthday" class="field-error">
            <i class="pi pi-exclamation-circle" />
            {{ errors.birthday }}
          </small>
        </div>

        <div class="field">
          <label class="field-label" for="booking-gender">性別</label>
          <Select
            id="booking-gender"
            v-model="form.gender"
            :options="genderOptions"
            option-label="label"
            option-value="value"
            placeholder="未設定"
            show-clear
            fluid
            :invalid="errors.gender !== ''"
          />
          <small v-if="errors.gender" class="field-error">
            <i class="pi pi-exclamation-circle" />
            {{ errors.gender }}
          </small>
        </div>

        <div class="field">
          <label class="field-label" for="booking-email">メールアドレス</label>
          <InputText
            id="booking-email"
            v-model="form.email"
            type="email"
            autocomplete="email"
            placeholder="hanako@example.com"
            maxlength="255"
            fluid
            :invalid="errors.email !== ''"
          />
          <small v-if="errors.email" class="field-error">
            <i class="pi pi-exclamation-circle" />
            {{ errors.email }}
          </small>
        </div>
      </div>
    </div>

    <div class="field">
      <label class="field-label" for="booking-note">ご要望・気になること</label>
      <Textarea
        id="booking-note"
        v-model="form.note"
        auto-resize
        :rows="3"
        :maxlength="BOOKING_NOTE_MAX_LENGTH"
        fluid
        placeholder="施術のご希望やお困りごとがあればご記入ください"
        :invalid="errors.note !== ''"
      />
      <small v-if="errors.note" class="field-error">
        <i class="pi pi-exclamation-circle" />
        {{ errors.note }}
      </small>
    </div>

    <Button
      type="submit"
      label="次へ"
      icon="pi pi-arrow-right"
      icon-pos="right"
      fluid
      :disabled="submitting"
    />
  </form>
</template>

<style scoped>
.customer-form {
  display: flex;
  flex-direction: column;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-bottom: 1rem;
}

.field-label {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--rb-text);
  margin: 0;
}

.field-error {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.76rem;
  color: var(--rb-pink-strong);
}

.first-visit {
  margin-bottom: 1rem;
  padding: 0.9rem 1rem;
  border-radius: var(--rb-radius-md);
  border: 1px dashed var(--rb-pink-soft);
  background: var(--rb-pink-faint);
}

.first-visit-head {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
}

.first-visit-head label {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  cursor: pointer;
}

.first-visit-title {
  font-size: 0.9rem;
  font-weight: 700;
  color: var(--rb-text);
}

.first-visit-note {
  font-size: 0.76rem;
  color: var(--rb-text-muted);
}

.first-visit-body {
  margin-top: 0.9rem;
}

.first-visit-body .field:last-child {
  margin-bottom: 0;
}
</style>
```

- [ ] **Step 2: 開発サーバで表示を確認**

Run: `cd frontend && VITE_USE_MOCK=true npm run dev`
`/booking/rbmocksalon00001` を開き、ステップ4で「新規ご来店」のオン／オフにより3項目が開閉すること、オフに戻すと入力値が消えることを確認する。

- [ ] **Step 3: `BookingPage.vue` のスクリプトを差し替え**

`frontend/src/pages/public/BookingPage.vue` の import を差し替える。

```ts
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { AxiosError } from 'axios'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import BookingCustomerForm from '@/components/booking/BookingCustomerForm.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import PublicLayout from '@/layouts/PublicLayout.vue'
import { publicBookingService } from '@/services/publicBookingService'
import { extractErrorMessage, extractFieldErrors } from '@/utils/apiError'
import { formatNumber, formatTime, genderLabel, toDateString } from '@/utils/format'
import {
  bookingSelectableRange,
  buildCancelUrl,
  calcEndAtIso,
  emptyBookingCustomerErrors,
  formatDateTimeRange,
  hasBookingCustomerError,
  validateBookingCustomer,
} from '@/utils/publicBooking'
import type { BookingCustomerFormState } from '@/utils/publicBooking'
import type {
  AvailabilitySlot,
  PublicMenu,
  PublicReservationRequest,
  PublicReservationResponse,
  PublicSalon,
  PublicStaff,
} from '@/types'
```

`InputText` と `reactive` は本ファイルで使わなくなるため import から外すこと。

`const form = reactive(...)` と `const fieldErrors = reactive(...)` の2行を次に差し替える。

```ts
const form = ref<BookingCustomerFormState>({
  name: '',
  kana: '',
  phone: '',
  isFirstVisit: false,
  birthday: null,
  gender: null,
  email: '',
  note: '',
})
const fieldErrors = ref(emptyBookingCustomerErrors())
```

- [ ] **Step 4: バリデーションと送信処理を差し替え**

`validateCustomerForm()` と `goConfirm()` を次に差し替える。

```ts
function goConfirm(): void {
  fieldErrors.value = validateBookingCustomer(form.value)
  if (!hasBookingCustomerError(fieldErrors.value)) step.value = 5
}
```

`submitReservation()` を次に差し替える。

```ts
async function submitReservation(): Promise<void> {
  if (submitting.value || !selectedMenu.value || !selectedSlot.value) return
  submitting.value = true
  try {
    const customer = form.value
    const payload: PublicReservationRequest = {
      menu_id: selectedMenu.value.id,
      user_id: selectedStaff.value?.id ?? null,
      start_at: selectedSlot.value,
      name: customer.name,
      kana: customer.kana,
      phone: customer.phone,
      is_first_visit: customer.isFirstVisit,
      note: customer.note.trim() !== '' ? customer.note.trim() : null,
    }
    if (customer.isFirstVisit) {
      payload.birthday = customer.birthday ? toDateString(customer.birthday) : null
      payload.gender = customer.gender
      payload.email = customer.email.trim() !== '' ? customer.email.trim() : null
    }
    completed.value = await publicBookingService.createReservation(bookingSlug.value, payload)
  } catch (error) {
    handleSubmitError(error)
  } finally {
    submitting.value = false
  }
}
```

`handleSubmitError()` を次に差し替える。

```ts
function handleSubmitError(error: unknown): void {
  const status = error instanceof AxiosError ? error.response?.status : undefined
  if (status === 422) {
    const errors = extractFieldErrors(error)
    if (errors.start_at) {
      // 時間帯系エラー: サーバメッセージを表示し、空き枠を再取得して日時選択へ戻す
      startAtError.value = errors.start_at
      selectedSlot.value = null
      step.value = 3
      void fetchSlots()
      return
    }
    const customerErrors = emptyBookingCustomerErrors()
    for (const key of Object.keys(customerErrors) as (keyof typeof customerErrors)[]) {
      customerErrors[key] = errors[key] ?? ''
    }
    fieldErrors.value = customerErrors
    if (hasBookingCustomerError(customerErrors)) {
      step.value = 4
      return
    }
  }
  const summary =
    status === 429 ? THROTTLE_MESSAGE : extractErrorMessage(error, '予約の登録に失敗しました')
  toast.add({ severity: 'error', summary, life: 4000 })
}
```

- [ ] **Step 5: ステップ4のテンプレートをコンポーネントに差し替え**

`<!-- Step 4: お客様情報入力 -->` の `<template v-else-if="step === 4">` ブロックの中身（`<h2>` を除く `<form>` 全体）を次に差し替える。

```vue
          <!-- Step 4: お客様情報入力 -->
          <template v-else-if="step === 4">
            <h2 class="step-title">お客様情報をご入力ください</h2>
            <BookingCustomerForm
              v-model="form"
              :errors="fieldErrors"
              :submitting="submitting"
              @submit="goConfirm"
            />
          </template>
```

- [ ] **Step 6: 確認画面に追加項目を表示**

ステップ5の `<dl class="summary">` 内、電話番号の行の直後に追加する。

```vue
              <div v-if="form.isFirstVisit" class="summary-row">
                <dt>ご来店</dt>
                <dd>今回が初めて</dd>
              </div>
              <div v-if="form.isFirstVisit && form.birthday" class="summary-row">
                <dt>生年月日</dt>
                <dd>{{ toDateString(form.birthday) }}</dd>
              </div>
              <div v-if="form.isFirstVisit && form.gender !== null" class="summary-row">
                <dt>性別</dt>
                <dd>{{ genderLabel(form.gender) }}</dd>
              </div>
              <div v-if="form.isFirstVisit && form.email.trim() !== ''" class="summary-row">
                <dt>メール</dt>
                <dd>{{ form.email }}</dd>
              </div>
              <div v-if="form.note.trim() !== ''" class="summary-row">
                <dt>ご要望</dt>
                <dd>{{ form.note }}</dd>
              </div>
```

- [ ] **Step 7: 未使用になったスタイルを削除**

`BookingPage.vue` の `<style scoped>` から `.customer-form` の定義を削除する。`.field` / `.field-label` / `.field .field-label` / `.field-error` はステップ3の日付欄でも使われているため残す。削除後に開発サーバで見た目が崩れていないことを確認する。

- [ ] **Step 8: 型チェックと lint を通す**

Run: `cd frontend && npm run type-check && npm run lint`
Expected: エラーなし

- [ ] **Step 9: フロントエンドのテストを実行**

Run: `cd frontend && npx vitest run`
Expected: PASS

- [ ] **Step 10: モックで通しの動作を確認**

Run: `cd frontend && VITE_USE_MOCK=true npm run dev`
チェックオンで3項目を入力 → 確認画面に表示される → 予約確定まで通ること、チェックオフで従来どおり進めることを確認する。

- [ ] **Step 11: ビルドが通ることを確認**

Run: `cd frontend && npm run build`
Expected: 成功

- [ ] **Step 12: コミット**

```bash
cd frontend && npm run format
cd .. && git add frontend/src/components/booking/BookingCustomerForm.vue frontend/src/pages/public/BookingPage.vue
git commit -m "feat: 公開予約ページに新規ご来店チェックと顧客情報の追加入力欄を設置"
```

---

## 完了確認

- [ ] `cd backend && composer test` が全て PASS
- [ ] `cd frontend && npm run type-check && npm run lint && npx vitest run && npm run build` が全て成功
- [ ] `git log --oneline` に6つのコミットが並ぶ（docs 1・backend 3・frontend 2）
