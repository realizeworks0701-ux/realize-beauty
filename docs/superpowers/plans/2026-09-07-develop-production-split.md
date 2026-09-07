# develop 環境と本番環境の分離 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `develop` ブランチをデモ兼先行検証環境へ、`main` を本番へデプロイする2環境構成にし、develop では Stripe をテストモードで無料試用できるようにする。

**Architecture:** Render の Blueprint に develop 用 Web サービスを追加する（DB は Neon の無料プランを `DB_URL` で接続するため `databases:` には追加しない）。`APP_ENV=staging` にすることで既存の `StripeClient::assertModeMatchesEnvironment()` がテストキーを要求する側に倒れる。フロントは Cloudflare Workers の環境機能（`env.develop`）で2つ目の Worker を生やす。コード変更は、デモ環境で見え方が壊れる箇所（Stripe 例外の英語 "Server Error"、モード取り違え、デモデータの陳腐化、Neon のコールドスタート）を潰す最小限に留める。

**Tech Stack:** Laravel 13.19 / PHP 8.3 / Vue 3 + TypeScript / Render Blueprint (render.yaml) / Cloudflare Workers Static Assets (wrangler) / Neon PostgreSQL / Stripe

**設計書:** [docs/superpowers/specs/2026-09-07-develop-production-split-design.md](../specs/2026-09-07-develop-production-split-design.md)
**手作業分の手順書:** [docs/runbook-develop-env.md](../../runbook-develop-env.md)

## Global Constraints

- Backend: PHP 8.3 / Laravel 13.19。整形は `cd backend && ./vendor/bin/pint`。テストは `cd backend && composer test`。
- Frontend: TypeScript の `any` を使わない。テストは `cd frontend && npm run test:unit`。型検査は `npm run type-check`。整形は `npm run format`、lint は `npm run lint`。
- 利用者に見えるメッセージはすべて日本語（ADR-030）。
- コメントは過剰に書かず、命名で意図を表す。ただし「なぜそうしたか」が非自明な箇所には理由を書く（既存コードの慣習）。
- `APP_ENV` は Stripe のモードを決める二値スイッチである。`production` は Live キーを要求し、それ以外は Test キーを要求する（[StripeClient.php:146-167](../../../backend/app/Services/Billing/StripeClient.php#L146)）。`STRIPE_ENFORCE_MODE` を false にして回避してはならない。
- Render の Blueprint 同期は**差分ではなくファイル全体を適用**し、ダッシュボードの設定を上書きする。`render.yaml` を触る作業は Task 1 を必ず先に完了させる。
- 例外クラスは `render(Request): JsonResponse` を自前で持つのが本リポジトリの流儀（[FeatureRequiredException.php](../../../backend/app/Exceptions/FeatureRequiredException.php)）。`bootstrap/app.php` にマッピングを足す方式は取らない。
- コミットは各タスク末尾で1つ。メッセージは日本語、`docs:` / `feat:` / `fix:` / `chore:` の接頭辞を使う（既存履歴に準拠）。

## 実行順の制約

- **Task 1 は他のすべてに先行する。** 完了して push し、ユーザーが Render で Manual Sync を済ませるまで Task 2 を push しない。
- Task 2 は、ユーザーが [runbook](../../runbook-develop-env.md) の STEP 0 を完了したことを確認してから push する。
- Task 3〜9 は互いに独立で、順不同に実行できる。
- Task 10（ドキュメント）は最後。

---

### Task 1: render.yaml のプランドリフトを是正する

`render.yaml` は Web サービスと PostgreSQL の両方に `plan: free` と書いてあるが、実際は両方とも有料プラン。Blueprint 同期はダッシュボードの設定を上書きし、ファイル全体を適用するため、**この行が残ったまま `render.yaml` を変更して push すると本番の Postgres が 256MB に降格し「作成30日で失効→削除」の経路に乗る。**

Blueprint 仕様は「既存リソースについて `plan` を省略すると現行プランを保持する」と明記している。実プラン ID を書くより省略の方が事故らない。

**Files:**
- Modify: `render.yaml:9`（databases の `plan: free`）, `render.yaml:19`（services の `plan: free`）

**Interfaces:**
- Produces: `render.yaml` から既存2リソースの `plan` キーが消えた状態。Task 2 はこの上に develop サービスを追記する。

- [ ] **Step 1: 現状の該当行を確認する**

```bash
grep -n "plan: free" render.yaml
```

Expected: 2行ヒットする（`databases` 側と `services` 側）。

- [ ] **Step 2: databases の plan 行を削除する**

`render.yaml` の以下を

```yaml
databases:
  - name: realize-beauty-db
    databaseName: realize_beauty
    user: realize_beauty
    plan: free # 無料枠は期限あり. 本番運用時は有料プランへ
```

こう置き換える。

```yaml
databases:
  - name: realize-beauty-db
    databaseName: realize_beauty
    user: realize_beauty
    # plan は意図的に書かない。Blueprint 同期はファイル全体を適用してダッシュボードの
    # 設定を上書きするため、実態とずれた値を書くと本番DBを降格させる。
    # 仕様上、既存リソースで plan を省略すると現行プランが保持される。
    # https://render.com/docs/blueprint-spec
```

- [ ] **Step 3: services の plan 行を削除し、branch を明示する**

`render.yaml` の以下を

```yaml
  - type: web
    name: realize-beauty-api
    runtime: docker
    rootDir: backend
    dockerfilePath: ./Dockerfile
    plan: free
    healthCheckPath: /up
```

こう置き換える。

```yaml
  - type: web
    name: realize-beauty-api
    runtime: docker
    rootDir: backend
    dockerfilePath: ./Dockerfile
    # plan は意図的に書かない（databases 側のコメントと同じ理由）。
    # branch は develop サービスを足すため明示する。省略時はリポジトリの
    # 既定ブランチを指す暗黙の挙動になり、兄弟サービスが増えると読み解けない。
    branch: main
    healthCheckPath: /up
```

- [ ] **Step 4: 削除できたことを確認する**

```bash
grep -n "plan:" render.yaml; grep -n "branch:" render.yaml
```

Expected: `plan:` は0件。`branch: main` が1件。

- [ ] **Step 5: YAML として壊れていないことを確認する**

```bash
python3 -c "import yaml,sys; d=yaml.safe_load(open('render.yaml')); print('services:', [s['name'] for s in d['services']]); print('databases:', [x['name'] for x in d['databases']]); print('plan keys:', [k for s in d['services']+d['databases'] for k in s if k=='plan'])"
```

Expected: services に `realize-beauty-api`、databases に `realize-beauty-db`、`plan keys: []`。

- [ ] **Step 6: コミット**

```bash
git add render.yaml
git commit -m "fix: render.yaml の plan がダッシュボードの有料プランを降格させないようにする

Blueprint 同期はファイル全体を適用しダッシュボードの設定を上書きする。
plan: free は死んだ文字列ではなく降格指示であり、本番 Postgres を 256MB に
落として「作成30日で失効→削除」の経路に乗せる。仕様上、既存リソースは
plan を省略すると現行プランを保持するため、行ごと削除する。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 7: ユーザーに引き渡す**

このコミットを push したあと、ユーザーが [runbook](../../runbook-develop-env.md) STEP 0 の 1〜3（Auto Sync を No にする / Generate Blueprint で他のドリフトを確認する / Manual Sync）を実行する。**その完了を確認するまで Task 2 を push しない。**

---

### Task 2: render.yaml に develop サービスを追加する

DB は Neon を使うため `databases:` には追加しない。`DB_URL` を `sync: false` で受け取る。

**Files:**
- Modify: `render.yaml`（`services:` の末尾に1エントリ追加）

**Interfaces:**
- Consumes: Task 1 の結果（`plan` キーが無く、本番サービスに `branch: main` がある状態）
- Produces: Render サービス `realize-beauty-api-dev`。Task 7 の Cloudflare 側 `VITE_API_BASE_URL` と、runbook STEP 8/9 の外部登録がこの `APP_URL` に依存する。

- [ ] **Step 1: develop サービスを `services:` の末尾に追記する**

```yaml
  # --- develop 環境（ADR-031） -------------------------------------------
  # 見込み客に見せるデモ兼、先行検証用。本番とは DB・R2・Stripe・LINE・Google の
  # すべてを分ける。DB は Neon（無料プラン・期限なし）を DB_URL で参照するため、
  # このファイルの databases: には追加しない。
  - type: web
    name: realize-beauty-api-dev
    runtime: docker
    rootDir: backend
    dockerfilePath: ./Dockerfile
    # 新規リソースは plan を省略すると 0.5c-512mb（有料）になる。ここは明示する。
    plan: free
    branch: develop
    healthCheckPath: /up
    envVars:
      - key: APP_NAME
        value: Realize Beauty (develop)
      # production 以外にすることが Stripe テストモードの前提条件。
      # StripeClient::assertModeMatchesEnvironment() が Live キーを拒否する側に倒れる。
      # 副作用として Laravel の ConfirmableTrait も無効になり、migrate:fresh /
      # db:wipe / db:seed が確認なしで走る点に注意（demo:reset が独自ガードを持つ）。
      - key: APP_ENV
        value: staging
      # staging でも既定は false だが、見込み客に見せる環境なので明示する
      - key: APP_DEBUG
        value: false
      - key: APP_LOCALE
        value: ja
      # 本番とは必ず別の値にする。鍵を分けておけば、万一本番のDBダンプを入れても
      # LINE/Google の暗号化列を復号できない（制約ではなく安全装置）
      - key: APP_KEY
        sync: false
      - key: APP_URL
        sync: false

      - key: LOG_CHANNEL
        value: stderr
      - key: LOG_LEVEL
        value: info
      - key: SALON_TIMEZONE
        value: Asia/Tokyo
      - key: PHP_CLI_SERVER_WORKERS
        value: '4'
      - key: SANCTUM_EXPIRATION
        value: '720'
      - key: PHOTO_URL_TTL_MINUTES
        value: '60'

      # DB は Neon。接続文字列は必ず直接エンドポイント（ホスト名に -pooler を
      # 含まないもの）を使う。アプリがセッションで search_path と時刻を設定するため。
      - key: DB_CONNECTION
        value: pgsql
      - key: DB_URL
        sync: false
      # DB_URL のクエリ文字列と config の既定値(prefer)が競合しないよう明示する
      - key: DB_SSLMODE
        value: require

      # キューワーカーが存在しないため、既定の database ドライバではジョブが
      # jobs テーブルに溜まったまま実行されない。sync はジョブの例外が
      # DB::transaction の外へ抜けて予約APIを 500 にするため使えない
      # （PublicBookingService.php:135）。deferred はレスポンス送出後に実行する。
      - key: QUEUE_CONNECTION
        value: deferred

      # develop フロント（Worker）のオリジン。未設定なら全拒否（フェイルクローズ）
      - key: CORS_ALLOWED_ORIGINS
        sync: false
      - key: FRONTEND_URL
        sync: false

      # 本番バケットは共用しない。R2 はバケット単位の課金がないため費用は変わらず、
      # プレフィックス分離は未設定時に fail-open して本番のキー空間に合流する。
      - key: FILESYSTEM_DISK
        value: r2
      - key: R2_ACCESS_KEY_ID
        sync: false
      - key: R2_SECRET_ACCESS_KEY
        sync: false
      - key: R2_BUCKET
        sync: false
      - key: R2_ENDPOINT
        sync: false
      - key: R2_PUBLIC_URL
        sync: false

      # 本番とは別のキーにする（AI要約はリクエスト内で同期的に課金される）
      - key: OPENAI_API_KEY
        sync: false

      # develop 専用の OAuth クライアント。
      # {APP_URL}/api/v1/google-calendar/callback を Google Cloud に登録すること。
      - key: GOOGLE_CLIENT_ID
        sync: false
      - key: GOOGLE_CLIENT_SECRET
        sync: false

      # Stripe は named sandbox の Test Mode。sk_test_ / pk_test_ を入れる。
      # Live キーを入れると assertModeMatchesEnvironment() が例外で止める。
      - key: STRIPE_SECRET
        sync: false
      - key: STRIPE_KEY
        sync: false
      # {APP_URL}/api/webhooks/stripe に対して sandbox 側で発行した値。
      # 本番の whsec を貼ると Live イベントが develop の DB に適用される。
      - key: STRIPE_WEBHOOK_SECRET
        sync: false
      # Test Mode 側の Price ID。Live のものとは別で、接頭辞では判別できない。
      - key: STRIPE_PRICE_LITE
        sync: false
      - key: STRIPE_PRICE_STANDARD
        sync: false
      - key: STRIPE_PRICE_PRO
        sync: false
```

- [ ] **Step 2: YAML を検証する**

```bash
python3 -c "
import yaml
d = yaml.safe_load(open('render.yaml'))
names = [s['name'] for s in d['services']]
dev = next(s for s in d['services'] if s['name'] == 'realize-beauty-api-dev')
prod = next(s for s in d['services'] if s['name'] == 'realize-beauty-api')
keys = [e['key'] for e in dev['envVars']]
print('services:', names)
print('dev plan/branch:', dev.get('plan'), dev.get('branch'))
print('prod plan/branch:', prod.get('plan'), prod.get('branch'))
print('dev has fromDatabase:', any('fromDatabase' in e for e in dev['envVars']))
print('APP_ENV:', [e['value'] for e in dev['envVars'] if e['key'] == 'APP_ENV'])
print('QUEUE_CONNECTION:', [e['value'] for e in dev['envVars'] if e['key'] == 'QUEUE_CONNECTION'])
missing = {'APP_KEY','APP_URL','DB_URL','CORS_ALLOWED_ORIGINS','FRONTEND_URL','R2_BUCKET','STRIPE_SECRET','STRIPE_WEBHOOK_SECRET','STRIPE_PRICE_LITE','STRIPE_PRICE_STANDARD','STRIPE_PRICE_PRO','GOOGLE_CLIENT_ID','OPENAI_API_KEY'} - set(keys)
print('missing required keys:', missing)
"
```

Expected:
```
services: ['realize-beauty-api', 'realize-beauty-api-dev']
dev plan/branch: free develop
prod plan/branch: None main
dev has fromDatabase: False
APP_ENV: ['staging']
QUEUE_CONNECTION: ['deferred']
missing required keys: set()
```

- [ ] **Step 3: コミット**

```bash
git add render.yaml
git commit -m "feat: develop 環境の Render サービスを Blueprint に追加する

DB は Neon（無料プラン・期限なし・自動ウェイク）を DB_URL で参照するため
databases: には追加しない。APP_ENV=staging は Stripe をテストモード側に
倒すための前提条件で、QUEUE_CONNECTION=deferred はジョブの例外が予約APIの
500 にならないようにするため。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Stripe 例外を日本語で返す

`StripeConfigException` と `StripeApiException` は素の `RuntimeException` で `render()` を持たない。`APP_DEBUG=false` では `{"message":"Server Error"}` が返り、SPA のトーストがその英語をそのまま表示する。初回デプロイで最も起きやすい誤設定（テスト側カスタマーポータルの保存漏れ / Price ID のモード違い / キーとモードの不一致）が全部ここに落ちる。

例外の `message` には設定内容が入っているため、利用者にはそのまま出さない。詳細はログに残る（`render()` を足してもレポートは行われる）。

**Files:**
- Modify: `backend/app/Services/Billing/StripeConfigException.php`
- Modify: `backend/app/Services/Billing/StripeApiException.php`
- Test: `backend/tests/Feature/StripeConfigTest.php`（末尾に2ケース追加）

**Interfaces:**
- Produces: `StripeConfigException` → HTTP 503、`StripeApiException` → HTTP 502。どちらも `{"message": "<日本語>"}`。

- [ ] **Step 1: 失敗するテストを書く**

`backend/tests/Feature/StripeConfigTest.php` の末尾（最後のメソッドの直後、クラスの閉じ括弧の手前）に追加する。

```php
    /**
     * 設定不備をお客さんの画面に英語の "Server Error" として出さない。
     * 例外の message は設定内容を含むため、利用者向けには一般化した文言を返す。
     */
    public function test_a_config_error_is_returned_as_a_japanese_message(): void
    {
        config(['billing.stripe.secret' => 'sk_live_dummy']);
        $this->actingAsSalonUser(Salon::factory()->withoutSubscription()->create());

        $response = $this->postJson('/api/v1/subscription/checkout', ['plan' => 'lite']);

        $response->assertStatus(503);
        $this->assertSame(
            'お支払い機能の設定に不備があります。管理者にお問い合わせください。',
            $response->json('message'),
        );
    }

    public function test_a_stripe_api_error_is_returned_as_a_japanese_message(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'nope']], 400)]);
        $this->actingAsSalonUser(Salon::factory()->withoutSubscription()->create());

        $response = $this->postJson('/api/v1/subscription/checkout', ['plan' => 'lite']);

        $response->assertStatus(502);
        $this->assertSame(
            'お支払いサービスに接続できませんでした。時間をおいて再度お試しください。',
            $response->json('message'),
        );
    }
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
cd backend && php artisan test --filter=StripeConfigTest
```

Expected: 新規2件が FAIL。`Expected status code 503 but received 500` の形。

- [ ] **Step 3: StripeConfigException に render() を実装する**

`backend/app/Services/Billing/StripeConfigException.php` を全文置き換える。

```php
<?php

namespace App\Services\Billing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Stripe の設定不備（未設定、または Live/Test キーと APP_ENV の取り違え）。
 *
 * 本番に Test キー、開発に Live キーが入った状態で決済フローを走らせないための安全弁。
 *
 * getMessage() は「どの環境にどちらのキーが入っているか」を含むため利用者へは出さない。
 * 詳細はログに残る（render() を持っていてもレポートは行われる）。
 */
class StripeConfigException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'お支払い機能の設定に不備があります。管理者にお問い合わせください。',
        ], 503);
    }
}
```

- [ ] **Step 4: StripeApiException に render() を実装する**

`backend/app/Services/Billing/StripeApiException.php` を全文置き換える。

```php
<?php

namespace App\Services\Billing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Stripe API がエラーを返した。メッセージに顧客情報を含めない。
 *
 * 上流の障害なので 502 を返す。Stripe 側の文言をそのまま出すと英語が混ざるため、
 * 利用者へは日本語の一般化した文言を返し、詳細はログに残す。
 */
class StripeApiException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'お支払いサービスに接続できませんでした。時間をおいて再度お試しください。',
        ], 502);
    }
}
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
cd backend && php artisan test --filter=StripeConfigTest
```

Expected: すべて PASS。既存ケース（`withoutExceptionHandling()` を使うもの）も引き続き PASS すること。`render()` は例外ハンドラを経由するときだけ効くため、既存の `expectException` 系には影響しない。

- [ ] **Step 6: 整形して全体テスト**

```bash
cd backend && ./vendor/bin/pint && composer test
```

Expected: Pint が差分を出しても FAIL しない。テストは全件 PASS。

- [ ] **Step 7: コミット**

```bash
git add backend/app/Services/Billing/StripeConfigException.php backend/app/Services/Billing/StripeApiException.php backend/tests/Feature/StripeConfigTest.php
git commit -m "fix: Stripe の設定不備とAPIエラーを日本語で返す

これまでは render() が無いため APP_DEBUG=false で Server Error が返り、
SPA のトーストがその英語をそのまま表示していた。テスト側カスタマーポータルの
保存漏れや Price ID のモード違いが、この見え方で見込み客の画面に出る。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Webhook の livemode を検査する

`livemode` はコード中に1箇所も出てこない。現在、Live と Test の分離はエンドポイントごとの `whsec_` のみに依存している。本番の `whsec_` を develop に貼り間違えると Live イベントが develop の DB に適用される。両 DB のサロン ID は 1 から始まるため、`metadata.salon_id` で解決される先は必ず存在してしまう。

`livemode` は Stripe の Event オブジェクトに常に含まれるため、欠けている場合は不正なペイロードとして拒否してよい。

**Files:**
- Modify: `backend/app/Services/Billing/StripeWebhookService.php`
- Modify: `backend/tests/Concerns/ConfiguresStripe.php:88-107`（`signedWebhook` に `livemode` を追加）
- Test: `backend/tests/Feature/StripeWebhookTest.php`（末尾に2ケース追加）

**Interfaces:**
- Consumes: `StripeWebhookEventRepository::markSkipped(string $stripeEventId, string $message): void`（既存）
- Produces: `ConfiguresStripe::signedWebhook(string $type, array $object, string $eventId = 'evt_test_1', ?int $timestamp = null, ?Carbon $createdAt = null, bool $livemode = false): array` — 第6引数 `$livemode` が増える。既存の呼び出しは既定値で動く。

- [ ] **Step 1: テストヘルパに livemode を足す**

`backend/tests/Concerns/ConfiguresStripe.php` の `signedWebhook` を置き換える。

```php
    protected function signedWebhook(
        string $type,
        array $object,
        string $eventId = 'evt_test_1',
        ?int $timestamp = null,
        ?Carbon $createdAt = null,
        bool $livemode = false,
    ): array {
        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created' => ($createdAt ?? Carbon::now()->utc())->getTimestamp(),
            // 実際の Stripe は常に送ってくる。既定は false（configureStripe が sk_test_ を入れるため）
            'livemode' => $livemode,
            'data' => ['object' => $object],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $timestamp ??= Carbon::now()->utc()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        return [$payload, "t={$timestamp},v1={$signature}"];
    }
```

- [ ] **Step 2: 失敗するテストを書く**

`backend/tests/Feature/StripeWebhookTest.php` のクラス末尾に追加する。

```php
    // ---- Live / Test の取り違え ------------------------------

    /**
     * 本番の whsec を develop に貼るなどの取り違えで、Live のイベントが
     * テストモードの環境へ適用されないようにする。
     * 署名は通ってしまうため、livemode がモード分離の最後の砦になる。
     */
    public function test_skips_an_event_whose_livemode_does_not_match_the_secret_key(): void
    {
        $salon = Salon::factory()->onPlan(SubscriptionPlan::Lite)->create();
        $salon->subscription()->update(['stripe_subscription_id' => 'sub_test_1', 'stripe_customer_id' => 'cus_test_1']);

        [$payload, $signature] = $this->signedWebhook(
            'customer.subscription.updated',
            $this->stripeSubscription([
                'status' => 'active',
                'items' => ['data' => [['id' => 'si_test_1', 'price' => ['id' => self::PRICE_PRO]]]],
            ]),
            livemode: true,
        );

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_test_1',
            'status' => 'skipped',
        ]);

        // Lite のまま。Live のイベントがテストモードの環境へ適用されていない
        $this->assertSame(SubscriptionPlan::Lite, $salon->subscription()->firstOrFail()->plan);
    }

    public function test_skips_an_event_without_a_livemode_field(): void
    {
        $payload = json_encode([
            'id' => 'evt_test_no_livemode',
            'object' => 'event',
            'type' => 'customer.subscription.updated',
            'created' => Carbon::now()->utc()->getTimestamp(),
            'data' => ['object' => $this->stripeSubscription()],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $timestamp = Carbon::now()->utc()->getTimestamp();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_test_no_livemode',
            'status' => 'skipped',
        ]);
    }
```

- [ ] **Step 3: テストが落ちることを確認する**

```bash
cd backend && php artisan test --filter=StripeWebhookTest
```

Expected: 新規2件が FAIL（`status` が `processed` になっている、または skipped 行が無い）。既存ケースは Step 1 の変更後も PASS していること。

- [ ] **Step 4: livemode 検査を実装する**

`backend/app/Services/Billing/StripeWebhookService.php` の `handle()` の中、`claim()` の直後・`try {` の直前に挿入する。

```php
        // 署名は通ったが、モードが食い違うイベント。本番の whsec を別環境に貼るなどの
        // 取り違えでしか起きないが、起きた場合はサロンIDが両DBとも1始まりのため
        // metadata.salon_id が必ず「どれかのサロン」に当たってしまう。
        // 監査のため claim 後に skipped として記録する。
        if (! $this->modeMatches($event)) {
            Log::warning('Stripe webhook skipped for livemode mismatch', [
                'event_id' => $eventId,
                'type' => $type,
                'event_livemode' => $event['livemode'] ?? null,
            ]);

            $this->webhookEventRepository->markSkipped(
                $eventId,
                'イベントの livemode が STRIPE_SECRET のモードと一致しません。',
            );

            return;
        }
```

同じクラスの `dispatch()` の直前にプライベートメソッドを追加する。

```php
    /**
     * Stripe の Event は常に livemode を持つ。欠けているものは正規のイベントではない。
     *
     * @param  array<string, mixed>  $event
     */
    private function modeMatches(array $event): bool
    {
        if (! isset($event['livemode']) && ! array_key_exists('livemode', $event)) {
            return false;
        }

        if (! is_bool($event['livemode'])) {
            return false;
        }

        return $event['livemode'] === str_starts_with((string) config('billing.stripe.secret'), 'sk_live_');
    }
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
cd backend && php artisan test --filter=StripeWebhookTest
```

Expected: 全件 PASS。

- [ ] **Step 6: 整形して全体テスト**

```bash
cd backend && ./vendor/bin/pint && composer test
```

Expected: 全件 PASS。

- [ ] **Step 7: コミット**

```bash
git add backend/app/Services/Billing/StripeWebhookService.php backend/tests/Concerns/ConfiguresStripe.php backend/tests/Feature/StripeWebhookTest.php
git commit -m "feat: Stripe Webhook の livemode を検査する

これまでモードの分離はエンドポイントごとの whsec だけに依存しており、
本番の whsec を別環境に貼ると Live のイベントがそのまま適用された。
両環境のDBはサロンIDが1始まりのため、metadata.salon_id は必ず
どれかのサロンに当たってしまう。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: demo:reset コマンドとシーダーのパスワード修正

シーダーは予約日時を実行時の `Carbon::today()` で固定するため、数日で「本日の予約」が空になりダッシュボードが死んで見える。再実行しても各レコードは `firstOrCreate` なので古い行が残って積み上がるだけ。また、見込み客が Stripe の契約を完了すると `stripe_subscription_id` が入り、次の人は「すでに契約中です」で弾かれる。

デモ用パスワードも文書と食い違っている。`Hash::make('email')` は `1260198` で `password` から置換された際の取り違えで、[docs/deployment.md](../../deployment.md) と ADR-028 は `password` と書いている。

無料プランには Shell が無いため、コマンドはローカルから `DB_URL` を develop に向けて実行される。この経路では `APP_ENV` はローカルの値になり本番判定が効かないので、**接続先の実体を確認させるガード**を持たせる。

**Files:**
- Create: `backend/app/Console/Commands/ResetDemoData.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php:55`, `:66`（`Hash::make('email')` → `Hash::make('password')`）
- Test: `backend/tests/Feature/ResetDemoDataCommandTest.php`

**Interfaces:**
- Produces: `php artisan demo:reset [--plan=lite|standard|pro] [--force --expect-database=<name>]`

- [ ] **Step 1: 失敗するテストを書く**

`backend/tests/Feature/ResetDemoDataCommandTest.php` を新規作成する。

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * デモ環境のリセット（ADR-031）。
 *
 * 無料プランには Shell が無いため、このコマンドはローカルから DB_URL を
 * develop に向けて実行される。その経路では APP_ENV はローカルの値になり
 * 本番判定が効かないため、接続先の実体を確認させるガードが要になる。
 */
class ResetDemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('demo:reset', ['--force' => true, '--expect-database' => DB::connection()->getDatabaseName()])
            ->expectsOutputToContain('本番環境では実行できません')
            ->assertExitCode(1);
    }

    public function test_it_refuses_when_the_expected_database_name_does_not_match(): void
    {
        $this->artisan('demo:reset', ['--force' => true, '--expect-database' => 'realize_beauty'])
            ->expectsOutputToContain('--expect-database')
            ->assertExitCode(1);
    }

    public function test_it_refuses_force_without_an_expected_database_name(): void
    {
        $this->artisan('demo:reset', ['--force' => true])
            ->assertExitCode(1);
    }

    public function test_it_rejects_an_unknown_plan(): void
    {
        $this->artisan('demo:reset', [
            '--plan' => 'platinum',
            '--force' => true,
            '--expect-database' => DB::connection()->getDatabaseName(),
        ])
            ->expectsOutputToContain('--plan')
            ->assertExitCode(1);
    }

    public function test_it_asks_for_the_database_name_when_not_forced(): void
    {
        $this->artisan('demo:reset')
            ->expectsQuestion('全データを削除します。続けるにはデータベース名を入力してください', 'wrong_name')
            ->expectsOutputToContain('データベース名が一致しません')
            ->assertExitCode(1);
    }
}
```

`backend/tests/Feature/DatabaseSeederTest.php` を新規作成する。

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * デモ用シードのログイン情報が docs/deployment.md の記述と一致することを守る。
 * develop 環境のログイン情報を見込み客に渡すため、食い違うと詰む。
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeded_owner_can_log_in_with_the_documented_password(): void
    {
        $this->seed();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertOk();
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
cd backend && php artisan test --filter="ResetDemoDataCommandTest|DatabaseSeederTest"
```

Expected: `ResetDemoDataCommandTest` は全件 FAIL（`Command "demo:reset" is not defined.`）。`DatabaseSeederTest` は FAIL（401 が返る）。

- [ ] **Step 3: シーダーのパスワードを直す**

`backend/database/seeders/DatabaseSeeder.php` の2箇所（オーナーとスタッフ）で `Hash::make('email')` を `Hash::make('password')` に置き換える。

```bash
cd backend && sed -i '' "s/Hash::make('email')/Hash::make('password')/g" database/seeders/DatabaseSeeder.php
grep -n "Hash::make" database/seeders/DatabaseSeeder.php
```

Expected: 2行とも `Hash::make('password')`。

- [ ] **Step 4: demo:reset を実装する**

`backend/app/Console/Commands/ResetDemoData.php` を新規作成する。

```php
<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionPlan;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * デモ環境のデータを初期状態に戻す（ADR-031）。
 *
 * シーダーは予約日時を実行時の today() で作るため、放置するとダッシュボードが
 * 空に見える。また Checkout を1人が完了すると stripe_subscription_id が入り、
 * 次の見込み客は「すでに契約中です」で弾かれる。見せる相手が変わるたびに実行する。
 *
 * 無料プランには Shell が無く、実行はローカルから DB_URL を develop へ向けて行う。
 * その経路では APP_ENV がローカルの値になり本番判定が効かないため、
 * 接続先の実体（ホストとデータベース名）を確認させるガードを持つ。
 */
class ResetDemoData extends Command
{
    protected $signature = 'demo:reset
        {--plan=pro : シード後のプラン（lite/standard/pro）。lite にするとアップグレード導線を見せられる}
        {--force : 対話確認を省略する。--expect-database が必須になる}
        {--expect-database= : --force のときに接続先データベース名として期待する値}';

    protected $description = 'デモ用データを再投入して初期状態に戻す（本番では実行不可）';

    private const CONFIRM_QUESTION = '全データを削除します。続けるにはデータベース名を入力してください';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:reset は本番環境では実行できません。');

            return self::FAILURE;
        }

        $plan = SubscriptionPlan::tryFrom((string) $this->option('plan'));

        if ($plan === null) {
            $this->error('--plan は lite / standard / pro のいずれかを指定してください。');

            return self::FAILURE;
        }

        $connection = DB::connection();
        $database = $connection->getDatabaseName();

        $this->line('接続先ホスト : '.($connection->getConfig('host') ?? '(unknown)'));
        $this->line("データベース : {$database}");
        $this->newLine();

        if (! $this->confirmTarget($database)) {
            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);

        if ($plan !== SubscriptionPlan::Pro) {
            Subscription::query()->update(['plan' => $plan->value]);
            $this->line("プランを {$plan->label()} に変更しました。");
        }

        $this->info('デモデータを初期状態に戻しました。');

        return self::SUCCESS;
    }

    /**
     * APP_ENV ではなく接続先の実体を確認させる。
     * ローカルから DB_URL を向け違えた場合を捕まえられるのはこの検査だけ。
     */
    private function confirmTarget(string $database): bool
    {
        if ($this->option('force')) {
            if ($this->option('expect-database') !== $database) {
                $this->error("--force を使うときは --expect-database={$database} を指定してください。");

                return false;
            }

            return true;
        }

        if ($this->ask(self::CONFIRM_QUESTION) !== $database) {
            $this->error('データベース名が一致しません。中止しました。');

            return false;
        }

        return true;
    }
}
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
cd backend && php artisan test --filter="ResetDemoDataCommandTest|DatabaseSeederTest"
```

Expected: 全件 PASS。

- [ ] **Step 6: 整形して全体テスト**

```bash
cd backend && ./vendor/bin/pint && composer test
```

Expected: 全件 PASS。`CreateSalonOwnerCommandTest` など既存のコマンドテストも壊れていないこと。

- [ ] **Step 7: コミット**

```bash
git add backend/app/Console/Commands/ResetDemoData.php backend/database/seeders/DatabaseSeeder.php backend/tests/Feature/ResetDemoDataCommandTest.php backend/tests/Feature/DatabaseSeederTest.php
git commit -m "feat: demo:reset でデモ環境を初期状態に戻せるようにする

シーダーは実行時の today() で予約を作るため放置すると本日の予約が空になり、
Checkout を1人が完了すると次の見込み客が弾かれる。見せる相手が変わるたびに
実行する運用にする。

ガードは APP_ENV ではなく接続先のデータベース名を見る。無料プランには Shell が
無く、コマンドはローカルから DB_URL を向けて実行されるため、APP_ENV 判定では
向け違いを捕まえられない。

あわせてデモ用パスワードを docs/deployment.md の記述どおり password に戻す
（1260198 の置換取り違えで email になっていた）。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: entrypoint の migrate にリトライを入れる

Neon のコンピュートは5分のアイドルで停止し、次の接続で自動復帰する（数百ms）。ただし `entrypoint.sh` は `migrate --force` を `set -e` の下で一度だけ実行するため、初回接続がタイムアウトするとコンテナが起動しない。本番の DB 再起動時にも同じ形で落ちる。

**Files:**
- Modify: `backend/docker/entrypoint.sh`

**Interfaces:**
- Produces: `entrypoint.sh` が `migrate --force` を最大5回・漸増バックオフで再試行する。全失敗時は非ゼロで終了する（従来どおりコンテナを起動させない）。

- [ ] **Step 1: entrypoint.sh を書き換える**

`backend/docker/entrypoint.sh` の `php artisan migrate --force` の行を、以下の関数定義と呼び出しに置き換える。

```sh
# DB が起きるまで待つ。Neon のコンピュートはアイドルで停止し次の接続で自動復帰するため、
# 単発の migrate を set -e の下で走らせると初回接続のタイムアウトがそのまま起動失敗になる。
# 本番の Render Postgres が再起動した直後も同じ形で落ちる。
# 全試行が失敗したら従来どおり非ゼロで抜ける（壊れたマイグレーションを黙って無視しない）。
migrate_with_retry() {
    attempt=1
    max_attempts=5

    while true; do
        if php artisan migrate --force; then
            return 0
        fi

        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "migrate failed after ${max_attempts} attempts" >&2
            return 1
        fi

        delay=$((attempt * 3))
        echo "migrate failed (attempt ${attempt}/${max_attempts}); retrying in ${delay}s" >&2
        sleep "$delay"
        attempt=$((attempt + 1))
    done
}

migrate_with_retry
```

- [ ] **Step 2: シェルスクリプトとして構文が正しいことを確認する**

```bash
sh -n backend/docker/entrypoint.sh && echo "syntax OK"
```

Expected: `syntax OK`。

- [ ] **Step 3: リトライ挙動を実際に確かめる**

関数だけを取り出し、必ず失敗するコマンドに差し替えて動かす。

```bash
cd /tmp && cat > retry-probe.sh <<'PROBE'
#!/bin/sh
set -e
php() { return 1; }
migrate_with_retry() {
    attempt=1
    max_attempts=3
    while true; do
        if php artisan migrate --force; then
            return 0
        fi
        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "migrate failed after ${max_attempts} attempts" >&2
            return 1
        fi
        delay=1
        echo "migrate failed (attempt ${attempt}/${max_attempts}); retrying in ${delay}s" >&2
        sleep "$delay"
        attempt=$((attempt + 1))
    done
}
migrate_with_retry
PROBE
sh retry-probe.sh; echo "exit=$?"```

Expected: `migrate failed (attempt 1/3)` と `(attempt 2/3)` が出たあと `migrate failed after 3 attempts`、最後に `exit=1`。

```bash
rm -f /tmp/retry-probe.sh
```

- [ ] **Step 4: ローカルの DB に対して実際に通ることを確認する**

```bash
cd backend && php artisan migrate --force
```

Expected: `Nothing to migrate.` もしくは適用済みの表示。エラーにならないこと（リトライ関数が呼ぶコマンド自体が正しいことの確認）。

- [ ] **Step 5: コミット**

```bash
git add backend/docker/entrypoint.sh
git commit -m "fix: 起動時の migrate をリトライして DB のコールドスタートで落ちないようにする

Neon のコンピュートはアイドルで停止し次の接続で自動復帰するため、単発の
migrate を set -e の下で走らせると初回接続のタイムアウトがそのまま
コンテナの起動失敗になる。本番の Postgres が再起動した直後も同じ形で落ちる。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: wrangler に develop 環境を定義する

Cloudflare Workers Static Assets はデプロイ先を `name` で識別する。同じ `wrangler.jsonc` を2つのプロジェクトから使うと、Workers Builds は `The name in your Wrangler configuration file must match the name of your Worker.` で**ビルドが失敗する**（手動 `wrangler deploy` の場合は片方が上書きされる）。

wrangler の環境機能を使うと、`env.develop` を定義するだけで `<top-level-name>-develop` が導出される。`assets` と `compatibility_date` は**継承されるキー**なので env ブロックに再掲しない — 再掲は差異の発生源になる。

また、`wrangler` が package.json に無いと Workers Builds は毎回その日の `npx wrangler` を引く。devDependency として固定する。

**Files:**
- Modify: `frontend/wrangler.jsonc`
- Modify: `frontend/package.json`（devDependencies と scripts）

**Interfaces:**
- Produces: Worker `realize-beauty-develop`。npm スクリプト `deploy`（本番）と `deploy:develop`。

- [ ] **Step 1: wrangler を devDependency に入れる**

```bash
cd frontend && npm install --save-dev wrangler
```

- [ ] **Step 2: wrangler.jsonc に env.develop を追加する**

`frontend/wrangler.jsonc` を全文置き換える。

```jsonc
{
  // Cloudflare Workers Static Assets で SPA を配信する（Pages 統合後の静的サイト配信方式）。
  // ビルド成果物 dist/ をそのまま配信し、未知パスは index.html を返してクライアントルーティングに委ねる。
  "name": "realize-beauty",
  "compatibility_date": "2025-06-01",
  "assets": {
    "directory": "./dist",
    "not_found_handling": "single-page-application"
  },
  // develop 環境（ADR-031）。`wrangler deploy --env develop` で
  // Worker 名 realize-beauty-develop が導出される。
  // name は書かない（導出に任せる）。assets と compatibility_date は
  // 継承されるキーなので再掲しない。API の接続先はビルド時定数のため、
  // 環境ごとの差は VITE_API_BASE_URL の値だけで、ここには現れない。
  "env": {
    "develop": {}
  }
}
```

- [ ] **Step 3: package.json に deploy スクリプトを追加する**

`frontend/package.json` の `scripts` に2行足す（`preview` の直後）。

```json
    "deploy": "wrangler deploy",
    "deploy:develop": "wrangler deploy --env develop",
```

- [ ] **Step 4: wrangler が両環境を正しく解決することを確認する**

```bash
cd frontend && npm run build && npx wrangler deploy --env develop --dry-run
```

Expected: 出力に `realize-beauty-develop` が含まれ、エラーで終わらない。`--dry-run` は Cloudflare の資格情報を必要としない。

```bash
cd frontend && npx wrangler deploy --dry-run
```

Expected: 出力に `realize-beauty`（`-develop` が付かない方）が含まれる。

> `--dry-run` が `main` フィールドが無いことを理由に失敗する場合は、代わりに `npx wrangler versions upload --env develop --dry-run` を試す。それも通らない場合は、設定ファイルが JSONC として解釈できることだけ確認して次へ進み、Step 6 の実デプロイで確かめる。

- [ ] **Step 5: 型検査と lint が通ることを確認する**

```bash
cd frontend && npm run type-check && npm run lint
```

Expected: どちらもエラーなし。

- [ ] **Step 6: コミット**

```bash
git add frontend/wrangler.jsonc frontend/package.json frontend/package-lock.json
git commit -m "feat: wrangler に develop 環境を追加する

同じ wrangler.jsonc を2つの Worker プロジェクトから使うと Workers Builds が
name の不一致でビルドに失敗する。env.develop を定義して
realize-beauty-develop を導出させる。assets と compatibility_date は
継承されるキーなので env ブロックには再掲しない。

wrangler を devDependency に固定する。未指定だと Workers Builds が
ビルドごとにその日の npx wrangler を引く。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: 環境バッジを出す

develop のログイン情報は見込み客に渡す。本番と見分けが付かないと、どちらを触っているのか分からなくなる。`VITE_ENV_LABEL` が設定されているときだけ、画面上部にバッジを出す。本番ビルドでは未設定なので何も描画されない。

管理画面と公開予約ページの両方に出す必要があるため、`AppLayout` ではなく `App.vue` に置く。

**Files:**
- Create: `frontend/src/components/common/EnvBadge.vue`
- Create: `frontend/src/components/common/EnvBadge.spec.ts`
- Modify: `frontend/src/App.vue`
- Modify: `frontend/env.d.ts`

**Interfaces:**
- Produces: `EnvBadge.vue`（props なし）。`import.meta.env.VITE_ENV_LABEL` が非空文字列のときだけ `<div class="env-badge">` を描画する。

- [ ] **Step 1: 失敗するテストを書く**

`frontend/src/components/common/EnvBadge.spec.ts` を新規作成する。

```ts
import { describe, expect, it, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import EnvBadge from './EnvBadge.vue'

describe('EnvBadge', () => {
  afterEach(() => {
    vi.unstubAllEnvs()
  })

  it('VITE_ENV_LABEL が未設定なら何も描画しない', () => {
    vi.stubEnv('VITE_ENV_LABEL', '')

    const wrapper = mount(EnvBadge)

    expect(wrapper.find('.env-badge').exists()).toBe(false)
  })

  it('VITE_ENV_LABEL の値をそのまま表示する', () => {
    vi.stubEnv('VITE_ENV_LABEL', 'DEVELOP')

    const wrapper = mount(EnvBadge)

    expect(wrapper.find('.env-badge').text()).toBe('DEVELOP')
  })
})
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
cd frontend && npx vitest run src/components/common/EnvBadge.spec.ts
```

Expected: FAIL。`Failed to resolve import "./EnvBadge.vue"`。

- [ ] **Step 3: EnvBadge を実装する**

`frontend/src/components/common/EnvBadge.vue` を新規作成する。

```vue
<script setup lang="ts">
import { computed } from 'vue'

// develop のログイン情報は見込み客に渡すため、本番と見分けが付く必要がある。
// 本番ビルドでは VITE_ENV_LABEL が未設定なので何も描画されない。
const label = computed(() => import.meta.env.VITE_ENV_LABEL ?? '')
</script>

<template>
  <div v-if="label" class="env-badge">{{ label }}</div>
</template>

<style scoped>
.env-badge {
  position: fixed;
  top: 0;
  left: 50%;
  z-index: 2000;
  transform: translateX(-50%);
  padding: 0.125rem 0.75rem;
  border-radius: 0 0 0.375rem 0.375rem;
  background: #7c3aed;
  color: #fff;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  pointer-events: none;
}
</style>
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
cd frontend && npx vitest run src/components/common/EnvBadge.spec.ts
```

Expected: 2件とも PASS。

- [ ] **Step 5: App.vue に組み込む**

`frontend/src/App.vue` の `<script setup>` の import 群に1行足す。

```ts
import EnvBadge from '@/components/common/EnvBadge.vue'
```

`<template>` を置き換える。

```vue
<template>
  <EnvBadge />
  <Toast position="top-right" />
  <ConfirmDialog />
  <RouterView v-if="isPublic" />
  <AppLayout v-else />
</template>
```

- [ ] **Step 6: env.d.ts に型を足す**

`frontend/env.d.ts` の `ImportMetaEnv` に1エントリ足す（`VITE_USE_MOCK` の直後）。

```ts
  /** 設定されていると画面上部に環境バッジを出す（例 'DEVELOP'）。本番では未設定。 */
  readonly VITE_ENV_LABEL?: string
```

- [ ] **Step 7: 型検査・lint・全テストを通す**

```bash
cd frontend && npm run type-check && npm run lint && npm run test:unit
```

Expected: すべてエラーなし。既存の `AppLayout.spec.ts` も PASS。

- [ ] **Step 8: 実際にバッジが出ることを目視確認する**

```bash
cd frontend && VITE_USE_MOCK=true VITE_ENV_LABEL=DEVELOP npm run dev
```

ブラウザで開き、画面上部中央に紫の `DEVELOP` バッジが出ることを確認する。確認後 Ctrl-C で止める。

- [ ] **Step 9: コミット**

```bash
git add frontend/src/components/common/EnvBadge.vue frontend/src/components/common/EnvBadge.spec.ts frontend/src/App.vue frontend/env.d.ts
git commit -m "feat: VITE_ENV_LABEL で環境バッジを表示する

develop のログイン情報は見込み客に渡すため、本番と見分けが付く必要がある。
管理画面と公開予約ページの両方に出すため App.vue に置く。
本番ビルドでは未設定なので何も描画されない。

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: CI に develop ブランチを追加する

`.github/workflows/ci.yml` の push トリガは `branches: [main]` のみ。`develop` への push が検証されない。`pull_request:` は既に全ブランチを対象にしているため変更不要。

**Files:**
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Produces: `develop` への push でも CI が走る。

- [ ] **Step 1: 現状のトリガを確認する**

```bash
head -12 .github/workflows/ci.yml
```

Expected: `push:` の下に `branches: [main]` がある。

- [ ] **Step 2: develop を追加する**

`.github/workflows/ci.yml` の

```yaml
on:
  push:
    branches: [main]
  pull_request:
```

を

```yaml
on:
  push:
    branches: [main, develop]
  pull_request:
```

に置き換える。

- [ ] **Step 3: YAML として壊れていないことを確認する**

```bash
python3 -c "import yaml; d=yaml.safe_load(open('.github/workflows/ci.yml')); print(d[True] if True in d else d['on'])"
```

Expected: `push` の `branches` が `['main', 'develop']` を含む。

> PyYAML は `on:` を真偽値 `True` として読むため、上のコードは両方の取り方を試している。

- [ ] **Step 4: コミット**

```bash
git add .github/workflows/ci.yml
git commit -m "chore: develop ブランチへの push でも CI を走らせる

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: ドキュメントを更新する

Documentation Driven Development の建て付け上、設計判断は ADR に残す。既存ドキュメントには実装とずれている箇所（Cloudflare Pages / `_redirects` / ブランチ運用の矛盾 / デモパスワード）があり、この作業で触る範囲は併せて直す。

**Files:**
- Create: `docs/decisions/ADR-031-two-environment-deployment.md`
- Modify: `docs/decisions/ADR-022-deployment.md`
- Modify: `docs/decisions/ADR-010-git-workflow.md`
- Modify: `docs/deployment.md`
- Modify: `docs/stripe.md`
- Modify: `docs/standards/git.md`
- Modify: `README.md`
- Modify: `docs/decisions/README.md`（ADR 一覧に ADR-031 を追加）

**Interfaces:**
- Consumes: Task 1〜9 の実装内容
- Produces: ADR-031。他の ADR とドキュメントからこれを参照する。

- [ ] **Step 1: ADR-031 を書く**

`docs/decisions/ADR-031-two-environment-deployment.md` を新規作成する。テンプレートは `docs/decisions/TEMPLATE.md`、書式は既存 ADR（`ADR-029-subscription-billing.md`）に合わせる。以下を必ず含める。

- **Status**: Accepted / **Date**: 2026-09-07
- **Context**: デプロイ先が本番のみで、見込み客に見せるにも検証するにも実顧客のデータを触るしかなかった。develop は「デモ」と「先行検証」の二役を兼ねる。
- **Decision**:
  - `develop` ブランチ → develop 環境、`main` → 本番。
  - develop の `APP_ENV` は `staging`。これが Stripe をテストモード側に倒す唯一の手段で、`STRIPE_ENFORCE_MODE` を無効化してはならない。副作用として Laravel の `ConfirmableTrait` が無効になり `migrate:fresh` / `db:wipe` / `db:seed` が確認なしで走る。
  - develop の DB は Neon 無料プラン（Singapore、`DB_URL` 接続）。Render の無料 Postgres は1ワークスペースに1つで、かつ作成30日で失効するため使わない。
  - R2 は共用せず develop 専用バケットを作る。R2 はバケット単位の課金がないため費用は変わらず、プレフィックス分離は未設定時に fail-open して本番のキー空間に合流するうえ、トークンがバケットスコープなので権限の境界にならない。
  - develop の `QUEUE_CONNECTION` は `deferred`。`sync` はジョブの例外が `DB::transaction` の外へ抜けて予約 API を 500 にする。
  - LINE / Google / OpenAI は develop 専用の資格情報を使う。
  - `render.yaml` に `plan` を書かない（既存リソースは省略で現行プランが保持される。実態とずれた値は降格指示になる）。新規リソースには明示する。
- **Alternatives Considered**: Render の有料 Postgres をもう1つ（$6/月）/ 既存インスタンス内に `CREATE DATABASE`（接続数と PITR がインスタンス単位で、develop の暴走が本番を枯渇させる）/ Render Preview Environments（URL が可変で完全一致の CORS 許可リストに載らない）/ `main` のみで手動昇格。
- **Consequences**: 無料 Web サービスはスリープし復帰に約1分・Shell が使えない（運用コマンドはローカルから実行）/ Google の同意画面がテスト状態のあいだリフレッシュトークンが7日で失効する / デモの鮮度は `demo:reset` の手動実行に依存する。
- **References**: 設計書、runbook、ADR-022、ADR-029、ADR-010。

- [ ] **Step 2: ADR-022 を更新する**

- 2環境構成になったことを追記し、ADR-031 を参照する。
- **Cloudflare Pages → Cloudflare Workers Static Assets** に修正する。`frontend/public/_redirects` はリポジトリに存在せず、SPA フォールバックは `wrangler.jsonc` の `not_found_handling: "single-page-application"` が担っている（`03b5820` で移行済み）。

- [ ] **Step 3: ADR-010 を更新する**

Status を `Accepted` のまま残し、`feature/* → develop → main` へ改めた旨と ADR-031 への参照を追記する。README.md と docs/standards/git.md が既にこの形を書いており、実態を合わせる形になる。

- [ ] **Step 4: docs/deployment.md を更新する**

- 冒頭に「本番の手順書」であることと、develop は [runbook-develop-env.md](../../runbook-develop-env.md) を参照する旨を書く。
- Cloudflare Pages / `_redirects` の記述を Workers Static Assets に直す。
- §3-2 のログイン確認は `admin@example.com` / `password`（Task 5 でシーダーを直したので記述と一致する）。ただし本番では `db:seed` を使わないため、この記述が本番手順に紛れていないか確認する。

- [ ] **Step 5: docs/stripe.md を更新する**

現在の「DEV / PRODUCTION」2列は `DEV = localhost + Stripe CLI` を前提にしており、ホストされた develop 環境で崩れる。**local / develop / production の3列**に改める。あわせて以下を追記する。

- develop は **named sandbox** を使う（レガシーのテストモードは Dashboard 設定の一部を Live と共有する）。
- **テストモードのカスタマーポータル設定を保存する**こと。未保存だとポータル作成が 400 を返す。
- `stripe:check` は env の静的検査であり Stripe と通信しない。Price ID の実在とポータル設定の保存は検出できない。
- Webhook の `livemode` 検査を入れたこと（Task 4）。

- [ ] **Step 6: docs/standards/git.md と README.md を整合させる**

どちらも `main → develop → feature/*` と書いてあるので、ADR-031 で正式化されたことを追記し、ADR-010 との矛盾が解消したことを示す。

- [ ] **Step 7: docs/decisions/README.md に ADR-031 を追加する**

既存の一覧の並びと書式に合わせて1行足す。

- [ ] **Step 8: リンク切れがないことを確認する**

```bash
grep -rn "ADR-031" docs/ README.md | head -20
ls docs/decisions/ADR-031-two-environment-deployment.md
```

Expected: ファイルが存在し、ADR-022 / ADR-010 / docs/decisions/README.md / 設計書から参照されている。

- [ ] **Step 9: コミット**

```bash
git add docs README.md
git commit -m "docs: 2環境構成を ADR-031 として記録し、関連ドキュメントを更新する

あわせて実装とずれていた記述を直す。
- Cloudflare Pages / public/_redirects → Workers Static Assets
  （SPA フォールバックは wrangler.jsonc の not_found_handling が担う）
- ブランチ運用の矛盾（ADR-010 と README/standards）を ADR-031 で解消
- docs/stripe.md を local / develop / production の3列に改める

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## 完了後の確認

すべてのタスクが終わったら、以下をまとめて実行する。

```bash
cd backend && ./vendor/bin/pint --test && composer test
cd ../frontend && npm run type-check && npm run lint && npm run test:unit && npm run build
```

Expected: すべて PASS。

そのうえで `develop` ブランチを作成して push する。

```bash
git switch -c develop && git push -u origin develop
```

以降の作業は [docs/runbook-develop-env.md](../../runbook-develop-env.md) のユーザー作業（STEP 1 以降）に引き継ぐ。

## 本計画のスコープ外

以下は既存課題であり、この計画では触らない。

- 本番のキューワーカー（`type: worker` で `queue:work`）と cron（`type: cron` で `schedule:run`）。`routes/console.php` に登録された3コマンドはどの環境でも一度も動いていない（[runbook-hardening.md §6](../../runbook-hardening.md)）。
- `DB_SSLMODE=require` の本番有効化（[runbook-hardening.md §1](../../runbook-hardening.md)）。
- 本番の `autoDeployTrigger` を `checksPass` に変える判断。
- `envVarGroups` による共通変数の集約。サービス2つ・共通リテラル9個では割に合わない。
