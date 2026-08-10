# 公開Web予約の新規顧客登録 — 設計書

作成日: 2026-08-10
対象フェーズ: フェーズ2（Web予約・LINE連携）の追補

---

## Purpose

公開Web予約ページで、初めて来店する顧客が予約と同時に顧客カルテの基本情報を登録できるようにする。
あわせて、これまでアプリから一度も書き込まれていなかった `first_visit_at` / `last_visit_at` を来店確定時に自動記録し、
ダッシュボードの「今月の新規」と顧客一覧の「最終来店」を機能させる。

---

## Background

現状の到達点:

- 公開Web予約（`POST /api/public/v1/salons/{booking_slug}/reservations`）は氏名・フリガナ・電話番号のみを受け取る
- 電話番号（正規化後）が一致する顧客がいれば紐付け、いなければ**すでに顧客を自動生成している**（`PublicBookingService::resolveCustomer`）
- そのため新規顧客のレコードは氏名・フリガナ・電話番号だけの状態で作られ、性別・生年月日・メールは空のまま
- `customers.first_visit_at` / `customers.last_visit_at` はアプリコードから**一度も書き込まれていない**。
  読み出しは `DashboardRepository`（今月の新規・最終来店順）と `CustomerRepository`（`visited_after` / `visited_before` フィルタ、`last_visit_at` ソート）、
  `CustomerDetailPage` / `CustomerListPage` の表示にあるが、値は常に null（シードデータを除く）

本設計はこの2点を埋める。

---

## Scope

### In Scope

1. 公開Web予約ページのステップ4「お客様情報」に「新規ご来店」チェックボックスを設置する
2. チェックオン時のみ、生年月日・性別・メールアドレス（すべて任意）を入力できるようにする
3. チェックと無関係に「ご要望・気になること」（任意）を入力できるようにし、`reservations.note` へ保存する
4. 新規顧客を作成する際、入力された生年月日・性別・メールアドレスを `customers` に保存する
5. 予約が `status=visited` になったとき `customers.first_visit_at` / `last_visit_at` を自動更新する（都度再計算方式）
6. 既存の `status=visited` 予約から来店日を一括で埋めるデータマイグレーションを追加する

### Out of Scope

- サロン側予約フォーム（`ReservationFormDialog.vue`）へのインライン顧客登録
- 「新規ご来店」の申告値そのものの永続化（`reservations` へのカラム追加は行わない）
- 公開Web予約からの**既存顧客情報の更新**（空欄埋めを含め、一切行わない）
- 電話番号の所有確認（SMS 認証等）。[booking.md](../../requirements/booking.md) のなりすまし脅威に対する既存の緩和策を維持する
- メール通知（`customers.email` は連絡先情報としての保持のみ）

---

## 決定事項

| 論点 | 決定 | 理由 |
|---|---|---|
| 対象画面 | 公開Web予約ページのみ | サロン側フォームは別件として切り出す |
| チェックボックスの役割 | 入力項目の出し分けスイッチ | 新規判定はサーバ側の電話番号一致で行う既存ルールを維持し、UI は入力負担の調整に専念させる |
| 追加項目 | 生年月日・性別・メールアドレス（すべて任意） | 入力ハードルを上げて予約離脱を招かない。`customers` の該当カラムはすべて nullable |
| ご要望の保存先 | `reservations.note`・常時表示 | その回の施術希望なので予約単位が自然。サロン側予約カレンダーのメモ欄にそのまま表示される |
| 既存顧客一致時の追加項目 | 反映しない | 現行の「name / kana は上書きしない」ルールと整合し、他人の電話番号での予約によるカルテ汚染を防ぐ |
| 来店日の更新方式 | 都度再計算（visited 予約から MIN/MAX） | 誤操作の取り消し・予約削除・顧客付け替えのいずれでも自己修復する。バックフィルと同じロジックを使い回せる |

---

## 1. 画面設計 — `/booking/{booking_slug}` ステップ4

```
┌─────────────────────────────────────┐
│ お客様情報をご入力ください            │
│                                     │
│ お名前            [必須] ______      │
│ フリガナ          [必須] ______      │
│ 電話番号          [必須] ______      │
│                                     │
│ ┌─ ☐ 新規ご来店 ────────────────┐  │
│ │   当サロンのご利用が初めての方   │  │
│ │   ── チェック時のみ展開 ──      │  │
│ │   生年月日      [任意] ______   │  │
│ │   性別          [任意] ▼未設定  │  │
│ │   メールアドレス [任意] ______   │  │
│ └───────────────────────────────┘  │
│                                     │
│ ご要望・気になること [任意]          │
│ ┌─────────────────────────────┐    │
│ │                             │    │
│ └─────────────────────────────┘    │
└─────────────────────────────────────┘
```

- チェックボックスの初期状態は**オフ**
- オンにすると追加3項目が同一ステップ内に展開する。ステップ構成（メニュー / スタッフ / 日時 / お客様情報 / 確認）は変更しない
- 性別の選択肢は顧客登録フォームと同じ `未設定 / 男性 / 女性 / その他`（`0 / 1 / 2 / 9`）。初期選択は「未設定」で、この場合 API へは `null` を送る
- チェックをオフに戻したとき、追加3項目の入力値をクリアする（確認画面の表示と送信内容の不一致を防ぐ）
- ステップ5「確認」では、チェックオン時に限り追加3項目を表示する。値が入力された項目のみ行を出す
- 「ご要望・気になること」はチェックの状態にかかわらず常時表示する

### コンポーネント分割

`BookingPage.vue` は1,000行近くあり、ステップ4の追加でさらに肥大化する。
ステップ4のフォーム部分を `frontend/src/components/booking/BookingCustomerForm.vue` として切り出す。

- props: フォームの値（`v-model` による双方向バインド）、`fieldErrors`、`disabled`
- 責務: 入力欄の描画とチェックボックスによる出し分けのみ。API 呼び出し・ステップ遷移は `BookingPage.vue` に残す

バリデーション関数は `frontend/src/utils/publicBooking.ts` に置き、コンポーネントから呼ぶ。

---

## 2. API 設計

マイグレーションは**顧客・予約テーブルともに不要**。`customers` は `gender` / `birthday` / `email` / `first_visit_at` / `last_visit_at` を、
`reservations` は `note`（`text`）を既に持つ。

### `POST /api/public/v1/salons/{booking_slug}/reservations` リクエストの追加分

既存の `menu_id` / `user_id` / `start_at` / `name` / `kana` / `phone` に以下を追加する。

| Field | Rule | 保存先 |
|---|---|---|
| `is_first_visit` | `required`・`boolean` | 保存しない（出し分けの制御のみ） |
| `birthday` | `exclude_unless:is_first_visit,true`・`nullable`・`date_format:Y-m-d`・`before_or_equal:today` | `customers.birthday` |
| `gender` | `exclude_unless:is_first_visit,true`・`nullable`・`integer`・`in:0,1,2,9` | `customers.gender` |
| `email` | `exclude_unless:is_first_visit,true`・`nullable`・`email`・`max:255` | `customers.email` |
| `note` | `nullable`・`string`・`max:500` | `reservations.note` |

`exclude_unless` により、`is_first_visit` が false のまま `birthday` 等を直接 POST しても検証済みデータから除外される。
UI の出し分けを API 契約としても担保する。

なお `exclude_unless:is_first_visit,true` がブール値と正しく比較されるのは、
`is_first_visit` 自身に `boolean` ルールが付いている場合に限る（Laravel が依存ルールのパラメータを
`boolean` ルールの有無を見て `'true'` → `true` に変換するため）。`boolean` を外してはならない。

`note` の上限を管理側の `max:2000` ではなく `max:500` にするのは、未認証エンドポイントで受け取る入力量を抑えるため。
サロン側は登録後に管理画面から2000文字まで加筆できる。

レスポンス（`PublicReservationResource`）は変更しない。

### 422 のエラーキー割当

[booking.md](../../requirements/booking.md) の既存ルール（顧客情報は `name` / `kana` / `phone`、時間帯系は `start_at`）に以下を追加する。

- `birthday` / `gender` / `email` / `note` はそれぞれ自身のキーで返す
- UI はステップ4に留まり、該当フィールド下にサーバメッセージを表示する（既存の `name` / `kana` / `phone` と同じ扱い）
- `start_at` 系エラーで空き枠を再取得して日時選択ステップへ戻す既存挙動は変更しない

---

## 3. バックエンド処理設計

### 3-1. 顧客の自動生成

`PublicBookingService::resolveCustomer` を変更する。

```php
private function resolveCustomer(int $salonId, string $phone, array $data): Customer
{
    $customer = $this->customerRepository->findFirstByNormalizedPhone($salonId, $phone);

    // 既存顧客には入力値を反映しない（他人の電話番号での予約によるカルテ汚染を防ぐ）
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

チェックがオフなら `exclude_unless` で3項目が検証済みデータから落ちるため、`?? null` がそのまま効く。
「未設定」も `null` を送る方針にしているので、チェックのオン／オフで値の入り方が変わらない。

`note` は `PublicBookingService::createBooking` 内の `reservationRepository->create()` の属性に
`'note' => $data['note'] ?? null` を追加する。

### 3-2. 来店日の再計算

責務を層をまたがない形で2つに割る。

- `ReservationRepository::visitDateRange(int $salonId, int $customerId): array` —
  `status=visited` の未削除予約から `['first' => ?Carbon, 'last' => ?Carbon]` を返す。
  該当予約がなければ両方 null（＝顧客の来店日を null に戻す指示になる）
- `CustomerRepository::updateVisitDates(int $salonId, int $customerId, ?string $first, ?string $last): void` —
  `Y-m-d` 文字列（または null）を受け取り、`customers` の2カラムだけを更新する

`start_at` は `timestamptz` なので、SQL では素の `MIN` / `MAX` を取り、PHP 側で
`Carbon::parse($value)->setTimezone(config('app.salon_timezone'))->toDateString()` に変換する。
UTC の最小／最大は JST の最小／最大と一致する（タイムゾーン変換が単調）ため結果は等しく、
タイムゾーン変換の生 SQL を書かずに済む。

オーケストレーションは `ReservationService` が担い、呼び出し箇所は次の2つに限る。

| 契機 | 引き直す顧客 |
|---|---|
| `ReservationService::update()` | 変更前の `customer_id` と変更後の `customer_id`（同一なら1件） |
| `ReservationService::delete()` | その予約の `customer_id` |

`ReservationService::create()` は status が必ず `reserved` のため不要。
公開予約の作成・キャンセルも `visited` を経由しないため不要。

「status が visited に出入りしたときだけ」といった条件分岐は入れず、上記2契機で無条件に引き直す。
条件を増やすほど漏れが生まれ、都度再計算方式の利点である自己修復性が失われるため。

### 3-3. 既存データのバックフィル

artisan コマンドではなく**データマイグレーション**で行う。
本番は Render の free プランで Shell が使えず、デプロイ時に走る `migrate` が唯一の実行経路のため。

```sql
UPDATE customers c
   SET first_visit_at = v.first_visit_at,
       last_visit_at  = v.last_visit_at
  FROM (SELECT customer_id,
               MIN((start_at AT TIME ZONE ?)::date) AS first_visit_at,
               MAX((start_at AT TIME ZONE ?)::date) AS last_visit_at
          FROM reservations
         WHERE status = 'visited' AND deleted_at IS NULL
         GROUP BY customer_id) v
 WHERE c.id = v.customer_id
```

タイムゾーンは `config('app.salon_timezone')` をバインドパラメータとして渡す。
visited 予約を持つ顧客だけを更新するため、シード済みの既存値を null で潰すことはない。
`down()` はデータを破壊しない no-op とする。

---

## 4. テスト方針

### バックエンド

`tests/Feature/PublicReservationApiTest.php` に追加:

- チェックあり＋3項目入力 → 顧客が `gender` / `birthday` / `email` 付きで作成される
- チェックなしで3項目を直接 POST → `exclude_unless` により無視され、顧客の該当カラムは null のまま
- 電話番号が一致する既存顧客＋チェックあり＋3項目 → 既存顧客は一切更新されず、予約は成功する
- `note` が `reservations.note` に保存される
- `birthday` が未来日 / `gender` が範囲外 / `email` が不正 → それぞれのキーで 422

新規 `tests/Feature/CustomerVisitDateTest.php`:

- `reserved → visited` で `first_visit_at` / `last_visit_at` が入る
- `visited → cancelled` に戻すと null に戻る（都度再計算方式の核。前進のみ更新との差はここで出る）
- visited が複数あるとき MIN / MAX が入る
- 予約を論理削除すると引き直される
- `customer_id` を付け替えると旧顧客・新顧客の両方が引き直される
- UTC 15:00 の予約が JST の翌日日付として記録される（日付境界）

バックフィルは新規 `tests/Feature/BackfillCustomerVisitDatesTest.php` で検証する。
マイグレーション実行後の状態ではなく、マイグレーションクラスが持つ SQL を直接呼ぶ形とし、
既存 visited 予約から正しく埋まること・visited 予約を持たない顧客の既存値が保持されること・
JST の日付境界が正しいことを確認する。

### フロントエンド

追加項目のバリデーションは `utils/publicBooking.ts` の関数として実装し、
`utils/publicBooking.spec.ts` にケースを追加する（既存ファイルの構成に合わせ、コンポーネントを DOM 越しにテストしない）。

- 生年月日の形式・未来日の判定
- メールアドレスの形式判定
- ご要望の文字数上限（500）

---

## 5. 更新するドキュメント

Documentation Driven Development に従い、実装前に反映する。

| ファイル | 内容 |
|---|---|
| [docs/requirements/booking.md](../../requirements/booking.md) | バリデーション表への追加、Business Rules 5（顧客マッチング）への「既存顧客には反映しない」の明記、422 エラーキー割当の追加 |
| [docs/api/endpoints.md](../../api/endpoints.md) | 公開予約作成のリクエストボディ |
| [docs/api/openapi.yaml](../../api/openapi.yaml) | 同上のスキーマ |
| [docs/ui/public-booking.md](../../ui/public-booking.md) | ステップ4の画面仕様、コンポーネント分割 |

来店日の自動記録は予約コア（フェーズ1）の挙動変更にあたるため、
[docs/requirements/reservation.md](../../requirements/reservation.md) にも `first_visit_at` / `last_visit_at` の更新規則を追記する。

---

## References

- [docs/requirements/booking.md](../../requirements/booking.md)
- [docs/requirements/reservation.md](../../requirements/reservation.md)
- [docs/db/ERD.md](../../db/ERD.md)
- [docs/decisions/ADR-009-service-layer.md](../../decisions/ADR-009-service-layer.md)
- [docs/decisions/ADR-023-reservation-core.md](../../decisions/ADR-023-reservation-core.md)
- [docs/decisions/ADR-024-line-integration.md](../../decisions/ADR-024-line-integration.md)
