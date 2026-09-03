# Stripe 連携手順（Test Mode / Live Mode）

サブスクリプション課金の Stripe 側セットアップと動作確認の手順書。
プランと機能制御の仕様は [subscription.md](subscription.md)、設計の背景は
[ADR-029](decisions/ADR-029-subscription-billing.md) を参照。

## DEV と PRODUCTION は完全に別の Stripe である

**最初に頭に入れること。** Stripe は同一アカウント内に Test Mode と Live Mode という
2つの独立した世界を持ち、**Customer も Product も Price も Webhook も API キーも別物**である。
Test Mode で作った `price_xxx` は Live Mode では存在せず、その逆も同じ。

```
  DEV（ローカル / APP_ENV=local）          PRODUCTION（Render / APP_ENV=production）
  ─────────────────────────────           ──────────────────────────────────────
  Stripe  Test Mode                        Stripe  Live Mode
    sk_test_... / pk_test_...                sk_live_... / pk_live_...
    Test の price_xxx                        Live の price_xxx
    Test の whsec_...                        Live の whsec_...
    テストカードで決済                        実在のカードで実課金
    stripe listen で受信                     https://<api>/api/webhooks/stripe で受信
```

| 項目 | DEV | PRODUCTION |
|---|---|---|
| `APP_ENV` | `local` | `production` |
| Stripe のモード | Test Mode | Live Mode |
| `STRIPE_SECRET` | `sk_test_...` | `sk_live_...` |
| `STRIPE_KEY` | `pk_test_...` | `pk_live_...` |
| `STRIPE_PRICE_*` | Test Mode で作った Price ID | Live Mode で作った Price ID |
| `STRIPE_WEBHOOK_SECRET` | `stripe listen` が表示する `whsec_...` | 本番エンドポイントの `whsec_...` |
| Webhook の受信先 | Stripe CLI が localhost へ転送 | `https://<api>/api/webhooks/stripe` |
| カード | Stripe 公式のテストカード（§8） | 実在のカード。**実際に請求される** |

`StripeClient::assertModeMatchesEnvironment()` が**すべての Stripe API 呼び出しの手前で**
この対応を検査し、取り違えていれば `StripeConfigException` を投げて止める（§6）。

> **Test Mode の Price ID を Live で使う／その逆**は、キー検査をすり抜ける唯一の取り違えである。
> Price ID は接頭辞から test / live を判別できないため（どちらも `price_`）、
> `stripe:check` は「設定されていること」しか確認できない。§10 のチェックリストで目視確認する。

---

## 0. 前提

- Stripe 公式 SDK は導入していない。Laravel の `Http` クライアントで実装している
  （ADR-021 と同じ方針。`Http::fake()` でテストできる利点を優先）
- 決済画面は **Stripe Checkout のリダイレクト方式**。Stripe.js / Elements も入れていない
- **カード番号・CVC・有効期限は Stripe がホストする画面で入力され、Laravel には一切到達しない。**
  DB に保持するのは `stripe_customer_id` / `stripe_subscription_id` / `stripe_price_id` だけ
- 請求金額の計算・日割りは行わない。すべて Stripe に委ねる

---

## 1. Stripe アカウントの準備

1. [dashboard.stripe.com](https://dashboard.stripe.com) でアカウントを作る
2. **Test Mode / Live Mode の切り替えはダッシュボード右上のトグル**。以降の作業は
   「今どちらのモードにいるか」を毎回確認してから行う
3. Live Mode で決済を受けるには**事業者情報の審査（本人確認・銀行口座の登録）**が必要。
   審査が通るまで Live のキーは発行されるが決済は受け付けられない。DEV の作業は Test Mode だけで完結する

---

## 2. Product と Price を作る（Test / Live 双方）

**Test Mode と Live Mode の両方で、同じ作業を2回行う。** Price ID は別々になる。

1. **Product catalog → 商品を追加** で商品を3つ作る

   | 商品名 | 料金 | 課金サイクル |
   |---|---|---|
   | Realize Beauty Lite | 980円 | 月額（継続） |
   | Realize Beauty Standard | 1,980円 | 月額（継続） |
   | Realize Beauty Pro | 3,980円 | 月額（継続） |

   - 通貨は **JPY**。日本円はゼロ十進通貨のため、金額欄には `980` と入力する（`98000` ではない）
   - 料金体系は **定額・継続**（recurring / monthly）
   - 金額はアプリ側の `config/billing.php` の `monthly_price` と**必ず一致させる**。
     ズレると画面の表示と実際の請求額が食い違う（請求は Stripe 側が正）

2. 各商品の料金セクションから **Price ID（`price_` で始まる文字列）**をコピーする
3. Test Mode の3つを DEV の `.env` に、Live Mode の3つを本番の環境変数に設定する（§5）

> 価格を変更するときは、**既存の Price を編集せず新しい Price を作って ID を差し替える**。
> Stripe の Price は原則イミュータブルで、既存の契約者は元の Price のまま継続する。
> 既存契約者を新価格へ移す場合は Stripe 側の価格移行を使う。

---

## 3. Customer Portal を有効化する

支払い方法の変更と請求履歴の閲覧は Stripe の画面に委ねている
（`POST /api/v1/subscription/portal` がポータルのURLを返す）。**これも Test / Live 双方で設定する。**

1. **設定 → 請求 → カスタマーポータル** を開く
2. 有効化し、以下を許可する
   - 支払い方法の更新
   - 請求書・領収書の履歴の閲覧
   - 顧客情報（請求先住所・メールアドレス）の更新
3. **プランの変更・解約はポータル側で有効にしないことを推奨する。**
   アプリ側に `POST /subscription/change-plan` と `POST /subscription/cancel` の導線があり、
   二重になって利用者が混乱するため。ポータルで解約された場合も Webhook で同期されるので
   状態が壊れることはないが、導線は1本にまとめる
4. **デフォルトのリダイレクトURL**にフロントの `/settings/plan` を設定する
   （アプリは `return_url` を毎回渡すため必須ではないが、保険として入れておく）

---

## 4. Webhook エンドポイントを登録する

契約状態はフロントの申告ではなく **Webhook 経由でしか同期しない**。登録を忘れると、
決済は通るのにアプリ側がいつまでも未契約のままになる。

**DEV と PRODUCTION で別のエンドポイントを登録し、それぞれの署名シークレットを使う。**
DEV は Stripe CLI を使うのでダッシュボードでの登録は不要（§7）。

### PRODUCTION（Live Mode）

1. **開発者 → Webhook → エンドポイントを追加**
2. エンドポイントURL: `https://<APIのホスト>/api/webhooks/stripe`
   （例 `https://realize-beauty-api.onrender.com/api/webhooks/stripe`）
   - `/api/v1/` **配下ではない**。認証なし・throttle なしのルートである
   - **HTTPS 必須**
3. 送信するイベントを選ぶ

   | イベント | アプリ側の処理 |
   |---|---|
   | `checkout.session.completed` | サロンと `stripe_customer_id` を紐づけ、Stripe から subscription を取り直して同期 |
   | `customer.subscription.created` | 契約内容を同期 |
   | `customer.subscription.updated` | 契約内容を同期（プラン変更・解約申請・状態遷移はすべてここ） |
   | `customer.subscription.deleted` | 契約内容を同期（`canceled` になり利用停止） |
   | `invoice.payment_failed` | `subscription_events` に監査ログを記録（利用は止めない） |
   | `invoice.paid` | 受理のみ。状態は `customer.subscription.updated` で届く |

   上記以外のイベントが届いても `skipped` として 200 を返すだけなので害はないが、
   不要な受信を減らすため必要なものだけを選ぶ

4. 発行された **署名シークレット（`whsec_` で始まる）**を本番の `STRIPE_WEBHOOK_SECRET` に設定する

---

## 5. 環境変数

`backend/.env`（Git 管理外）と、本番は Render の環境変数に設定する。
`backend/.env.example` に説明つきで列挙してある。

| 変数 | 必須 | 内容 |
|---|:---:|---|
| `STRIPE_KEY` | ✓ | Publishable Key。Checkout のリダイレクト方式では未使用だが、将来 Stripe.js を使う場合に備えて保持する |
| `STRIPE_SECRET` | ✓ | Secret Key。**サーバ専用。フロントへ渡さない** |
| `STRIPE_WEBHOOK_SECRET` | ✓ | Webhook の署名シークレット。DEV と本番で別の値 |
| `STRIPE_PRICE_LITE` | ✓ | Lite の Price ID |
| `STRIPE_PRICE_STANDARD` | ✓ | Standard の Price ID |
| `STRIPE_PRICE_PRO` | ✓ | Pro の Price ID |
| `STRIPE_API_BASE_URL` | | 既定 `https://api.stripe.com`。テストのスタブ以外で変更しない |
| `STRIPE_API_VERSION` | | 既定 `2024-06-20` |
| `STRIPE_TIMEOUT` | | 既定 `15`（秒） |
| `STRIPE_WEBHOOK_TOLERANCE` | | 既定 `300`（秒）。署名タイムスタンプの許容差。リプレイの窓を絞る |
| `STRIPE_ENFORCE_MODE` | | 既定 `true`。Live/Test と `APP_ENV` の突き合わせ検査。**テスト以外で false にしない** |
| `BILLING_RETURN_PATH` | | 既定 `/settings/plan`。Checkout / ポータルから戻る SPA のパス |
| `BILLING_BACKFILL_PLAN` | | 既定 `pro`。課金導入前から存在するサロンへ一括付与するプラン（初回マイグレーションのみ） |

戻り先URLは `FRONTEND_URL` + `BILLING_RETURN_PATH` で組み立てる。`FRONTEND_URL` が
本番のフロントURLになっているかも併せて確認する。

```
success_url = {FRONTEND_URL}{BILLING_RETURN_PATH}?checkout=success&session_id={CHECKOUT_SESSION_ID}
cancel_url  = {FRONTEND_URL}{BILLING_RETURN_PATH}?checkout=cancel
```

### DEV の設定例

```dotenv
APP_ENV=local
FRONTEND_URL=http://localhost:5173

STRIPE_KEY=pk_test_〈発行された値〉
STRIPE_SECRET=sk_test_〈発行された値〉
# stripe listen が表示する値。値が変わったら差し替える
STRIPE_WEBHOOK_SECRET=whsec_〈発行された値〉

# Test Mode で作った Price ID
STRIPE_PRICE_LITE=price_〈発行された値〉
STRIPE_PRICE_STANDARD=price_〈発行された値〉
STRIPE_PRICE_PRO=price_〈発行された値〉
```

`.env` を編集したら `php artisan config:clear` を実行する（設定キャッシュが残っていると反映されない）。

### PRODUCTION の設定例（Render の環境変数）

```dotenv
APP_ENV=production
FRONTEND_URL=https://realize-beauty.pages.dev

STRIPE_KEY=pk_live_〈発行された値〉
STRIPE_SECRET=sk_live_〈発行された値〉
# 本番の Webhook エンドポイントに発行された値
STRIPE_WEBHOOK_SECRET=whsec_〈発行された値〉

# Live Mode で作った Price ID（Test のものとは別）
STRIPE_PRICE_LITE=price_〈発行された値〉
STRIPE_PRICE_STANDARD=price_〈発行された値〉
STRIPE_PRICE_PRO=price_〈発行された値〉
```

本番コンテナは起動時に `config:cache` を実行するため、環境変数の変更は**再デプロイで反映される**
（[deployment.md](deployment.md) / [runbook-hardening.md](runbook-hardening.md) と同じ注意点）。

---

## 6. キー取り違えの防止

### 実行時の検査

`StripeClient::assertModeMatchesEnvironment()` が Checkout・ポータル・プラン変更・解約を含む
**すべての Stripe API 呼び出しの手前**で走る。

| 状況 | 結果 |
|---|---|
| `APP_ENV=production` に `sk_test_` | `StripeConfigException`「本番環境に Stripe の Test キーが設定されています。」 |
| 本番以外に `sk_live_` | `StripeConfigException`「本番以外の環境に Stripe の Live キーが設定されています。」 |
| `STRIPE_SECRET` が未設定 | `StripeConfigException`「STRIPE_SECRET が設定されていません。」 |

`STRIPE_ENFORCE_MODE=false` で無効化できるが、**用途は自動テストだけ**。
これは「ローカルから本番の Stripe を叩けてしまう」状態を作らないための最後の砦である。

### デプロイ前の診断コマンド

```sh
php artisan stripe:check
```

秘密鍵そのものは出力せず、モード（test / live）と設定の有無だけを表示する。
設定に問題があれば終了コード 1 を返す。

正常時（DEV）:

```
APP_ENV: local
想定モード: test

STRIPE_SECRET: test モード
STRIPE_KEY: test モード
STRIPE_WEBHOOK_SECRET: 設定済み（whsec_1a******）

Lite      price_1A******（月額 980円）
Standard  price_1B******（月額 1,980円）
Pro       price_1C******（月額 3,980円）

Live/Test モードと APP_ENV は整合しています。
```

取り違えている場合（本番に Test キー）:

```
APP_ENV: production
想定モード: live

STRIPE_SECRET: test モード（この環境では live を使用すること）
STRIPE_KEY: test モード（この環境では live を使用すること）
STRIPE_WEBHOOK_SECRET: 設定済み（whsec_1a******）

Lite      price_1A******（月額 980円）
Standard  price_1B******（月額 1,980円）
Pro       price_1C******（月額 3,980円）

本番環境に Stripe の Test キーが設定されています。Live キー（sk_live_）を設定してください。
```

Price ID が抜けている場合は `Lite      Price ID 未設定` のように該当行がエラーになる。
その状態でも他の機能は動くが、**そのプランは購入できない**
（`GET /api/v1/subscription` の `plans[].is_purchasable` が false になり、画面で選べなくなる）。

---

### 同じ Price を複数プランに割り当てていないか

`php artisan stripe:check` は、複数のプランに同じ Price ID が設定されていないかも確認する。
重複していると Stripe から届いた price をどのプランへ写すかが宣言順で決まり、
プランが黙って取り違えられる（例: Standard の決済で Pro の機能が開く）。
プランごとに必ず別の Price を用意すること。

## 7. ローカルで Webhook を受け取る

ローカルの `localhost:8000` は Stripe から到達できないため、**Stripe CLI に転送させる**。

### 手順

1. Stripe CLI をインストールする（macOS: `brew install stripe/stripe-cli/stripe`）
2. ログインする。ブラウザが開いて認可を求められる

   ```sh
   stripe login
   ```

3. 転送を開始する。**このターミナルは開いたままにしておく**

   ```sh
   stripe listen --forward-to localhost:8000/api/webhooks/stripe
   ```

   起動時に署名シークレットが表示される。

   ```
   > Ready! You are using Stripe API Version [2024-06-20].
     Your webhook signing secret is whsec_〈発行された値〉 (^C to quit)
   ```

4. 表示された `whsec_...` を `backend/.env` の `STRIPE_WEBHOOK_SECRET` に設定し、
   `php artisan config:clear` を実行する。**これを忘れると署名検証に失敗して 400 になる**
5. 別ターミナルで API を起動する（`composer dev`）
6. 動作確認用にイベントを送る

   ```sh
   stripe trigger checkout.session.completed
   stripe trigger customer.subscription.updated
   stripe trigger invoice.payment_failed
   ```

> **`stripe trigger` は Stripe が用意する架空のオブジェクトを送る。**
> `metadata.salon_id` を持たないため、アプリはサロンを解決できず
> `stripe_webhook_events` に `skipped` として記録し 200 を返す。
> これは**署名検証と受信経路が通っていることの確認**であって、契約同期の確認にはならない。
> 契約同期まで確かめるには §9 の手順で実際に Checkout を通す。

### Webhook 側の仕様（DEV / 本番で共通）

| 状況 | HTTP | 記録 |
|---|:---:|---|
| 署名検証に失敗 | **400** | 記録しない。`Log::warning` のみ |
| 処理できた | 200 | `processed` |
| 対象外のイベント種別・該当サロンなし | 200 | `skipped` |
| 処理中に例外 | 500 | `failed`。Stripe が再送する |
| 同一イベントの再送（`processed` / `skipped` 済み） | 200 | 何もしない |

署名検証は `Stripe-Signature` ヘッダの `t` と `v1` を解析し、
`HMAC-SHA256("{t}.{payload}", whsec)` を `hash_equals` で比較したうえで、
`t` の乖離を `STRIPE_WEBHOOK_TOLERANCE` 秒（既定300）で検査してリプレイを弾く。

> 署名エラーで **400 を返すのは意図的**である。LINE / Google の Webhook は
> 再送ループとエンドポイント無効化を避けるため常に 200 を返すが（[subscription.md](subscription.md) §5）、
> Stripe は 4xx を「設定不備」としてダッシュボードに残すため、
> **秘密鍵の取り違えにその場で気づける**。

冪等性は `stripe_webhook_events.stripe_event_id` の unique 制約だけで担保している。
`failed` の記録だけは再送で再処理される。

---

## 8. テストカード

**DEV でも必ず Stripe Test Mode を経由する。** 「任意のカード番号を独自ロジックで通す」実装は禁止。
自前の判定を挟むと、3DS・カード拒否・残高不足といった**本番でしか起きない分岐がテストできず**、
本番で初めて壊れる。カード番号がアプリのコードに触れる設計そのものを作らない。

以下は Stripe 公式のテストカード。**有効期限は将来の任意の日付、CVC は任意の3桁、
郵便番号は任意**でよい（例: `12/34` / `123`）。Test Mode でのみ使える。

| カード番号 | 挙動 | 用途 |
|---|---|---|
| `4242 4242 4242 4242` | 決済成功 | 通常の契約開始・プラン変更の確認 |
| `4000 0000 0000 0002` | カード拒否（`card_declined`） | 決済失敗時の画面表示の確認 |
| `4000 0000 0000 9995` | 残高不足（`insufficient_funds`） | 拒否理由ごとの文言の確認 |
| `4000 0025 0000 3155` | 3D Secure 認証が必要 | 認証ステップを挟む決済の確認 |
| `4000 0000 0000 3220` | 3D Secure 2 認証が必須 | 認証を毎回要求される決済の確認 |
| `3530 1113 3330 0000` | JCB（決済成功） | 日本の加盟店で JCB を受けられることの確認 |

決済フローの詳細と網羅的な一覧は Stripe 公式ドキュメント
（[Testing](https://docs.stripe.com/testing)）を正とする。

> Live Mode ではテストカードは一切使えない。本番の確認は実在のカードで、
> **実際に請求が発生する**（§10）。

---

## 9. 手動での動作確認

DEV で以下を上から順に通す。`stripe listen` を起動したまま行い、
そのターミナルに `checkout.session.completed [200]` のように**転送結果が出ることを毎回確認する**。

### 9-1. 契約開始

`php artisan salon:create-owner` で作ったサロンは、**Stripe と未紐づけの Lite / active**
（`--plan` の既定）の契約行を持つ。契約行が無いと全機能が 403 になるためプロビジョニング時点で用意している。
`GET /api/v1/subscription` の `subscription.is_subscribed` が false の状態が「Stripe での契約前」である。

1. ログインし `/settings/plan` を開く。支払い情報が未登録（`has_payment_method` が false）であること
2. Standard を選んで開始 → Stripe Checkout へリダイレクトされる
3. `4242 4242 4242 4242` で決済する
4. `/settings/plan?checkout=success&session_id=cs_test_...` に戻る
5. 確認すること
   - `GET /api/v1/subscription` の `subscription.status` が `active`、`plan` が `standard`、
     `is_subscribed` と `has_payment_method` が true
   - `GET /api/v1/auth/me` の `user.features` で `reservation` / `line` / `google_calendar` が true、
     `ai_summary` / `analytics` が false
   - ナビに「予約」が現れる
   - `subscription_events` に行が追加される。初回契約は Stripe と紐づくタイミングなので `started`、
     契約中のプラン変更なら `plan_changed`。解約申請は `cancel_requested`、取り消しは `cancel_revoked`

### 9-2. プラン外の機能が遮断される

1. Standard のまま `POST /api/v1/records/{id}/summarize` を叩く
2. **403** と以下のボディが返ること

   ```json
   { "message": "AI要約はProプラン以上でご利用いただけます。",
     "feature": "ai_summary", "required_plan": "pro", "current_plan": "standard" }
   ```

3. **401 でないこと**を必ず確認する（401 だと SPA がログアウトしてしまう）
4. Lite に落として公開予約ページ `/booking/{slug}` を開き、**404** になること

### 9-3. 支払い失敗（past_due）

1. Customer Portal で支払い方法を `4000 0000 0000 0341`
   （カードの登録は成功するが、その後の請求で失敗する Stripe 公式のテストカード）に変更する
2. Stripe ダッシュボード（Test Mode）の請求書から支払いを実行し、失敗させる
3. 確認すること
   - `invoice.payment_failed` が届き、`subscription_events` に `payment_failed` が記録される
   - `subscription.status` が `past_due`、`is_active` が **true のまま**（利用は止まらない）
   - `needs_payment_attention` が true になり、画面に警告バナーが出る

### 9-4. 3D Secure

1. Customer Portal で支払い方法を `4000 0025 0000 3155` に変更する
2. 認証ダイアログが出て、完了するまで決済が確定しないこと
3. 認証を中断した場合に `incomplete` のまま利用できない状態になること

### 9-5. プラン変更

1. Standard → Pro に変更する
2. 確認すること
   - `subscription.plan` が `pro`、`features.ai_summary` / `features.analytics` が true になる
   - ダッシュボードの `sales_trend` / `popular_menus` / `customer_segments` が null でなくなる
   - Stripe の請求書に**日割りの調整項目**が現れる（アプリは金額計算をしない）
3. Pro → Lite に落とし、差額が**次回請求へのクレジット**として繰り越されること。
   ナビから「予約」が消え、予約 API が 403 になること

### 9-6. 解約と解約取消

1. 解約する → `cancel_at_period_end` が true になる
2. **その場では止まらない**こと。`status` は `active` のままで機能も使えること
3. 画面に解約申請中である旨と、利用できる期限（`current_period_end`）が表示されること
4. 解約取消（resume）で `cancel_at_period_end` が false に戻ること
5. 期間終了後の停止を確かめる場合は、Stripe ダッシュボードから即時解約して
   `customer.subscription.deleted` を発生させ、`status` が `canceled` になり全機能が 403 になること

### 9-7. 解約してもデータが消えないこと

1. 解約後に再度 Checkout して契約する
2. 顧客・カルテ・写真が**そのまま残っている**こと
3. Google カレンダー接続・LINE 設定が保持され、そのまま再開できること
4. `stripe_customer_id` が**同じ Customer のまま**再利用されていること（1サロン1 Customer）

### 9-8. Webhook の重複

1. Stripe ダッシュボード（開発者 → Webhook → イベント）で処理済みのイベントを選び、
   **再送信**する。Stripe CLI なら次のコマンドでもよい

   ```sh
   stripe events resend evt_xxxxxxxxxxxxxxxxxxxxxxxx
   ```

2. 確認すること
   - HTTP は 200
   - `stripe_webhook_events` の行が増えず、`status` も変わらない
   - `subscription_events` に**同じ遷移が二重に記録されない**

### 9-9. 署名の検証

```sh
curl -i -X POST http://localhost:8000/api/webhooks/stripe \
  -H 'Content-Type: application/json' \
  -H 'Stripe-Signature: t=1,v1=deadbeef' \
  -d '{"id":"evt_test","type":"invoice.paid"}'
```

**400** が返り、`stripe_webhook_events` に行が追加されないこと。

---

## 10. 本番切り替えチェックリスト

上から順に確認する。**決済は間違えると実際にお金が動く**ので、飛ばさない。

- [ ] Stripe アカウントの**事業者審査が完了**し、Live Mode で決済を受けられる状態になっている
- [ ] **Live Mode で** Product と Price を3つ作った（Test のものとは別。金額は 980 / 1,980 / 3,980）
- [ ] Live Mode で **Customer Portal を有効化**した（Test 側の設定は引き継がれない）
- [ ] Render の環境変数に `STRIPE_SECRET=sk_live_...` と `STRIPE_KEY=pk_live_...` を設定した
- [ ] `STRIPE_PRICE_LITE` / `STRIPE_PRICE_STANDARD` / `STRIPE_PRICE_PRO` に
      **Live Mode の** Price ID を設定した（Test の ID が残っていないか目視で確認する）
- [ ] 本番の Webhook エンドポイントを **Live Mode に登録**した。
      URL は `https://<api>/api/webhooks/stripe` で、**HTTPS** であること
- [ ] 本番の `STRIPE_WEBHOOK_SECRET` に**そのエンドポイントの** `whsec_...` を設定した
      （DEV の Stripe CLI の値ではない）
- [ ] `FRONTEND_URL` が本番のフロントURLになっている（Checkout の戻り先が localhost になっていないか）
- [ ] `STRIPE_ENFORCE_MODE` を false にしていない
- [ ] 再デプロイ後、本番で `php artisan stripe:check` を実行し、
      `APP_ENV: production` / `想定モード: live` / すべて live と表示されること
- [ ] `subscriptions` のバックフィルが効いており、既存サロンが機能を失っていないこと
- [ ] Stripe ダッシュボード（Live）の Webhook で**送信試行が 200 を返している**こと

### 本番での決済確認

**Live Mode ではテストカードが使えず、実在のカードで実際に請求が発生する。**

1. 最も安い Lite で1件だけ契約する
2. Checkout の完了 → `subscription.status` が `active` になる → 機能が開く、まで確認する
3. 確認後、**その場で解約する**。全額返金する場合は Stripe ダッシュボードから返金する
4. 3D Secure・カード拒否の分岐は Test Mode で確認済みとし、本番では繰り返さない

---

## 11. セキュリティ上の約束

- **Secret Key（`sk_...`）をフロントエンドへ渡さない。** API レスポンスにも含めない。
  `SubscriptionResource` は `stripe_customer_id` / `stripe_subscription_id` すら露出させず、
  導線の出し分けに使う `has_payment_method` / `is_subscribed` の真偽値だけを返す
- **キーを Git にコミットしない。** `.env` は Git 管理外で、`.env.example` には
  プレースホルダと説明だけを置く。誤ってコミットしたら、
  修正コミットではなく**まず Stripe ダッシュボードでキーをローテーションする**
- **Webhook は必ず署名を検証する。** 検証を通っていないリクエストで DB を書き換えない。
  タイムスタンプの乖離検査（既定300秒）でリプレイも弾く
- **Price ID をクライアントに指定させない。** `POST /subscription/checkout` と
  `POST /subscription/change-plan` が受け取るのは `plan`（`lite` / `standard` / `pro`）だけで、
  Price ID はサーバが `config/billing.php` から引く。
  クライアントが送った値が Stripe へ渡る経路は存在しない
- **カード情報を保存しない。** Checkout のリダイレクト方式のため、
  カード番号・CVC・有効期限は Laravel に到達しない。DB にも保存しない
- **Webhook の payload をそのまま残さない。** `stripe_webhook_events` に保存するのは
  イベントID・種別・状態・時刻だけで、請求先やカードの情報は入れない
- **Stripe の API エラーをそのままログに書かない。** 請求先情報を含みうるため、
  `StripeClient` は `status` / `error.type` / `error.code` だけを記録する

---

## 11.5. 同期のずれを防ぐ仕組み

Stripe とアプリDBの同期には、次の3つの安全弁を入れてある。いずれも通常運用では意識しなくてよいが、
障害時の切り分けで挙動を知っていると役に立つ。

### 順序の入れ替わり

Stripe は Webhook の配信順序を**保証しない**。解約（`customer.subscription.deleted`）を処理したあとに、
それより前に発生した `customer.subscription.updated` が遅れて届くと、契約が復活してしまう。
これを防ぐため `subscriptions.last_stripe_event_at` に「最後に適用したイベントの発生時刻」を持ち、
それより古い `created` を持つイベントは**受理（200）するが適用しない**。

再送のたびに署名の `t` は新しくなるため、署名の有効期限（`STRIPE_WEBHOOK_TOLERANCE`）とは別の話である。

### 処理中の異常終了

`stripe_webhook_events.status` は `processing` → `processed` / `skipped` / `failed` と遷移する。
処理中にプロセスが落ちる（OOM・タイムアウト・デプロイ）と `processing` のまま残るが、
15分を過ぎた `processing` は「異常終了した」とみなして Stripe の再送で再処理する。
`failed` も同様に再送で復旧する。これが無いと、1度の異常終了でそのイベントが永久に握りつぶされる。

### 二重契約の防止

Checkout 完了から Webhook 到着までの数秒間、アプリDBはまだ「未契約」のままになる。
この窓で利用者が2本目の Checkout を通すと、アプリからは見えないまま Stripe 上に
サブスクリプションが2本でき、二重に課金される。

対策は2段構えにしてある。

1. **戻ってきた時点で取り込む。** `success_url` に含まれる `session_id` を SPA が
   `POST /api/v1/subscription/sync-checkout` へ渡し、Stripe から結果を取り直して即座に反映する。
   Webhook を待たないのでほとんどの場合ここで窓が閉じる。`session_id` は URL 経由で渡るため、
   サーバ側でそのセッションが自サロンのものかを必ず確認する（他サロンのものなら 403）。
2. **2本目を作らせない。** `POST /subscription/checkout` は、DBの契約状態だけでなく
   **Stripe 側の Customer に有効なサブスクリプションが無いか**も直接問い合わせる
   （`GET /v1/subscriptions?customer=…&status=all`）。見つかった場合はその契約をDBへ取り込んだうえで
   422 を返す。ただし初回契約はまだ Customer が無いためこの確認は効かず、1 が担う。

解約済み（`canceled` など利用不可の状態）しか無ければ、再契約として通常どおり Checkout に進む。

### 鮮度（last_stripe_event_at）の進め方

`last_stripe_event_at` は「いま持っている契約情報が、いつ時点のものか」を表す。

- Webhook 起点 … イベントの `created`（発生時刻）
- プラン変更・解約・解約取消・Checkout 完了後の取り直し … **その操作を行った「いま」**

live API の応答はその瞬間の正本なので、鮮度を「いま」に進めておかないと、
操作の直前に発生していた Webhook が後から届いて操作を巻き戻してしまう。

### 契約行がまだ無いサロンの決済

`salon:create-owner` を使わず作られたサロンなど、`subscriptions` に行が無い状態でも Checkout は通る。
決済が済んだ以上は取りこぼせないため、`checkout.session.completed` と `customer.subscription.created`
は行が無ければ**その場で作る**（`metadata.salon_id` が実在するサロンを指す場合のみ）。
存在しないサロンIDを指すイベントは行を作らず `skipped` にする（外部キー違反で再送ループに入らないため）。

---

## 12. 障害時の対処

### 決済は完了したのにアプリが未契約のまま

ほぼ確実に **Webhook が届いていない／処理できていない**。次の順で切り分ける。

1. **Stripe ダッシュボード → 開発者 → Webhook → 該当エンドポイント**を開き、送信試行の結果を見る

   | 記録 | 原因 | 対処 |
   |---|---|---|
   | 送信試行そのものが無い | エンドポイント未登録、またはイベント種別を選んでいない | §4 の登録を確認する |
   | 404 | URL の誤り（`/api/v1/webhooks/stripe` にしている等） | URL を `https://<api>/api/webhooks/stripe` に直す |
   | **400** | 署名検証の失敗。`STRIPE_WEBHOOK_SECRET` の取り違えが最有力。Test の `whsec_` を本番に入れている、CLI の値を使っている、設定キャッシュが古い | 正しい `whsec_` を設定して再デプロイする |
   | 500 | アプリ側の例外 | アプリのログを見る。`stripe_webhook_events` に `failed` が残っている |
   | タイムアウト | API が起動していない、または応答が遅い | `https://<api>/up` で疎通を確認する |

2. **修正したら Stripe ダッシュボードから「再送信」する。** Stripe は失敗したイベントを
   一定期間自動で再試行するが、待たずに手動で再送してよい。Stripe CLI なら

   ```sh
   stripe events resend evt_xxxxxxxxxxxxxxxxxxxxxxxx
   ```

   `processed` / `skipped` 済みのイベントを再送しても二重処理は起きない（§7）。
   `failed` の記録は再送で再処理される

3. それでも状態が合わない場合、Stripe 上の subscription を正として
   `customer.subscription.updated` を再送させる。アプリ側は毎回 Stripe から取り直して同期するため、
   DB を手で書き換える必要はない

### `stripe_webhook_events` テーブルの見方

冪等性の担保が唯一の目的のテーブル。個人情報は入っていない。

| カラム | 内容 |
|---|---|
| `stripe_event_id` | `evt_xxx`。冪等キー。ダッシュボードのイベントIDと突き合わせる |
| `type` | イベント種別 |
| `status` | `processing` / `processed` / `skipped` / `failed` |
| `message` | `skipped` / `failed` の理由（例外クラス名など）。個人情報は含めない |
| `occurred_at` | Stripe 側のイベント発生時刻 |
| `processed_at` | アプリが処理を終えた時刻 |

直近の受信を見る:

```sql
SELECT stripe_event_id, type, status, message, occurred_at, processed_at
FROM stripe_webhook_events
ORDER BY id DESC
LIMIT 20;
```

失敗と対象外だけを見る:

```sql
SELECT stripe_event_id, type, status, message, occurred_at
FROM stripe_webhook_events
WHERE status IN ('failed', 'skipped')
ORDER BY id DESC;
```

| 見えた状態 | 読み方 |
|---|---|
| 行が無い | そもそも届いていない（署名エラーの 400 も記録されない）。ダッシュボード側を見る |
| `skipped` ばかり | `stripe trigger` の架空イベント、または `metadata.salon_id` を解決できていない。手動で作った Stripe 上の subscription をアプリの契約行と結び付けられていない場合に起きる |
| `failed` | アプリの例外。`message` の例外クラスとアプリのログを突き合わせ、修正後に再送する |
| `processing` のまま残っている | 処理中にプロセスが落ちた（OOM・タイムアウト・デプロイ）。15分を過ぎたものは Stripe の再送で自動的に再処理される（§11.5）。急ぐ場合はダッシュボードから再送する。行を手で消す必要はない |

### 契約の履歴を追う

`subscription_events` が業務監査ログ。誰がいつプランを変えた／止まったかはここを見る。

```sql
SELECT occurred_at, type, from_plan, to_plan, from_status, to_status, stripe_event_id
FROM subscription_events
WHERE salon_id = 1
ORDER BY occurred_at DESC;
```

`type` に入る値は `started`（Stripe と紐づいた、または失効状態から利用可能へ復帰）/ `plan_changed` /
`payment_failed` / `cancel_requested`（解約申請）/ `cancel_revoked`（解約の取り消し）/
`suspended`（`unpaid` へ）/ `ended`（`canceled` へ）/ `status_changed`（それ以外の状態遷移）。
`stripe_event_id` から Stripe ダッシュボードの該当イベントへ辿れる。

> **解約の申請でもログが残る。** プランも状態も変わらず `cancel_at_period_end` が true になるだけだが、
> それだけでは「いつ解約されたか」を追えないため、`SubscriptionService::recordTransitions()` が
> `cancel_requested` の行を別途作る。取り消したときは `cancel_revoked`。
> 現在申請中かどうかは `subscriptions.cancel_at_period_end` を直接見る。
