# ADR-029: サブスクリプション課金とプランによる機能制御

## Status

Accepted

---

## Date

2026-09-03

---

## Context

本番公開（2026-07-22）以降、予約コア（ADR-023）・LINE 連携（ADR-024）・Googleカレンダー同期（ADR-025）・
ダッシュボード刷新（ADR-026）と機能を積み上げてきたが、対価を受け取る仕組みが無いまま残っていた。
課金を入れるにあたり、以下が確認された。

- 契約の概念がアプリのどこにも無く、ログインできるサロンは全機能を無条件で使える。
  収益化の経路が無いだけでなく、「どこまで使えるか」をサロンへ提示する手段も無い。
- 機能ごとの変動費が大きく異なる。AI要約は OpenAI の従量課金（ADR-021）、LINE 通知は push の
  無料枠 200通/月（ADR-024）、Googleカレンダー同期は watch チャネルの定期更新
  （ADR-025、`google-calendar:renew-channels`）を伴う。カルテだけを使うサロンと同額にする根拠がない。
- `salons` テーブルに契約に相当するカラムは無い。契約状態・外部の決済識別子・利用期間・解約日を
  置く場所がなく、`role` と同じく「予約済みカラム」で済ませられる規模でもない。
- カード情報を自前で受け取ると PCI DSS の対象になる。MVP の運用体制（個人開発・Render の
  1コンテナ・ADR-028 のハードニングを入れたばかり）で背負える責務ではない。
- Stripe の Price ID は Test Mode と Live Mode で別物であり、かつ `price_` 接頭辞から
  どちらかを判別できない。ADR-028 で DEV と本番の資格情報の取り違えを実際に踏んでいるため、
  同じ事故が課金で起きると請求そのものが壊れる。
- 機能を止める箇所は HTTP リクエストだけではない。リマインダー（`reservations:send-reminders`）・
  LINE / Google の webhook 受信・キュー投入済みジョブは認証ユーザーを持たない経路であり、
  route middleware だけでは塞げない。
- `docs/requirements/MVP.md` の Out of Scope には「決済」が挙がっている。ただし同じ Out of Scope の
  「LINE連携」「Web予約」は、ADR-024 / ADR-023 と要件ドキュメント（`docs/requirements/booking.md` ほか）で
  正式化したうえで実装した前例がある。本件も同じ手順に従い、本 ADR と要件・API・UI ドキュメントで
  正式化する（MVP.md の Out of Scope の記述は本 ADR にあわせて更新する）。

---

## Decision

**決済・請求の Source of Truth は Stripe に置き、アプリは「プラン → 機能」の対応表だけを持って利用可否を判定する。**

プランは3段とし、上位が下位を包含する。

| プラン | 月額（税込・円） | 追加される機能 |
|---|---|---|
| Lite | 980 | `customer` / `medical_record` / `photo` |
| Standard | 1,980 | + `reservation` / `google_calendar` / `line` |
| Pro | 3,980 | + `ai_summary` / `analytics` |

- カタログの正典は `config/billing.php`。プラン名・月額・Stripe Price ID・機能一覧をここに集約し、
  `plans` テーブルは作らない。Price ID のみ env から注入する（環境ごとに異なるため）。
  `SubscriptionPlan` / `Feature` は enum として持ち、月額や機能一覧は enum に埋め込まず config から引く。
- 判定は `EntitlementService::can() / ensure()` の1箇所に集約する。**アプリ中でプラン名を比較しない**
  （「Pro だから AI 要約」と書かない）。同サービスは **scoped**（singleton ではない）で、
  リクエスト内は契約行を1回だけ引きつつ、キューのジョブ境界では破棄される。
- 契約状態は Stripe の `status` をそのまま `SubscriptionStatus` として保持し、読み替えない。
  利用可否は `grantsAccess()` に一本化し、`trialing` / `active` / `past_due` の3つで true を返す。
  **`past_due` では止めない**（Stripe Billing の回収・再試行の途中であるため）。回収が尽きて `unpaid` に
  なった時点で停止する。`cancel_at_period_end` による解約申請中は Stripe 上 `active` のままなので、
  期間終了まで自然に利用できる。
- **契約行が無い、または `grantsAccess()` が false のサロンはプラン無し＝全機能不可**（fail closed）。
  救済用の既定プランは設けない。課金のゲートは「判定できなければ通す」であってはならない。
- DB は新規3テーブルのみとし、既存テーブルへのカラム追加は行わない。
  - `subscriptions`: 1サロン1行（`salon_id` に unique）。Soft Delete は使わず、解約後も行を残して
    `status` で表現する（再契約に備える）。保持するのは `stripe_customer_id` /
    `stripe_subscription_id` / `stripe_price_id` と期間・解約日時のみ。
  - `stripe_webhook_events`: 冪等性の担保だけが目的。`stripe_event_id` の unique 制約が唯一のガード。
    **payload はそのまま保存しない**（カード情報・請求先などの個人情報を持ち込まないため）。
  - `subscription_events`: 業務監査ログ。`started` / `plan_changed` / `payment_failed` /
    `cancel_requested` / `cancel_revoked` / `ended` / `suspended` / `status_changed` を、
    前後のプラン・状態とともに残す。解約申請はプラン・状態が変わらないため、専用の遷移として別に記録する。
  - 既存サロンには migration（`2026_09_03_000004_backfill_salon_subscriptions`）で Pro / active を
    投入し、機能を取り上げない（`BILLING_BACKFILL_PLAN` で変更可）。
- 遮断は3層に分けて置く。**認証ユーザーが居る経路と居ない経路で手段が違う**ため、1箇所では足りない。
  1. route middleware `feature:<key>`（`auth:sanctum` の内側）。`feature:medical_record,ai_summary` の
     ように複数指定した場合はすべてを満たす必要がある。ダッシュボード（`GET /dashboard`）・営業時間・
     認証・`subscription` 系はガードしない。
  2. Service / Job / Console の guard clause。ジョブは投入後にプランが下がる窓があるため
     `handle()` で再判定する。定期実行の横断クエリは `Subscription::scopeGranting(Feature)` を
     `whereHas` に噛ませて **SQL の段階で対象サロンを絞る**。
  3. 外部 webhook（LINE / Google）はプラン対象外なら **200 のまま無視する**。非 2xx を返すと
     送信側が再送を繰り返し、最終的にエンドポイントを無効化するため（ADR-024 の方針を踏襲）。
- 公開Web予約の slug 経路（サロン情報・空き枠・予約作成）は、予約機能を持たないサロンでは
  **403 ではなく 404**（`ModelNotFoundException`）を返す。403 だとスラッグの実在が外部に漏れるため、
  `is_active` が false のサロンと同じ扱いに揃える。一方、発行済みの**予約トークン経路
  （照会・キャンセル）はガードしない** —— ダウングレード前に受けた予約は最後まで扱えるようにする。
- エラー形式は `FeatureRequiredException`（**403**）。`{message, feature, required_plan, current_plan}` を返し、
  フロントがアップグレード導線の文言を組み立てられるようにする。**401 にしてはならない**
  （SPA の apiClient が 401 で認証状態を破棄しログイン画面へ飛ばすため、機能制限が強制ログアウトに化ける）。
- 「高度な分析」は画面を分けず、`GET /dashboard` のレスポンス内で出し分ける。`analytics` を持たない
  プランでは `sales_trend` / `popular_menus` / `customer_segments` を **キーを残したまま null** にし、
  レスポンスの形を変えない（ADR-026 で定義した構造をプランで壊さない）。
- Stripe 連携は公式 SDK を入れず Laravel の `Http` クライアントで実装する（ADR-021・ADR-024 と同方針。
  追加依存なしで `Http::fake()` によるテストが可能）。
  - カード入力は **Stripe Checkout のリダイレクト方式**。Stripe.js / Elements も導入しない。
    カード番号・CVC・有効期限は Stripe がホストする画面で入力され、**Laravel には一切到達しない**。
    支払い方法の変更・請求履歴の確認は Customer Portal に委ねる。
  - Checkout が受け取るのは **plan key（`lite`/`standard`/`pro`）のみ**。Price ID はサーバが config から
    引くため、クライアントが指定した値が Stripe へ渡ることはない。既存の `stripe_customer_id` は
    必ず再利用する（1サロン1 Customer）。
  - プラン変更は subscription item の price 差し替え（`proration_behavior: create_prorations` /
    `payment_behavior: error_if_incomplete`）。**即時反映と日割り精算を Stripe に委ね、
    アプリ側で請求金額を計算しない。**
  - 解約は `cancel_at_period_end = true` で、即時停止しない。期間終了で Stripe が `canceled` にし、
    webhook 経由で利用停止になる。解約申請は取り消せる（resume）。
    **解約しても顧客・カルテ・写真は一切削除しない。** Google 接続・LINE 連携情報も保持する。
  - 支払い失敗（`invoice.payment_failed`）は監査ログに記録するのみで停止しない。
- 契約状態はフロントの申告ではなく **webhook（`POST /api/webhooks/stripe`）でのみ同期する**。
  署名は `Stripe-Signature` の `t` と `v1` を解析し、`HMAC-SHA256("{t}.{payload}", whsec)` を
  `hash_equals` で比較したうえで、`t` の乖離を許容秒数（既定300秒）で検査してリプレイを弾く。
  - 署名検証失敗 → **400**。正常処理・対象外イベント → 200。処理中の例外 → `failed` を記録して
    再スロー（500 で Stripe に再送させる）。
  - 冪等性は `stripe_webhook_events.stripe_event_id` の unique 制約で担保し、`processed` / `skipped` の
    再送は何もしない。
- DEV と本番は Stripe のモードで分離する。`APP_ENV=local` は Test Mode、`APP_ENV=production` は Live Mode。
  `StripeClient::assertModeMatchesEnvironment()` が全 API 呼び出しの手前で突き合わせ、取り違えなら例外にする。
  デプロイ前の事前診断に `php artisan stripe:check` を用意する（秘密鍵は表示せず、モードと設定有無だけを出す）。
- 画面は `/settings/plan`（現在のプラン・状態・期間・解約申請の有無、プラン一覧、契約開始・変更・解約・
  支払い方法の管理）と `/plan-required/:feature`（未契約機能の説明とアップグレード導線）の2つ。
  サイドバーは契約機能に応じて項目を出し分け、AI要約ボタンのように画面内の一機能に留まるものは
  **隠さず無効化して理由を添える**（Googleカレンダー設定画面が権限外の操作に対して取っている流儀に揃える）。

---

## Alternatives Considered

### `plans` / `plan_features` テーブルでカタログを DB に持つ

プラン内容を管理画面から変更できる形が理想だが、**その管理画面自体が存在しない**。
また Stripe の Price ID は環境ごとに異なるため seeder が env 依存になり、`RefreshDatabase` を使う
既存36テストすべてでプランの seeding が必要になる。config に置けば差分がレビュー可能で、
テストは `config()->set()` で完結する。プラン内容の変更頻度も年に数回の規模であり、
デプロイを伴わない変更手段を先に用意する価値は薄いと判断した。

### `laravel/cashier-stripe` を導入する

今回必要な Stripe API は Checkout / Customer Portal / subscription 取得・更新の3〜4本に過ぎない。
ADR-021 以来「公式SDKを入れず `Http` クライアントで書き、`Http::fake()` でテストする」方針で
OpenAI・LINE・Google を実装しており、ここだけ外れると一貫性を失う。加えて cashier は独自のテーブル構成と
モデル trait を持ち込むため、既存の Controller → Service → Repository 構成への影響が大きい。採用しない。

### `salons` テーブルに `plan` カラムを足す

最小の変更に見えるが、契約状態（`trialing` / `past_due` / `unpaid` ...）・Stripe 側の識別子・
利用期間・解約予約を持てず、結局カラムを足し続けることになる。解約履歴も追えない。
`salons` は `RefreshDatabase` を使う既存36テストが揃って触る中心テーブルであり、
課金の都合でここを変えたくない。契約は別テーブルに切り出す。

### `past_due` で即時停止する

未収を早く止められるが、`past_due` は **Stripe Billing の回収・再試行が動いている最中**の状態である。
ここで機能を止めるとカード更新前に利用不能となり、Stripe に任せている回収フローを自分で壊すことになる。
サロン業務は日々の来店に直結するため、一時的な決済失敗で止める副作用のほうが大きい。
回収が尽きた `unpaid` で停止する。

### Stripe の webhook も LINE と同じく常に 200 を返す

LINE は非 2xx が続くとエンドポイントを無効化するため「常に 200」が正しい（ADR-024）。
Stripe は逆で、4xx を設定不備として記録しダッシュボードに失敗として残す。署名検証失敗を 200 で
握り潰すと、**Test の whsec を本番に設定した**ような取り違えに気づけない。署名検証失敗のみ 400 とし、
対象外イベントは 200（skipped）で受理する。

### ダウングレード・解約時に Google watch チャネルや LINE 連携を能動的に解除する

外部リソースの後始末としては筋が良いが、LINE 連携の解除は当該サロンの**全顧客の再連携**を要求し
（ADR-024）、Google 接続の解除は再度の OAuth 同意を要求する。ダウングレードは一時的なことも多く、
再契約時にそのまま復帰できるほうが利用者の損失が小さい。teardown は行わず、
**入口（送信・同期・webhook 受信）を塞ぐだけ**にとどめる。

---

## Consequences

### メリット

- 機能の増減が `config/billing.php` の `features` 配列の変更で完結する。プラン名で分岐するコードが
  アプリ中に散らないため、プラン改定時の影響範囲を読み切れる。
- カード情報がアプリのサーバー・DB・ログのいずれにも到達しない。PCI DSS の負担を負わずに課金できる。
- 請求金額・日割り・回収の再試行を Stripe に委ねたため、金額計算のバグが起きる余地がない。
- 遮断が3層に分かれているため、キュー投入後のダウングレードや認証を持たない定期実行経路でも
  取りこぼさない。定期実行は SQL で対象を絞るため、対象外サロンの分だけ処理が軽くなる。
- Stripe 依存が `StripeClient` と `StripeSignatureVerifier` に閉じており、`Http::fake()` で
  実際の Stripe に接続せずテストできる（ADR-021・ADR-024 と同じテスト戦略が使える）。

### デメリット・注意点

- **契約行が無いサロンは全機能が 403 になる**（fail closed）。バックフィル migration の適用漏れや、
  今後サロンを手動作成する際に契約行を作り忘れると、そのサロンは何も操作できない。
  `salon:create-owner` での初期作成手順とセットで運用する必要がある。
- **Price ID は接頭辞から Test / Live を判別できない。** `stripe:check` は「設定されていること」しか
  確認できず、Test の Price ID を本番に設定する取り違えは検出できない（秘密鍵の取り違えは検出できる）。
  設定時に Stripe ダッシュボードのモードを目視で確認する運用に頼る。
- Stripe の状態をアプリ DB に同期する以上、**webhook の欠落・遅延で状態がずれうる**。
  ずれた場合、サロン側は Customer Portal で実際の契約を確認でき、運用側は Stripe ダッシュボードの
  イベント再送と `stripe:check` で突き合わせられるが、自動で整合を取り戻す仕組みは持っていない。
- Lite のダッシュボードは、KPI・売上推移・本日の予約・人気メニューが**いずれも予約データ由来**であるため
  （ADR-026）、`analytics` の有無に関わらず実質的に数値が埋まらない。「開けるが空」の画面が残る。
  Lite 向けのダッシュボード再設計は本 ADR の対象外とし、当面はアップグレード導線を出すにとどめる。
- 解約後も `subscriptions` の行と顧客・カルテ・写真を保持するため、退会したサロンのデータが
  R2 と DB に残り続ける。データ削除の申し出に応じる手順は未整備で、backlog とする。
- プラン変更・解約の反映は webhook 経由のため、Stripe 側の処理が遅れると画面上の状態が
  数秒〜数十秒古いままになりうる。

---

## References

- [docs/requirements/MVP.md](../requirements/MVP.md)（Out of Scope の「決済」を本 ADR で更新）
- docs/db/ERD.md
- docs/api/endpoints.md（OpenAPI: docs/api/openapi.yaml）
- docs/deployment.md
- [ADR-021](ADR-021-openai-integration.md)（公式SDKを入れず `Http` クライアントで実装する方針の初出）
- [ADR-023](ADR-023-reservation-core.md)（予約コア。`reservation` 機能の範囲）
- [ADR-024](ADR-024-line-integration.md)（LINE連携。webhook を常に 200 で返す方針との対比）
- [ADR-025](ADR-025-google-calendar-sync.md)（Googleカレンダー同期。watch チャネルの定期更新）
- [ADR-026](ADR-026-dashboard-analytics.md)（ダッシュボード。`analytics` の対象3セクション）
- [ADR-028](ADR-028-production-hardening.md)（本番ハードニング。DEV/本番の資格情報分離）
