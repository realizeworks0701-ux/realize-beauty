# ダッシュボード刷新 + レスポンシブ化 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ダッシュボードを KPI 4枚(前月比)+売上推移グラフ+本日の来店予約+人気メニュー+顧客セグメント構成に刷新し、管理画面共通レイアウトをレスポンシブ化する。

**Architecture:** Backend は既存の Controller → Service → Repository 構成のまま `GET /api/v1/dashboard` のレスポンスを刷新。売上は `reservations.price`(予約時点のメニュー価格スナップショット)を新設して集計する。Frontend は chart.js(PrimeVue Chart)を導入し、`components/dashboard/` に新コンポーネントを追加、AppLayout に PrimeVue Drawer のモバイルメニューを追加する。

**Tech Stack:** Laravel 13 / PHP 8.3 / PostgreSQL / Vue 3 + TypeScript / PrimeVue 4 / chart.js / vitest / PHPUnit

**Spec:** [docs/superpowers/specs/2026-08-29-dashboard-redesign-design.md](../specs/2026-08-29-dashboard-redesign-design.md)

## Global Constraints

- AGENTS.md と CLAUDE.md のルールに従う。設計書(docs/)と実装が矛盾する場合は設計書優先
- Documentation Driven Development: Task 1 で設計書を先に更新してから実装する
- Backend: Controller にビジネスロジックを書かない。Repository が DB 操作、Service がビジネスロジック
- Carbon の期間比較は必ず `->copy()->utc()` で UTC に変換してからクエリに渡す(既知の再発バグ)
- 集計はすべて `config('app.salon_timezone')`(Asia/Tokyo)の日付境界で行う
- Frontend: Tailwind 等は使わない。`--rb-*` CSS トークン + scoped CSS。`any` 禁止。`noUncheckedIndexedAccess: true` のため配列アクセスに `?? fallback` が必要
- 型は `@/types` バレルから import。PrimeVue コンポーネントは個別 import
- コミットメッセージは日本語の `feat:`/`docs:`/`test:` プレフィックス + `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`
- 整形: backend は `./vendor/bin/pint`、frontend は `npm run format`(oxfmt)をコミット前に実行
- テスト実行の前提: Sail の pgsql が起動していること(`cd backend && ./vendor/bin/sail up -d pgsql`)。ホストPHPから DB_HOST=127.0.0.1 で接続

---

### Task 1: 設計書更新(ADR-026・UI・API・ERD・ロードマップ)

**Files:**
- Create: `docs/decisions/ADR-026-dashboard-analytics.md`
- Modify: `docs/ui/dashboard.md`(全面改訂)
- Modify: `docs/ui/README.md`(レスポンシブ方針)
- Modify: `docs/ui/wireframe.md`(Dashboard 節)
- Modify: `docs/api/components/schemas/dashboard.yaml`(全面改訂)
- Modify: `docs/api/endpoints.md`(Dashboard 節、117〜153行付近)
- Modify: `docs/db/ERD.md`(reservations テーブルに price 行追加、211行付近)
- Modify: `docs/requirements/MVP.md`(Dashboard 節を ADR-026 の構成に合わせる)
- Modify: `docs/roadmap/ROADMAP.md`(v0.2 Analytics の前倒し注記)

**Interfaces:**
- Produces: `GET /api/v1/dashboard` の新レスポンス形(後続タスクの正典)。`reservations.price` カラム定義

- [ ] **Step 1: ADR-026 を作成**

`docs/decisions/TEMPLATE.md` を読み、その見出し構成に合わせて以下の内容で `docs/decisions/ADR-026-dashboard-analytics.md` を作成する(Status: Accepted / 日付 2026-08-29):

決定事項:
1. ダッシュボードを「KPI 4枚(新規顧客数・予約数・売上・リピート率、当月値+前月比)+売上推移(直近6ヶ月)+本日の来店予約+人気メニュー(当月上位5)+顧客セグメント」構成に刷新する(v0.2 Analytics の売上グラフ・人気メニューを前倒し)。「今日の来店・総顧客数・今月のカルテ」KPI と「最近のカルテ・最近の顧客」一覧は廃止(各一覧ページで代替)
2. 売上は `reservations.price`(予約時点のメニュー税込価格スナップショット、integer nullable 円)で記録する。予約作成時(管理・公開Web予約とも)に記録、予約更新でメニューが変わったら再記録、既存予約は現在のメニュー価格でバックフィル。会計・決済テーブルは作らない(v0.2 で再検討)
3. ダッシュボード集計はサロンTZ(Asia/Tokyo)の日付境界に統一する(ADR-023 に記録した UTC/JST 混在の既知の不整合を解消)
4. リピート率 = 当月来店(visited)顧客のうち当月より前に初来店(first_visit_at < 当月初)していた顧客の割合。前月比はポイント差
5. 顧客タグ機能は作らず、来店履歴から自動分類する。判定順: 休眠(最終来店から90日超)→ 新規(初来店が当月)→ リピーター(来店2回以上)→ その他。対象は来店歴(first_visit_at)のある顧客のみ
6. 管理画面共通レイアウト(AppLayout)をレスポンシブ化する(<1024px でサイドバーを Drawer に切替)。UI 方針を「PC最適+モバイル対応(共通レイアウト)」へ改訂。各ページの個別最適化は別途
7. グラフ描画は chart.js(PrimeVue Chart 経由)を採用

Consequences に記載: MVP.md と ui/dashboard.md の売上矛盾は本 ADR で解消(売上KPIを正式要件化)/ バックフィルされた price は予約当時の価格とは限らない / 90日・上位5件・6ヶ月は実装上の定数

- [ ] **Step 2: docs/api/components/schemas/dashboard.yaml を全面置換**

まず `docs/api/components/schemas/reservation.yaml` を開き、予約レスポンスのトップレベルキー名(例: `Reservation`)を確認する。次に dashboard.yaml を以下で置換(`./reservation.yaml#/Reservation` は確認した実キー名に合わせる):

```yaml
DashboardResponse:
  type: object
  description: ダッシュボードレスポンス

  required:
    - data

  properties:

    data:
      type: object

      required:
        - kpis
        - sales_trend
        - today_reservations
        - popular_menus
        - customer_segments

      properties:

        kpis:
          type: object
          description: 当月(current)と前月(previous)の比較KPI。集計はサロンTZ（Asia/Tokyo）の日付境界
          required:
            - new_customers
            - reservations
            - sales
            - repeat_rate
          properties:
            new_customers:
              $ref: "#/KpiComparison"
            reservations:
              $ref: "#/KpiComparison"
            sales:
              $ref: "#/KpiComparison"
            repeat_rate:
              $ref: "#/KpiComparison"

        sales_trend:
          type: array
          description: 直近6ヶ月の月次売上（古い順。データのない月は 0）
          items:
            type: object
            required:
              - month
              - sales
            properties:
              month:
                type: string
                description: 対象月（YYYY-MM）
                example: "2026-08"
              sales:
                type: integer
                description: 月間売上（status=visited の予約の price 合計、円）
                example: 324000

        today_reservations:
          type: array
          description: 本日（サロンTZ）の予約。status が reserved / visited のみ、start_at 昇順
          items:
            $ref: "./reservation.yaml#/Reservation"

        popular_menus:
          type: array
          description: 当月の visited 予約件数上位5メニュー
          items:
            type: object
            required:
              - menu_id
              - name
              - price
              - count
            properties:
              menu_id:
                type: integer
                example: 1
              name:
                type: string
                example: プレミアムフェイシャル
              price:
                type: integer
                nullable: true
                description: 現在のメニュー税込価格（円）
                example: 12000
              count:
                type: integer
                description: 当月の visited 予約件数
                example: 14

        customer_segments:
          type: object
          description: 来店歴のある顧客の分類。判定順は dormant → new → repeat → other
          required:
            - new
            - repeat
            - dormant
            - other
          properties:
            new:
              type: integer
              description: 初来店が当月
              example: 28
            repeat:
              type: integer
              description: 来店2回以上（休眠を除く）
              example: 42
            dormant:
              type: integer
              description: 最終来店から90日超
              example: 6
            other:
              type: integer
              description: 上記いずれにも該当しない来店歴あり顧客
              example: 4

KpiComparison:
  type: object
  description: 当月値と前月値（増減率の計算はフロントエンド）
  required:
    - current
    - previous
  properties:
    current:
      type: number
      example: 28
    previous:
      type: number
      example: 25
```

`docs/api/openapi.yaml` から dashboard.yaml がどう参照されているか確認し、`#/KpiComparison` の同一ファイル内参照がバンドル構成上問題ないことを確認する(問題があれば KpiComparison を4箇所インラインに展開する)。

- [ ] **Step 3: docs/api/endpoints.md の Dashboard 節を置換**

`# Dashboard` 節(117行付近〜次の `# Customers` の前まで)の Response と Notes を置換:

````markdown
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
````

- [ ] **Step 4: docs/db/ERD.md の reservations テーブルに price 行を追加**

211行付近の `# reservations` カラム表の `status` 行の直後に追加:

```markdown
| price | integer | nullable。予約時点のメニュー税込価格スナップショット（円）。売上集計に使用（ADR-026）。導入時の既存行は現在のメニュー価格で埋め戻し |
```

- [ ] **Step 5: docs/ui/dashboard.md を全面改訂**

以下の内容で置換:

````markdown
# Dashboard

## 概要

サロン全体の状況を確認するトップ画面。KPI・売上推移・本日の予約・人気メニュー・顧客セグメントを表示する（ADR-026）。

---

## Route

`/dashboard`

---

## Components

### KPI（4枚、当月値+前月比）

GET /api/v1/dashboard の `kpis` に合わせる。

* 新規顧客数（前月比%）
* 予約数（前月比%）
* 売上（前月比%。¥表示）
* リピート率（前月比はポイント差）

KPIカードはグラデーション背景（design-system.md の Gradient 参照）+ 増減ピル（増=上矢印/減=下矢印）。

### 売上推移

直近6ヶ月の月次売上の面グラフ（chart.js / PrimeVue Chart）。

### 本日の来店予約

当日（JST）の予約リスト（時刻・顧客名・メニュー・ステータス）。行クリックで /reservations へ。0件時は EmptyState。

### 人気メニュー

当月の visited 予約件数上位5件。グラデーションのアイコンタイル + 相対バー + 現在価格。

### 顧客セグメント

新規 / リピーター / 休眠 / その他 の件数表示（定義は ADR-026）。

---

## レスポンシブ

| 幅 | KPI | 下段 |
|---|---|---|
| ≥1024px | 4カラム | 2カラム |
| 600–1023px | 2×2 | 1カラム |
| <600px | 1カラム | 1カラム |

---

## API

GET /api/v1/dashboard

---

## UIイメージ

```text
+------------------------------------------------------+
| 新規顧客数    予約数      売上        リピート率        |
| 12名 +20%   28件 +12%  ¥324,000 +8%  78% +5pt        |
+---------------------------+--------------------------+
| 売上推移（6ヶ月・面グラフ）  | 本日の来店予約             |
|                           | 10:00 山田様 フェイシャル   |
+---------------------------+--------------------------+
| 人気メニュー（上位5・バー）   | 顧客セグメント             |
|                           | 新規28 リピーター42 休眠6   |
+---------------------------+--------------------------+
```
````

- [ ] **Step 6: docs/ui/README.md・wireframe.md・MVP.md・ROADMAP.md を改訂**

- `docs/ui/README.md`: 「モバイルよりPC利用を優先」の記述を「PC利用を主とし、共通レイアウトはモバイルにも対応する（ADR-026。各画面の個別最適化は順次）」に置換
- `docs/ui/wireframe.md`: Dashboard 節(58行付近)のカード列挙を新構成(KPI4枚・売上推移・本日の来店予約・人気メニュー・顧客セグメント)に置換。dashboard.md の UIイメージと同じ ASCII 図を使う
- `docs/requirements/MVP.md`: Dashboard 節(46行付近)の項目を「当月KPI(新規顧客数・予約数・売上・リピート率)、売上推移、本日の来店予約、人気メニュー、顧客セグメント」に更新し「(ADR-026)」を付記。「AIからのお知らせ(将来)」はそのまま残す
- `docs/roadmap/ROADMAP.md`: v0.2 Analytics の「売上グラフ」「人気メニュー」をチェック済みにし「(v0.1系 ダッシュボード刷新で前倒し。ADR-026)」を付記。v0.1 の「今日の売上」項目には「(ADR-026 で当月売上KPIに置換)」を付記してチェック

- [ ] **Step 7: コミット**

```bash
git add docs/
git commit -m "docs: ダッシュボード刷新のADR-026と設計書更新（Analytics前倒し・売上スナップショット・レスポンシブ方針）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: reservations.price スナップショット + バックフィル(Backend)

**Files:**
- Create: `backend/database/migrations/2026_08_29_000001_add_price_to_reservations_table.php`
- Create: `backend/database/migrations/2026_08_29_000002_backfill_reservation_prices.php`
- Modify: `backend/app/Models/Reservation.php`(fillable / casts)
- Modify: `backend/app/Services/ReservationService.php`(create / update)
- Modify: `backend/app/Services/PublicBookingService.php`(create 内の reservationRepository->create)
- Test: `backend/tests/Feature/ReservationPriceSnapshotTest.php`(新規)
- Test: `backend/tests/Feature/PublicReservationApiTest.php`(既存成功テストに price 検証を追加)

**Interfaces:**
- Consumes: Task 1 の ERD 定義
- Produces: `reservations.price`(int|null)。Task 3 の売上集計が `sum('price')` で使用

- [ ] **Step 1: 失敗するテストを書く**

`backend/tests/Feature/ReservationPriceSnapshotTest.php` を作成:

```php
<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class ReservationPriceSnapshotTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_create_snapshots_current_menu_price(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $menu = Menu::factory()->for($user->salon)->create(['price' => 12000, 'duration_minutes' => 60]);

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-09-01T10:00:00+09:00',
        ])->assertCreated();

        $this->assertSame(12000, Reservation::sole()->price);
    }

    public function test_price_is_kept_when_menu_price_changes_later(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $menu = Menu::factory()->for($user->salon)->create(['price' => 12000, 'duration_minutes' => 60]);

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-09-01T10:00:00+09:00',
        ])->assertCreated();

        $menu->update(['price' => 99999]);

        $this->assertSame(12000, Reservation::sole()->price);
    }

    public function test_update_resnapshots_price_only_when_menu_changes(): void
    {
        $user = $this->actingAsSalonUser();
        $customer = Customer::factory()->for($user->salon)->create();
        $menuA = Menu::factory()->for($user->salon)->create(['price' => 12000, 'duration_minutes' => 60]);
        $menuB = Menu::factory()->for($user->salon)->create(['price' => 8000, 'duration_minutes' => 60]);

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menuA->id,
            'user_id' => $user->id,
            'start_at' => '2026-09-01T10:00:00+09:00',
        ])->assertCreated();
        $reservation = Reservation::sole();

        // ステータスのみ更新 → price は変わらない（メニュー価格が変わっていても）
        $menuA->update(['price' => 50000]);
        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'status' => ReservationStatus::Visited->value,
        ])->assertOk();
        $this->assertSame(12000, $reservation->refresh()->price);

        // メニュー変更 → 新メニューの価格で再スナップショット
        $this->patchJson("/api/v1/reservations/{$reservation->id}", [
            'menu_id' => $menuB->id,
        ])->assertOk();
        $this->assertSame(8000, $reservation->refresh()->price);
    }

    public function test_backfill_fills_missing_prices_from_menus(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = Menu::factory()->for($user->salon)->create(['price' => 8000]);
        $filled = Reservation::factory()->for($user->salon)->create([
            'customer_id' => Customer::factory()->for($user->salon),
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'price' => null,
        ]);
        $kept = Reservation::factory()->for($user->salon)->create([
            'customer_id' => Customer::factory()->for($user->salon),
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'price' => 5000,
        ]);

        (include database_path('migrations/2026_08_29_000002_backfill_reservation_prices.php'))->up();

        $this->assertSame(8000, $filled->refresh()->price);
        $this->assertSame(5000, $kept->refresh()->price);
    }
}
```

注意: `PATCH /api/v1/reservations/{id}` の FormRequest が `status` / `menu_id` 単体更新を許すことを `backend/app/Http/Requests/` の該当 Request(UpdateReservationRequest 等)で確認する。`sometimes` でない必須項目があればテスト側で併せて送る。

- [ ] **Step 2: テストが失敗することを確認**

```bash
cd backend && php artisan test --filter=ReservationPriceSnapshotTest
```

Expected: FAIL(price カラムが存在しない / null のまま)

- [ ] **Step 3: マイグレーション2本を作成**

`backend/database/migrations/2026_08_29_000001_add_price_to_reservations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->integer('price')->nullable()->after('status')
                ->comment('予約時点のメニュー税込価格スナップショット（円）。売上集計に使用');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
```

`backend/database/migrations/2026_08_29_000002_backfill_reservation_prices.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * price 導入前の既存予約に現在のメニュー価格を埋め戻す。
     * 予約時点の価格は復元できないため現在価格を近似値として使う（ADR-026）。
     * メニューは論理削除でも menus 行が残るため JOIN できる。
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE reservations r
               SET price = m.price
              FROM menus m
             WHERE r.menu_id = m.id
               AND r.price IS NULL
        SQL);
    }

    /**
     * 埋め戻した値と作成時スナップショットは区別できないため巻き戻しでは何もしない。
     */
    public function down(): void {}
};
```

- [ ] **Step 4: モデルとサービスを修正**

`backend/app/Models/Reservation.php`: `$fillable` の `'status',` の直後に `'price',` を追加。`$casts` に `'price' => 'integer',` を追加。

`backend/app/Services/ReservationService.php` の `create()`: `reservationRepository->create` に渡す配列の `'status' => ReservationStatus::Reserved,` の直後に追加:

```php
'price' => $menu->price,
```

同 `update()`: `$attributes = array_merge(...)` を以下に変更(メニュー変更時のみ再スナップショット):

```php
$attributes = array_merge(
    Arr::only($data, ['customer_id', 'menu_id', 'user_id', 'status', 'note']),
    ['start_at' => $startAt, 'end_at' => $endAt],
    $menuChanged ? ['price' => $menu->price] : [],
);
```

`backend/app/Services/PublicBookingService.php` の `reservationRepository->create` に渡す配列(117行付近)の `'status' => ReservationStatus::Reserved,` の直後に追加:

```php
'price' => $menu->price,
```

- [ ] **Step 5: 公開Web予約のテストに price 検証を追加**

`backend/tests/Feature/PublicReservationApiTest.php` の予約作成成功テスト(201 を assert しているメソッド)の末尾に、作成された予約の price がメニュー価格と一致する検証を追加する。例:

```php
$this->assertSame($menu->price, Reservation::latest('id')->first()->price);
```

(変数名はそのテスト内の実際のメニュー変数に合わせる)

- [ ] **Step 6: マイグレーションとテストを実行して合格を確認**

```bash
cd backend && php artisan migrate
php artisan test --filter='ReservationPriceSnapshotTest|PublicReservationApiTest|ReservationApiTest'
```

Expected: PASS(全件)

- [ ] **Step 7: 整形してコミット**

```bash
cd backend && ./vendor/bin/pint --dirty
git add backend/
git commit -m "feat: 予約に価格スナップショットを追加し既存予約を埋め戻す

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: ダッシュボード集計APIの刷新(Backend)

**Files:**
- Modify: `backend/app/Repositories/DashboardRepository.php`(全面書き換え)
- Modify: `backend/app/Http/Controllers/Api/V1/DashboardController.php`
- Test: `backend/tests/Feature/DashboardApiTest.php`(全面書き換え)

**Interfaces:**
- Consumes: `reservations.price`(Task 2)
- Produces: `GET /api/v1/dashboard` 新レスポンス(Task 1 の OpenAPI どおり)。`DashboardRepository::getSummary(int $salonId): array` のキー: `kpis` / `sales_trend` / `today_reservations`(Eloquent Collection) / `popular_menus` / `customer_segments`

- [ ] **Step 1: DashboardApiTest を新レスポンス仕様で全面書き換え(失敗するテスト)**

`backend/tests/Feature/DashboardApiTest.php` を以下で置換:

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

class DashboardApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 月中に固定して月境界・休眠90日境界のフレークを防ぐ（JST 2026-08-20）
        Carbon::setTestNow(Carbon::parse('2026-08-20T12:00:00+09:00'));
    }

    public function test_index_returns_summary_structure(): void
    {
        $this->actingAsSalonUser();

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'kpis' => [
                    'new_customers' => ['current', 'previous'],
                    'reservations' => ['current', 'previous'],
                    'sales' => ['current', 'previous'],
                    'repeat_rate' => ['current', 'previous'],
                ],
                'sales_trend',
                'today_reservations',
                'popular_menus',
                'customer_segments' => ['new', 'repeat', 'dormant', 'other'],
            ],
        ]);
        $response->assertJsonCount(6, 'data.sales_trend');
    }

    public function test_sales_kpi_sums_visited_reservation_prices_by_month(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        // 当月: visited 12000 + 8000。reserved / cancelled は含めない
        $this->reservationAt($user, $menu, '2026-08-05T10:00:00+09:00', ReservationStatus::Visited, 12000);
        $this->reservationAt($user, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Visited, 8000);
        $this->reservationAt($user, $menu, '2026-08-12T10:00:00+09:00', ReservationStatus::Reserved, 9000);
        $this->reservationAt($user, $menu, '2026-08-15T10:00:00+09:00', ReservationStatus::Cancelled, 7000);

        // 前月: visited 30000
        $this->reservationAt($user, $menu, '2026-07-10T10:00:00+09:00', ReservationStatus::Visited, 30000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.kpis.sales.current', 20000);
        $response->assertJsonPath('data.kpis.sales.previous', 30000);
    }

    public function test_monthly_aggregates_use_jst_boundary(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        // JST 8/1 00:30 = UTC 7/31 15:30 → 当月に含める
        $this->reservationAt($user, $menu, '2026-08-01T00:30:00+09:00', ReservationStatus::Visited, 5000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.kpis.sales.current', 5000);
        $response->assertJsonPath('data.kpis.sales.previous', 0);
    }

    public function test_new_customers_and_reservations_kpis_compare_with_previous_month(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-08-03']);
        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-08-18']);
        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-07-20']);
        Customer::factory()->for($user->salon)->create(['first_visit_at' => null]);

        // 当月の予約: reserved + visited = 2（cancelled は除外）
        $this->reservationAt($user, $menu, '2026-08-21T10:00:00+09:00', ReservationStatus::Reserved, 1000);
        $this->reservationAt($user, $menu, '2026-08-05T10:00:00+09:00', ReservationStatus::Visited, 1000);
        $this->reservationAt($user, $menu, '2026-08-06T10:00:00+09:00', ReservationStatus::Cancelled, 1000);
        // 前月の予約: 1
        $this->reservationAt($user, $menu, '2026-07-06T10:00:00+09:00', ReservationStatus::Visited, 1000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.kpis.new_customers.current', 2);
        $response->assertJsonPath('data.kpis.new_customers.previous', 1);
        $response->assertJsonPath('data.kpis.reservations.current', 2);
        $response->assertJsonPath('data.kpis.reservations.previous', 1);
    }

    public function test_repeat_rate_is_share_of_returning_visitors(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        $repeater = Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-07-10']);
        $newcomer = Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-08-05']);
        $this->reservationAt($user, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Visited, 1000, $repeater);
        $this->reservationAt($user, $menu, '2026-08-05T10:00:00+09:00', ReservationStatus::Visited, 1000, $newcomer);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.kpis.repeat_rate.current', 50.0);
    }

    public function test_sales_trend_returns_six_months_with_zero_fill(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        $this->reservationAt($user, $menu, '2026-05-10T10:00:00+09:00', ReservationStatus::Visited, 40000);
        $this->reservationAt($user, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Visited, 20000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonCount(6, 'data.sales_trend');
        $response->assertJsonPath('data.sales_trend.0.month', '2026-03');
        $response->assertJsonPath('data.sales_trend.0.sales', 0);
        $response->assertJsonPath('data.sales_trend.2.month', '2026-05');
        $response->assertJsonPath('data.sales_trend.2.sales', 40000);
        $response->assertJsonPath('data.sales_trend.5.month', '2026-08');
        $response->assertJsonPath('data.sales_trend.5.sales', 20000);
    }

    public function test_today_reservations_lists_jst_today_in_order(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);
        $todayJst = Carbon::today(config('app.salon_timezone'));

        $late = $this->reservationAt($user, $menu, $todayJst->copy()->setTime(23, 30)->toIso8601String(), ReservationStatus::Visited, 1000);
        $early = $this->reservationAt($user, $menu, $todayJst->copy()->setTime(0, 30)->toIso8601String(), ReservationStatus::Reserved, 1000);
        // 前日・翌日・cancelled は含めない
        $this->reservationAt($user, $menu, $todayJst->copy()->subMinutes(30)->toIso8601String(), ReservationStatus::Reserved, 1000);
        $this->reservationAt($user, $menu, $todayJst->copy()->addDay()->setTime(0, 30)->toIso8601String(), ReservationStatus::Reserved, 1000);
        $this->reservationAt($user, $menu, $todayJst->copy()->setTime(10, 0)->toIso8601String(), ReservationStatus::Cancelled, 1000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonCount(2, 'data.today_reservations');
        $response->assertJsonPath('data.today_reservations.0.id', $early->id);
        $response->assertJsonPath('data.today_reservations.1.id', $late->id);
        $response->assertJsonStructure([
            'data' => ['today_reservations' => [['id', 'start_at', 'status', 'customer' => ['id', 'name'], 'menu' => ['id', 'name']]]],
        ]);
    }

    public function test_popular_menus_ranks_visited_menus_of_current_month(): void
    {
        $user = $this->actingAsSalonUser();
        $menuA = Menu::factory()->for($user->salon)->create(['name' => 'フェイシャルA', 'price' => 12000, 'duration_minutes' => 60]);
        $menuB = Menu::factory()->for($user->salon)->create(['name' => 'ヘッドスパB', 'price' => 8000, 'duration_minutes' => 60]);

        $this->reservationAt($user, $menuA, '2026-08-03T10:00:00+09:00', ReservationStatus::Visited, 12000);
        $this->reservationAt($user, $menuA, '2026-08-04T10:00:00+09:00', ReservationStatus::Visited, 12000);
        $this->reservationAt($user, $menuB, '2026-08-05T10:00:00+09:00', ReservationStatus::Visited, 8000);
        // 前月分・cancelled は数えない
        $this->reservationAt($user, $menuB, '2026-07-05T10:00:00+09:00', ReservationStatus::Visited, 8000);
        $this->reservationAt($user, $menuB, '2026-08-06T10:00:00+09:00', ReservationStatus::Cancelled, 8000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonCount(2, 'data.popular_menus');
        $response->assertJsonPath('data.popular_menus.0.menu_id', $menuA->id);
        $response->assertJsonPath('data.popular_menus.0.name', 'フェイシャルA');
        $response->assertJsonPath('data.popular_menus.0.count', 2);
        $response->assertJsonPath('data.popular_menus.1.menu_id', $menuB->id);
        $response->assertJsonPath('data.popular_menus.1.count', 1);
    }

    public function test_customer_segments_classify_by_visit_history(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        // 休眠: 最終来店が90日超前（基準日 2026-08-20 の90日前 = 2026-05-22）
        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-01-10', 'last_visit_at' => '2026-02-01']);
        // 新規: 初来店が当月
        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-08-05', 'last_visit_at' => '2026-08-05']);
        // リピーター: visited 予約2件・最終来店90日以内
        $repeat = Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-06-01', 'last_visit_at' => '2026-08-01']);
        $this->reservationAt($user, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited, 1000, $repeat);
        $this->reservationAt($user, $menu, '2026-08-01T10:00:00+09:00', ReservationStatus::Visited, 1000, $repeat);
        // その他: 来店1回・90日以内・当月より前
        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-07-15', 'last_visit_at' => '2026-07-15']);
        // 来店歴なしは対象外
        Customer::factory()->for($user->salon)->create(['first_visit_at' => null, 'last_visit_at' => null]);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.customer_segments.dormant', 1);
        $response->assertJsonPath('data.customer_segments.new', 1);
        $response->assertJsonPath('data.customer_segments.repeat', 1);
        $response->assertJsonPath('data.customer_segments.other', 1);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    private function menuFor(User $user): Menu
    {
        return Menu::factory()->for($user->salon)->create(['duration_minutes' => 60]);
    }

    private function reservationAt(
        User $user,
        Menu $menu,
        string $startAt,
        ReservationStatus $status,
        int $price,
        ?Customer $customer = null,
    ): Reservation {
        $start = Carbon::parse($startAt)->utc();

        return Reservation::factory()->for($user->salon)->create([
            'customer_id' => ($customer ?? Customer::factory()->for($user->salon)->create())->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(60),
            'status' => $status,
            'price' => $price,
        ]);
    }
}
```

補足: CustomerFactory が `first_visit_at` / `last_visit_at` をランダム生成している場合はテストの明示指定が勝つので問題ないが、`null` 指定が factory の afterCreate 等で上書きされないか CustomerFactory を確認する。

- [ ] **Step 2: テストが失敗することを確認**

```bash
cd backend && php artisan test --filter=DashboardApiTest
```

Expected: FAIL(旧レスポンス形のため kpis キーが無い)

- [ ] **Step 3: DashboardRepository を全面書き換え**

`backend/app/Repositories/DashboardRepository.php` を以下で置換:

```php
<?php

namespace App\Repositories;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class DashboardRepository
{
    /** 休眠とみなす最終来店からの経過日数 */
    private const DORMANT_DAYS = 90;

    /** 人気メニューの表示件数 */
    private const POPULAR_MENU_LIMIT = 5;

    /** 売上推移の月数（当月含む） */
    private const TREND_MONTHS = 6;

    public function getSummary(int $salonId): array
    {
        $timezone = config('app.salon_timezone');
        $monthStart = Carbon::now($timezone)->startOfMonth();
        $prevMonthStart = $monthStart->copy()->subMonth();
        $nextMonthStart = $monthStart->copy()->addMonth();

        return [
            'kpis' => [
                'new_customers' => [
                    'current' => $this->countNewCustomers($salonId, $monthStart, $nextMonthStart),
                    'previous' => $this->countNewCustomers($salonId, $prevMonthStart, $monthStart),
                ],
                'reservations' => [
                    'current' => $this->countReservations($salonId, $monthStart, $nextMonthStart),
                    'previous' => $this->countReservations($salonId, $prevMonthStart, $monthStart),
                ],
                'sales' => [
                    'current' => $this->sumSales($salonId, $monthStart, $nextMonthStart),
                    'previous' => $this->sumSales($salonId, $prevMonthStart, $monthStart),
                ],
                'repeat_rate' => [
                    'current' => $this->repeatRate($salonId, $monthStart, $nextMonthStart),
                    'previous' => $this->repeatRate($salonId, $prevMonthStart, $monthStart),
                ],
            ],
            'sales_trend' => $this->salesTrend($salonId, $monthStart),
            'today_reservations' => $this->todayReservations($salonId),
            'popular_menus' => $this->popularMenus($salonId, $monthStart, $nextMonthStart),
            'customer_segments' => $this->customerSegments($salonId, $monthStart),
        ];
    }

    private function countNewCustomers(int $salonId, Carbon $from, Carbon $toExclusive): int
    {
        return Customer::where('salon_id', $salonId)
            ->where('first_visit_at', '>=', $from->toDateString())
            ->where('first_visit_at', '<', $toExclusive->toDateString())
            ->count();
    }

    private function countReservations(int $salonId, Carbon $from, Carbon $toExclusive): int
    {
        return Reservation::where('salon_id', $salonId)
            ->whereIn('status', [ReservationStatus::Reserved->value, ReservationStatus::Visited->value])
            ->where('start_at', '>=', $from->copy()->utc())
            ->where('start_at', '<', $toExclusive->copy()->utc())
            ->count();
    }

    private function sumSales(int $salonId, Carbon $from, Carbon $toExclusive): int
    {
        return (int) Reservation::where('salon_id', $salonId)
            ->where('status', ReservationStatus::Visited->value)
            ->where('start_at', '>=', $from->copy()->utc())
            ->where('start_at', '<', $toExclusive->copy()->utc())
            ->sum('price');
    }

    /**
     * 期間内に来店した顧客のうち、期間開始より前に初来店していた顧客の割合（%・小数1桁）。
     * 来店者がいない期間は 0 を返す。
     */
    private function repeatRate(int $salonId, Carbon $from, Carbon $toExclusive): float
    {
        $visitorIds = Reservation::where('salon_id', $salonId)
            ->where('status', ReservationStatus::Visited->value)
            ->where('start_at', '>=', $from->copy()->utc())
            ->where('start_at', '<', $toExclusive->copy()->utc())
            ->distinct()
            ->pluck('customer_id');

        if ($visitorIds->isEmpty()) {
            return 0.0;
        }

        $repeaters = Customer::where('salon_id', $salonId)
            ->whereIn('id', $visitorIds)
            ->where('first_visit_at', '<', $from->toDateString())
            ->count();

        return round($repeaters / $visitorIds->count() * 100, 1);
    }

    private function salesTrend(int $salonId, Carbon $monthStart): array
    {
        $trendStart = $monthStart->copy()->subMonths(self::TREND_MONTHS - 1);
        $nextMonthStart = $monthStart->copy()->addMonth();

        $sales = Reservation::where('salon_id', $salonId)
            ->where('status', ReservationStatus::Visited->value)
            ->where('start_at', '>=', $trendStart->copy()->utc())
            ->where('start_at', '<', $nextMonthStart->copy()->utc())
            ->selectRaw(
                "to_char(start_at at time zone ?, 'YYYY-MM') as month, coalesce(sum(price), 0) as sales",
                [config('app.salon_timezone')],
            )
            ->groupBy('month')
            ->pluck('sales', 'month');

        return collect(range(0, self::TREND_MONTHS - 1))
            ->map(function (int $offset) use ($trendStart, $sales) {
                $month = $trendStart->copy()->addMonths($offset)->format('Y-m');

                return ['month' => $month, 'sales' => (int) ($sales[$month] ?? 0)];
            })
            ->all();
    }

    private function todayReservations(int $salonId): Collection
    {
        $salonToday = Carbon::today(config('app.salon_timezone'));

        return Reservation::where('salon_id', $salonId)
            ->whereIn('status', [ReservationStatus::Reserved->value, ReservationStatus::Visited->value])
            ->where('start_at', '>=', $salonToday->copy()->utc())
            ->where('start_at', '<', $salonToday->copy()->addDay()->utc())
            ->with(['customer', 'menu', 'user'])
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();
    }

    private function popularMenus(int $salonId, Carbon $from, Carbon $toExclusive): array
    {
        $counts = Reservation::where('salon_id', $salonId)
            ->where('status', ReservationStatus::Visited->value)
            ->where('start_at', '>=', $from->copy()->utc())
            ->where('start_at', '<', $toExclusive->copy()->utc())
            ->selectRaw('menu_id, count(*) as reservation_count')
            ->groupBy('menu_id')
            ->orderByDesc('reservation_count')
            ->orderBy('menu_id')
            ->limit(self::POPULAR_MENU_LIMIT)
            ->get();

        $menus = Menu::withTrashed()
            ->whereIn('id', $counts->pluck('menu_id'))
            ->get()
            ->keyBy('id');

        return $counts
            ->map(function ($row) use ($menus) {
                $menu = $menus->get($row->menu_id);

                return [
                    'menu_id' => $row->menu_id,
                    'name' => $menu?->name ?? '不明なメニュー',
                    'price' => $menu?->price,
                    'count' => (int) $row->reservation_count,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 来店歴のある顧客を 休眠 → 新規 → リピーター → その他 の優先順で分類する（ADR-026）。
     */
    private function customerSegments(int $salonId, Carbon $monthStart): array
    {
        $dormantBefore = Carbon::today(config('app.salon_timezone'))
            ->subDays(self::DORMANT_DAYS)
            ->toDateString();
        $monthStartDate = $monthStart->toDateString();

        $base = fn () => Customer::where('salon_id', $salonId)->whereNotNull('first_visit_at');

        $total = $base()->count();
        $dormant = $base()->where('last_visit_at', '<', $dormantBefore)->count();
        $new = $base()
            ->where('last_visit_at', '>=', $dormantBefore)
            ->where('first_visit_at', '>=', $monthStartDate)
            ->count();
        $repeat = $base()
            ->where('last_visit_at', '>=', $dormantBefore)
            ->where('first_visit_at', '<', $monthStartDate)
            ->whereHas(
                'reservations',
                fn ($query) => $query->where('status', ReservationStatus::Visited->value),
                '>=',
                2,
            )
            ->count();

        return [
            'new' => $new,
            'repeat' => $repeat,
            'dormant' => $dormant,
            'other' => $total - $dormant - $new - $repeat,
        ];
    }
}
```

- [ ] **Step 4: DashboardController を新レスポンス形に変更**

`backend/app/Http/Controllers/Api/V1/DashboardController.php` を以下で置換:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $summary = $this->dashboardService->getSummary($request->user()->salon_id);

        return response()->json([
            'data' => [
                'kpis' => $summary['kpis'],
                'sales_trend' => $summary['sales_trend'],
                'today_reservations' => ReservationResource::collection($summary['today_reservations']),
                'popular_menus' => $summary['popular_menus'],
                'customer_segments' => $summary['customer_segments'],
            ],
        ]);
    }
}
```

- [ ] **Step 5: テストが合格することを確認**

```bash
cd backend && php artisan test --filter=DashboardApiTest
```

Expected: PASS(全10テスト)。失敗したら実装を直す(テストの期待値は仕様なので変えない)。

- [ ] **Step 6: バックエンド全テスト + 整形 + コミット**

```bash
cd backend && composer test
./vendor/bin/pint --dirty
git add backend/
git commit -m "feat: ダッシュボードAPIをKPI比較・売上推移・人気メニュー・顧客セグメント構成に刷新

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

Expected: composer test 全件 PASS

---

### Task 4: フロントの型・サービス・モック刷新 + chart.js 導入

**Files:**
- Modify: `frontend/src/types/dashboard.ts`(全面書き換え)
- Modify: `frontend/src/services/mock/mockAdapter.ts`(/dashboard ハンドラ、455行付近)
- Modify: `frontend/src/utils/format.ts`(calcDeltaPercent 追加)
- Modify: `frontend/package.json`(chart.js 追加)
- Test: `frontend/src/utils/format.spec.ts`(無ければ新規作成、あれば追記)

**Interfaces:**
- Consumes: Task 1 の OpenAPI 定義、既存 `Reservation` 型(`@/types`)
- Produces: 型 `DashboardSummary` / `DashboardKpis` / `KpiComparison` / `SalesTrendPoint` / `PopularMenu` / `CustomerSegments`(いずれも `@/types` から export)。`calcDeltaPercent(current: number, previous: number): number | null`

- [ ] **Step 1: chart.js をインストール**

```bash
cd frontend && npm install chart.js
```

- [ ] **Step 2: calcDeltaPercent の失敗するテストを書く**

`frontend/src/utils/format.spec.ts` が無ければ新規作成(あれば describe を追記):

```ts
import { describe, expect, it } from 'vitest'
import { calcDeltaPercent } from './format'

describe('calcDeltaPercent', () => {
  it('前月比の増減率を小数1桁で返す', () => {
    expect(calcDeltaPercent(12, 10)).toBe(20)
    expect(calcDeltaPercent(11, 12)).toBe(-8.3)
  })

  it('前月が0のときはnull（表示しない）', () => {
    expect(calcDeltaPercent(5, 0)).toBeNull()
  })
})
```

実行: `cd frontend && npm run test:unit -- format.spec` → Expected: FAIL(calcDeltaPercent が未定義)

- [ ] **Step 3: format.ts に calcDeltaPercent を追加**

`frontend/src/utils/format.ts` の `formatNumber` の直後に追加:

```ts
/** 前期比の増減率（%・小数1桁）。previous が 0 のときは null を返し表示しない */
export function calcDeltaPercent(current: number, previous: number): number | null {
  if (previous === 0) return null
  return Math.round(((current - previous) / previous) * 1000) / 10
}
```

実行: `npm run test:unit -- format.spec` → Expected: PASS

- [ ] **Step 4: types/dashboard.ts を全面書き換え**

```ts
import type { Reservation } from './reservation'

export interface KpiComparison {
  current: number
  previous: number
}

export interface DashboardKpis {
  new_customers: KpiComparison
  reservations: KpiComparison
  sales: KpiComparison
  repeat_rate: KpiComparison
}

export interface SalesTrendPoint {
  month: string
  sales: number
}

export interface PopularMenu {
  menu_id: number
  name: string
  price: number | null
  count: number
}

export interface CustomerSegments {
  new: number
  repeat: number
  dormant: number
  other: number
}

export interface DashboardSummary {
  kpis: DashboardKpis
  sales_trend: SalesTrendPoint[]
  today_reservations: Reservation[]
  popular_menus: PopularMenu[]
  customer_segments: CustomerSegments
}
```

`frontend/src/types/index.ts` が `export * from './dashboard'` 済みであることを確認(既存のまま)。

- [ ] **Step 5: mockAdapter の /dashboard ハンドラを置換**

`frontend/src/services/mock/mockAdapter.ts` の `if (method === 'get' && url === '/dashboard') {...}` ブロック(455〜473行付近)を以下で置換(`menus` / `reservations` / `toLocalDateString` / `respond` は同ファイル既存のものを使う):

```ts
    if (method === 'get' && url === '/dashboard') {
      const now = new Date()
      const today = toLocalDateString(now)
      const todayReservations = reservations
        .filter(
          (reservation) =>
            toLocalDateString(reservation.start_at) === today &&
            (reservation.status === 'reserved' || reservation.status === 'visited'),
        )
        .sort((a, b) => a.start_at.localeCompare(b.start_at))

      const trendSales = [182000, 210000, 198000, 246000, 289000, 324000]
      const salesTrend = trendSales.map((sales, index) => {
        const date = new Date(now.getFullYear(), now.getMonth() - (trendSales.length - 1 - index), 1)
        return {
          month: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`,
          sales,
        }
      })

      const popularCounts = [14, 11, 8, 6, 3]
      const popularMenus = menus.slice(0, 5).map((menu, index) => ({
        menu_id: menu.id,
        name: menu.name,
        price: menu.price,
        count: popularCounts[index] ?? 1,
      }))

      return respond(config, {
        data: {
          kpis: {
            new_customers: { current: 12, previous: 10 },
            reservations: { current: 28, previous: 25 },
            sales: { current: 324000, previous: 300000 },
            repeat_rate: { current: 78, previous: 73 },
          },
          sales_trend: salesTrend,
          today_reservations: todayReservations,
          popular_menus: popularMenus,
          customer_segments: { new: 28, repeat: 42, dormant: 6, other: 4 },
        },
      })
    }
```

注意: モック内 `reservations` 配列の要素が `Reservation` 型(customer/menu オブジェクト同梱)であることを確認。異なる場合は同ファイルの整形関数(予約一覧ハンドラが使っているもの)を通す。

- [ ] **Step 6: 型チェックとテスト**

```bash
cd frontend && npm run type-check && npm run test:unit
```

Expected: DashboardPage.vue が旧型参照でエラーになる場合はこの時点では許容(Task 6 で書き換える)。エラーが DashboardPage 起因のみであることを確認して次へ。それ以外のエラーは修正する。

- [ ] **Step 7: コミット**

```bash
git add frontend/
git commit -m "feat: ダッシュボード新レスポンスの型・モックとchart.jsを導入

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

(type-check が DashboardPage 起因で失敗する場合は Task 6 とまとめてコミットしてもよい)

---

### Task 5: KpiCard 拡張 + ダッシュボード新コンポーネント

**Files:**
- Modify: `frontend/src/components/common/KpiCard.vue`(prefix / delta 追加)
- Create: `frontend/src/components/common/KpiCard.spec.ts`
- Create: `frontend/src/components/dashboard/SalesTrendChart.vue`
- Create: `frontend/src/components/dashboard/TodayReservationList.vue`
- Create: `frontend/src/components/dashboard/PopularMenuList.vue`
- Create: `frontend/src/components/dashboard/CustomerSegmentList.vue`

**Interfaces:**
- Consumes: Task 4 の型(`SalesTrendPoint` / `PopularMenu` / `CustomerSegments` / `Reservation`)
- Produces: KpiCard props `{ label, value, icon, variant?, prefix?, suffix?, delta?: number | null, deltaSuffix? }`。`SalesTrendChart` props `{ trend: SalesTrendPoint[] }`、`TodayReservationList` props `{ reservations: Reservation[] }`、`PopularMenuList` props `{ menus: PopularMenu[] }`、`CustomerSegmentList` props `{ segments: CustomerSegments }`

- [ ] **Step 1: KpiCard の失敗するテストを書く**

`frontend/src/components/common/KpiCard.spec.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import KpiCard from './KpiCard.vue'

describe('KpiCard', () => {
  it('プレフィックスと前月比を表示する', () => {
    const wrapper = mount(KpiCard, {
      props: { label: '売上', value: 324000, icon: 'pi pi-wallet', prefix: '¥', delta: 8.3 },
    })
    expect(wrapper.text()).toContain('¥')
    expect(wrapper.text()).toContain('324,000')
    expect(wrapper.text()).toContain('+8.3%')
    expect(wrapper.find('.kpi-delta').classes()).toContain('is-up')
  })

  it('マイナスの前月比を下向き表示する', () => {
    const wrapper = mount(KpiCard, {
      props: { label: '予約数', value: 20, icon: 'pi pi-calendar', delta: -4.2 },
    })
    expect(wrapper.text()).toContain('-4.2%')
    expect(wrapper.find('.kpi-delta').classes()).toContain('is-down')
  })

  it('delta未指定なら前月比ピルを出さない', () => {
    const wrapper = mount(KpiCard, {
      props: { label: '総顧客数', value: 152, icon: 'pi pi-users' },
    })
    expect(wrapper.find('.kpi-delta').exists()).toBe(false)
  })
})
```

実行: `cd frontend && npm run test:unit -- KpiCard` → Expected: FAIL

- [ ] **Step 2: KpiCard を拡張**

`frontend/src/components/common/KpiCard.vue` の props を以下に変更:

```ts
const props = withDefaults(
  defineProps<{
    label: string
    value: number
    icon: string
    variant?: 'rose' | 'peach' | 'mauve' | 'cream'
    prefix?: string
    suffix?: string
    delta?: number | null
    deltaSuffix?: string
  }>(),
  {
    variant: 'rose',
    prefix: '',
    suffix: '',
    delta: null,
    deltaSuffix: '%',
  },
)
```

template の `.kpi-body` を以下に変更:

```html
    <div class="kpi-body">
      <span class="kpi-label">{{ label }}</span>
      <span class="kpi-value">
        <span v-if="prefix" class="kpi-prefix">{{ prefix }}</span
        >{{ displayValue }}<span v-if="suffix" class="kpi-suffix">{{ suffix }}</span>
      </span>
      <span v-if="delta !== null" class="kpi-delta" :class="delta >= 0 ? 'is-up' : 'is-down'">
        <i :class="delta >= 0 ? 'pi pi-arrow-up-right' : 'pi pi-arrow-down-right'" />
        {{ delta >= 0 ? '+' : '' }}{{ delta }}{{ deltaSuffix }}
      </span>
    </div>
```

style に追加:

```css
.kpi-prefix {
  font-size: 0.95rem;
  margin-right: 0.1rem;
  font-weight: 500;
}

.kpi-delta {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  align-self: flex-start;
  margin-top: 0.2rem;
  padding: 0.12rem 0.5rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 700;
  background: rgba(255, 255, 255, 0.28);
}

.kpi-delta i {
  font-size: 0.62rem;
}

.kpi-delta.is-up {
  color: #eafff2;
}

.kpi-delta.is-down {
  color: #ffe3e3;
}
```

実行: `npm run test:unit -- KpiCard` → Expected: PASS

- [ ] **Step 3: SalesTrendChart を作成**

`frontend/src/components/dashboard/SalesTrendChart.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import Chart from 'primevue/chart'
import type { SalesTrendPoint } from '@/types'

const props = defineProps<{ trend: SalesTrendPoint[] }>()

// --rb-* トークンと同値（chart.js は CSS 変数を解釈できないため）
const ROSE = '#d86c8a'
const ROSE_FILL = 'rgba(216, 108, 138, 0.16)'
const TEXT_MUTED = '#9a8d91'
const GRID = '#f0e4e8'

const chartData = computed(() => ({
  labels: props.trend.map((point) => `${Number(point.month.slice(5))}月`),
  datasets: [
    {
      data: props.trend.map((point) => point.sales),
      borderColor: ROSE,
      backgroundColor: ROSE_FILL,
      fill: true,
      tension: 0.4,
      borderWidth: 2,
      pointBackgroundColor: '#fff',
      pointBorderColor: ROSE,
      pointRadius: 4,
      pointHoverRadius: 5,
    },
  ],
}))

const chartOptions = {
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (context: { parsed: { y: number } }) =>
          `¥${context.parsed.y.toLocaleString('ja-JP')}`,
      },
    },
  },
  scales: {
    x: {
      grid: { display: false },
      ticks: { color: TEXT_MUTED },
    },
    y: {
      beginAtZero: true,
      grid: { color: GRID },
      ticks: {
        color: TEXT_MUTED,
        callback: (value: number | string) => `¥${Number(value).toLocaleString('ja-JP')}`,
      },
    },
  },
}
</script>

<template>
  <div class="chart-wrap">
    <Chart type="line" :data="chartData" :options="chartOptions" class="chart" />
  </div>
</template>

<style scoped>
.chart-wrap {
  position: relative;
  height: 260px;
}

.chart,
.chart-wrap :deep(.p-chart) {
  height: 100%;
}

.chart-wrap :deep(canvas) {
  max-width: 100%;
}
</style>
```

- [ ] **Step 4: TodayReservationList を作成**

`frontend/src/components/dashboard/TodayReservationList.vue`:

```vue
<script setup lang="ts">
import type { Reservation } from '@/types'
import { formatTime, reservationStatusLabel } from '@/utils/format'

defineProps<{ reservations: Reservation[] }>()
</script>

<template>
  <ul class="reservation-list">
    <li v-for="reservation in reservations" :key="reservation.id">
      <RouterLink to="/reservations" class="reservation-row">
        <span class="time">{{ formatTime(reservation.start_at) }}</span>
        <div class="body">
          <span class="name">{{ reservation.customer.name }} 様</span>
          <span class="menu">{{ reservation.menu.name }}</span>
        </div>
        <span class="status" :class="`is-${reservation.status}`">
          {{ reservationStatusLabel(reservation.status) }}
        </span>
      </RouterLink>
    </li>
  </ul>
</template>

<style scoped>
.reservation-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.reservation-row {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.6rem 0.7rem;
  border-radius: 14px;
  text-decoration: none;
  color: var(--rb-text);
  transition: background-color 0.15s ease;
}

.reservation-row:hover {
  background: var(--rb-pink-faint);
}

.time {
  flex-shrink: 0;
  min-width: 52px;
  padding: 0.3rem 0.45rem;
  border-radius: 10px;
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
  font-family: var(--rb-font-display);
  font-weight: 700;
  font-size: 0.85rem;
  text-align: center;
}

.body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
  flex: 1;
}

.name {
  font-weight: 700;
  font-size: 0.92rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.menu {
  font-size: 0.78rem;
  color: var(--rb-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.status {
  flex-shrink: 0;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 700;
}

.status.is-reserved {
  background: var(--rb-pink-tint);
  color: var(--rb-pink-deep);
}

.status.is-visited {
  background: var(--rb-beige-soft);
  color: #7a6a4f;
}
</style>
```

- [ ] **Step 5: PopularMenuList を作成**

`frontend/src/components/dashboard/PopularMenuList.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { PopularMenu } from '@/types'
import { formatNumber } from '@/utils/format'

const props = defineProps<{ menus: PopularMenu[] }>()

const VARIANTS = ['rose', 'peach', 'mauve', 'cream'] as const

const maxCount = computed(() => Math.max(...props.menus.map((menu) => menu.count), 1))

function tileClass(index: number): string {
  return `rb-gradient-${VARIANTS[index % VARIANTS.length] ?? 'rose'}`
}

function barWidth(count: number): string {
  return `${Math.round((count / maxCount.value) * 100)}%`
}
</script>

<template>
  <ul class="menu-list">
    <li v-for="(menu, index) in menus" :key="menu.menu_id" class="menu-row">
      <span class="menu-tile" :class="tileClass(index)"><i class="pi pi-sparkles" /></span>
      <div class="menu-body">
        <div class="menu-head">
          <span class="menu-name">{{ menu.name }}</span>
          <span class="menu-price">
            {{ menu.price != null ? `¥${formatNumber(menu.price)}` : '—' }}
          </span>
        </div>
        <div class="menu-bar-track">
          <div class="menu-bar" :style="{ width: barWidth(menu.count) }" />
        </div>
      </div>
      <span class="menu-count">{{ menu.count }}件</span>
    </li>
  </ul>
</template>

<style scoped>
.menu-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
}

.menu-row {
  display: flex;
  align-items: center;
  gap: 0.8rem;
}

.menu-tile {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  flex-shrink: 0;
  border-radius: 12px;
  color: #fff;
  font-size: 1rem;
}

.menu-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.menu-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}

.menu-name {
  font-weight: 700;
  font-size: 0.88rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.menu-price {
  flex-shrink: 0;
  font-size: 0.78rem;
  color: var(--rb-text-muted);
}

.menu-bar-track {
  height: 6px;
  border-radius: 999px;
  background: var(--rb-pink-faint);
  overflow: hidden;
}

.menu-bar {
  height: 100%;
  border-radius: 999px;
  background: var(--rb-gradient-brand);
}

.menu-count {
  flex-shrink: 0;
  font-size: 0.8rem;
  font-weight: 700;
  color: var(--rb-pink-deep);
}
</style>
```

- [ ] **Step 6: CustomerSegmentList を作成**

`frontend/src/components/dashboard/CustomerSegmentList.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { CustomerSegments } from '@/types'

const props = defineProps<{ segments: CustomerSegments }>()

const items = computed(() => [
  { key: 'new', label: '新規', value: props.segments.new },
  { key: 'repeat', label: 'リピーター', value: props.segments.repeat },
  { key: 'dormant', label: '休眠', value: props.segments.dormant },
  { key: 'other', label: 'その他', value: props.segments.other },
])
</script>

<template>
  <div class="segment-grid">
    <div v-for="item in items" :key="item.key" class="segment" :class="`is-${item.key}`">
      <span class="segment-label">{{ item.label }}</span>
      <span class="segment-value">{{ item.value }}<span class="segment-suffix">名</span></span>
    </div>
  </div>
</template>

<style scoped>
.segment-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.6rem;
}

.segment {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.7rem 0.9rem;
  border-radius: 14px;
  border: 1px solid var(--rb-border);
}

.segment.is-new {
  background: var(--rb-pink-faint);
}

.segment.is-repeat {
  background: var(--rb-pink-tint);
}

.segment.is-dormant {
  background: var(--rb-beige-soft);
}

.segment.is-other {
  background: #fff;
}

.segment-label {
  font-size: 0.75rem;
  color: var(--rb-text-muted);
}

.segment-value {
  font-family: var(--rb-font-display);
  font-weight: 700;
  font-size: 1.25rem;
}

.segment-suffix {
  font-size: 0.75rem;
  font-weight: 500;
  margin-left: 0.1rem;
}
</style>
```

- [ ] **Step 7: テスト実行とコミット**

```bash
cd frontend && npm run test:unit -- KpiCard && npm run format
git add frontend/
git commit -m "feat: KPIカードの前月比表示とダッシュボード用コンポーネントを追加

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: DashboardPage の書き換え + spec

**Files:**
- Modify: `frontend/src/pages/dashboard/DashboardPage.vue`(全面書き換え)
- Create: `frontend/src/pages/dashboard/DashboardPage.spec.ts`

**Interfaces:**
- Consumes: Task 4 の `DashboardSummary` 型・`calcDeltaPercent`、Task 5 の全コンポーネント

- [ ] **Step 1: DashboardPage の失敗するテストを書く**

`frontend/src/pages/dashboard/DashboardPage.spec.ts`(`MenuSummary` 等の実フィールドは `frontend/src/types/menu.ts` を確認して合わせる):

```ts
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { DashboardSummary } from '@/types'
import DashboardPage from './DashboardPage.vue'

const getSummaryMock = vi.hoisted(() => vi.fn())

vi.mock('@/services/dashboardService', () => ({
  dashboardService: { getSummary: getSummaryMock },
}))

function buildSummary(overrides: Partial<DashboardSummary> = {}): DashboardSummary {
  return {
    kpis: {
      new_customers: { current: 12, previous: 10 },
      reservations: { current: 28, previous: 25 },
      sales: { current: 324000, previous: 300000 },
      repeat_rate: { current: 78, previous: 73 },
    },
    sales_trend: [
      { month: '2026-03', sales: 182000 },
      { month: '2026-04', sales: 210000 },
      { month: '2026-05', sales: 198000 },
      { month: '2026-06', sales: 246000 },
      { month: '2026-07', sales: 289000 },
      { month: '2026-08', sales: 324000 },
    ],
    today_reservations: [
      {
        id: 1,
        customer: { id: 1, name: '山田 ひとみ', kana: 'ヤマダ ヒトミ', phone: null },
        menu: { id: 1, name: 'フェイシャルケア', price: 12000, duration_minutes: 60, is_active: true },
        user: { id: 1, name: '佐藤 恵' },
        start_at: '2026-08-29T10:00:00+09:00',
        end_at: '2026-08-29T11:00:00+09:00',
        status: 'reserved',
        source: 'staff',
        note: null,
        created_at: '2026-08-01T10:00:00+09:00',
        updated_at: '2026-08-01T10:00:00+09:00',
      },
    ],
    popular_menus: [{ menu_id: 1, name: 'プレミアムフェイシャル', price: 12000, count: 14 }],
    customer_segments: { new: 28, repeat: 42, dormant: 6, other: 4 },
    ...overrides,
  }
}

async function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/reservations', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()

  const wrapper = mount(DashboardPage, {
    global: {
      plugins: [router, PrimeVue, ToastService],
      stubs: { SalesTrendChart: true },
    },
  })
  await flushPromises()
  return wrapper
}

describe('DashboardPage', () => {
  beforeEach(() => {
    getSummaryMock.mockReset()
    getSummaryMock.mockResolvedValue(buildSummary())
  })

  it('KPIカード4枚を前月比付きで表示する', async () => {
    const wrapper = await mountPage()
    const text = wrapper.text()
    expect(text).toContain('新規顧客数')
    expect(text).toContain('予約数')
    expect(text).toContain('売上')
    expect(text).toContain('リピート率')
    expect(text).toContain('+20%')
    expect(text).toContain('+12%')
    expect(text).toContain('+8%')
    expect(text).toContain('+5pt')
    expect(text).toContain('324,000')
  })

  it('本日の来店予約と人気メニューとセグメントを表示する', async () => {
    const wrapper = await mountPage()
    const text = wrapper.text()
    expect(text).toContain('山田 ひとみ')
    expect(text).toContain('フェイシャルケア')
    expect(text).toContain('プレミアムフェイシャル')
    expect(text).toContain('14件')
    expect(text).toContain('リピーター')
    expect(text).toContain('42')
  })

  it('本日の予約が0件ならEmptyStateを表示する', async () => {
    getSummaryMock.mockResolvedValue(buildSummary({ today_reservations: [] }))
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('本日の予約はありません')
  })
})
```

実行: `cd frontend && npm run test:unit -- DashboardPage` → Expected: FAIL(旧ページのため)

- [ ] **Step 2: DashboardPage.vue を全面書き換え**

script setup:

```ts
import { computed, onMounted, ref } from 'vue'
import Skeleton from 'primevue/skeleton'
import GlassCard from '@/components/common/GlassCard.vue'
import KpiCard from '@/components/common/KpiCard.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import SalesTrendChart from '@/components/dashboard/SalesTrendChart.vue'
import TodayReservationList from '@/components/dashboard/TodayReservationList.vue'
import PopularMenuList from '@/components/dashboard/PopularMenuList.vue'
import CustomerSegmentList from '@/components/dashboard/CustomerSegmentList.vue'
import { useToast } from 'primevue/usetoast'
import { dashboardService } from '@/services/dashboardService'
import { calcDeltaPercent } from '@/utils/format'
import { extractErrorMessage } from '@/utils/apiError'
import type { DashboardSummary } from '@/types'

const toast = useToast()
const summary = ref<DashboardSummary | null>(null)
const loading = ref(true)

const kpiCards = computed(() => {
  if (!summary.value) return []
  const { kpis } = summary.value

  return [
    {
      label: '新規顧客数',
      value: kpis.new_customers.current,
      suffix: '名',
      icon: 'pi pi-user-plus',
      variant: 'rose' as const,
      delta: calcDeltaPercent(kpis.new_customers.current, kpis.new_customers.previous),
      deltaSuffix: '%',
    },
    {
      label: '予約数',
      value: kpis.reservations.current,
      suffix: '件',
      icon: 'pi pi-calendar',
      variant: 'peach' as const,
      delta: calcDeltaPercent(kpis.reservations.current, kpis.reservations.previous),
      deltaSuffix: '%',
    },
    {
      label: '売上',
      value: kpis.sales.current,
      prefix: '¥',
      icon: 'pi pi-wallet',
      variant: 'mauve' as const,
      delta: calcDeltaPercent(kpis.sales.current, kpis.sales.previous),
      deltaSuffix: '%',
    },
    {
      label: 'リピート率',
      value: kpis.repeat_rate.current,
      suffix: '%',
      icon: 'pi pi-heart',
      variant: 'cream' as const,
      delta: Math.round((kpis.repeat_rate.current - kpis.repeat_rate.previous) * 10) / 10,
      deltaSuffix: 'pt',
    },
  ]
})

onMounted(async () => {
  try {
    summary.value = await dashboardService.getSummary()
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: extractErrorMessage(error, 'ダッシュボードの取得に失敗しました'),
      life: 3000,
    })
  } finally {
    loading.value = false
  }
})
```

template:

```html
<template>
  <div class="rb-page">
    <PageHeader
      title="ダッシュボード"
      icon="pi pi-home"
      subtitle="サロン全体の状況をひと目で確認できます"
    />

    <div class="kpi-grid">
      <template v-if="loading">
        <Skeleton v-for="n in 4" :key="n" height="118px" border-radius="20px" />
      </template>
      <template v-else>
        <KpiCard v-for="card in kpiCards" :key="card.label" v-bind="card" />
      </template>
    </div>

    <div class="dash-grid">
      <GlassCard title="売上推移" icon="pi pi-chart-line">
        <Skeleton v-if="loading" height="260px" border-radius="14px" />
        <SalesTrendChart v-else-if="summary" :trend="summary.sales_trend" />
      </GlassCard>

      <GlassCard title="本日の来店予約" icon="pi pi-calendar-clock">
        <template #actions>
          <RouterLink to="/reservations" class="card-link">すべて見る</RouterLink>
        </template>
        <div v-if="loading" class="skeleton-list">
          <Skeleton v-for="n in 4" :key="n" height="52px" border-radius="14px" />
        </div>
        <TodayReservationList
          v-else-if="summary && summary.today_reservations.length > 0"
          :reservations="summary.today_reservations"
        />
        <EmptyState
          v-else
          icon="pi pi-calendar-clock"
          title="本日の予約はありません"
          description="予約カレンダーから予約を登録できます"
        />
      </GlassCard>

      <GlassCard title="人気メニュー" icon="pi pi-star">
        <div v-if="loading" class="skeleton-list">
          <Skeleton v-for="n in 3" :key="n" height="52px" border-radius="14px" />
        </div>
        <PopularMenuList
          v-else-if="summary && summary.popular_menus.length > 0"
          :menus="summary.popular_menus"
        />
        <EmptyState
          v-else
          icon="pi pi-star"
          title="今月の来店実績はまだありません"
          description="来店が確定するとメニュー別の人気が表示されます"
        />
      </GlassCard>

      <GlassCard title="顧客セグメント" icon="pi pi-users">
        <Skeleton v-if="loading" height="96px" border-radius="14px" />
        <CustomerSegmentList v-else-if="summary" :segments="summary.customer_segments" />
      </GlassCard>
    </div>
  </div>
</template>
```

style scoped(既存スタイルを全て置換):

```css
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
}

.dash-grid {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 1.25rem;
  align-items: start;
}

.card-link {
  font-size: 0.82rem;
  font-weight: 500;
  color: var(--rb-pink);
  text-decoration: none;
}

.card-link:hover {
  text-decoration: underline;
}

.skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

@media (max-width: 1023px) {
  .kpi-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .dash-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 599px) {
  .kpi-grid {
    grid-template-columns: 1fr;
  }
}
```

- [ ] **Step 3: テスト・型チェック・整形**

```bash
cd frontend && npm run test:unit && npm run type-check && npm run lint && npm run format
```

Expected: 全て PASS(既存 spec 含む)

- [ ] **Step 4: コミット**

```bash
git add frontend/
git commit -m "feat: ダッシュボードをKPI比較・売上推移・本日の予約・人気メニュー・セグメント構成に刷新

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: AppLayout のレスポンシブ化(モバイルDrawer)

**Files:**
- Modify: `frontend/src/layouts/AppLayout.vue`

**Interfaces:**
- Consumes: PrimeVue `Drawer`(`primevue/drawer`)
- Produces: `<1024px` でサイドバー非表示+ハンバーガー+Drawer、`<600px` でユーザー名非表示

- [ ] **Step 1: script setup を拡張**

import に追加: `import { ref, watch } from 'vue'` / `import { useRoute, useRouter } from 'vue-router'`(useRouter は既存) / `import Drawer from 'primevue/drawer'`。

`const auth = useAuthStore()` の後に追加:

```ts
const route = useRoute()
const menuOpen = ref(false)

watch(
  () => route.path,
  () => {
    menuOpen.value = false
  },
)
```

- [ ] **Step 2: template を変更**

ヘッダーのブランド部分を `.header-left` で包み、ハンバーガーを追加:

```html
    <header class="app-header glass-card">
      <div class="header-left">
        <Button
          icon="pi pi-bars"
          severity="secondary"
          text
          rounded
          class="menu-button"
          aria-label="メニューを開く"
          @click="menuOpen = true"
        />
        <RouterLink to="/dashboard" class="brand">
          <span class="brand-icon"><i class="pi pi-sparkles" /></span>
          <span class="brand-name">Realize Beauty</span>
        </RouterLink>
      </div>
      <div class="header-right">
        <!-- 既存のまま -->
      </div>
    </header>
```

`</header>` の直後(`.app-body` の前)に Drawer を追加(nav は既存サイドバーと同じ `navItems` を使用):

```html
    <Drawer v-model:visible="menuOpen" header="メニュー" class="mobile-drawer">
      <nav class="nav-list">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="nav-item"
          :class="{ active: $route.path.startsWith(item.to) }"
        >
          <i :class="item.icon" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>
    </Drawer>
```

- [ ] **Step 3: style を追加**

```css
.header-left {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.menu-button {
  display: none;
}

@media (max-width: 1023px) {
  .menu-button {
    display: inline-flex;
  }

  .app-sidebar {
    display: none;
  }

  .app-shell {
    padding: 0.75rem 0.75rem 1.25rem;
  }

  .app-header {
    top: 0.75rem;
  }
}

@media (max-width: 599px) {
  .user-name {
    display: none;
  }

  .user-chip {
    padding: 0.15rem;
    background: transparent;
    border: none;
  }
}
```

注意: Drawer のパネルは body 直下に teleport されるが、slot 内の `.nav-list` / `.nav-item` は本コンポーネントのスコープ属性を持つため scoped CSS が効く(既存の .nav-item スタイルがそのまま適用される)。

- [ ] **Step 4: 動作確認・整形・コミット**

```bash
cd frontend && npm run type-check && npm run lint && npm run test:unit && npm run format
git add frontend/
git commit -m "feat: 管理画面レイアウトをレスポンシブ化しモバイル用ドロワーメニューを追加

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: 総合検証

- [ ] **Step 1: バックエンド全テスト**

```bash
cd backend && composer test && ./vendor/bin/pint --test
```

Expected: 全件 PASS / pint 差分なし

- [ ] **Step 2: フロントエンド全チェック**

```bash
cd frontend && npm run test:unit && npm run build && npm run lint
```

Expected: テスト全件 PASS、build(type-check 込み)成功

- [ ] **Step 3: モックモードで目視確認(メインセッションで実施)**

```bash
cd frontend && npm run dev:mock
```

ブラウザで確認する項目:
- /dashboard: KPI 4枚(前月比ピル)、売上推移グラフ、本日の来店予約、人気メニュー、顧客セグメントが描画される
- ウィンドウを 900px / 500px に縮小: KPI が 2×2 → 1カラム、下段が1カラムに折り返し、サイドバーが消えてハンバーガー → Drawer が開閉し、遷移で自動クローズ
- 既存ページ(顧客一覧など)がデスクトップ幅で崩れていない

- [ ] **Step 4: 仕上げ**

spec のステータスを「実装済み(2026-08-29)」に更新してコミット。未コミットの変更が無いことを `git status` で確認。
