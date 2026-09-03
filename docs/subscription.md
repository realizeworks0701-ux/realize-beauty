# サブスクリプションと機能制御

プランごとに使える機能を切り替える仕組みの仕様書。設計の背景と判断理由は
[ADR-029](decisions/ADR-029-subscription-billing.md)、Stripe 側の設定・運用手順は
[stripe.md](stripe.md) を参照。

- **プラン → 機能の対応表は `backend/config/billing.php` が単一の正**。`plans` テーブルは作らない
- **判定は `EntitlementService` の1箇所に集約**する。アプリ中に「Pro なら」のような分岐を散らさない
- **契約が無い／失効しているサロンは全機能不可**（fail closed）。救済用の既定プランは設けない

---

## 1. プラン

| プラン | 月額（税込） | 使える機能 |
|---|---|---|
| Lite | 980円 | 顧客管理 / カルテ管理 / 写真管理 |
| Standard | 1,980円 | Lite + 予約管理 / Googleカレンダー連携 / LINE連携 |
| Pro | 3,980円 | Standard + AI要約 / 高度な分析 |

上位プランは下位プランの機能をすべて含む。`SubscriptionPlan` enum（`backend/app/Enums/SubscriptionPlan.php`）の
**case の宣言順は安い順**とし、`Feature::minimumPlan()` がこの順序に依存して
「その機能を含む最も安いプラン」を求める。並べ替えると導線の文言が壊れる。

月額・Stripe Price ID・機能一覧は enum ではなく `config/billing.php` にある。
プラン内容の変更をコード変更なしで行えるようにするため。

---

## 2. 機能（Feature）

`App\Enums\Feature` の8ケース。判定は必ずこのキーで行う。

| キー | 日本語ラベル | Lite | Standard | Pro |
|---|---|:---:|:---:|:---:|
| `customer` | 顧客管理 | ○ | ○ | ○ |
| `medical_record` | カルテ管理 | ○ | ○ | ○ |
| `photo` | 写真管理 | ○ | ○ | ○ |
| `reservation` | 予約管理 | − | ○ | ○ |
| `google_calendar` | Googleカレンダー連携 | − | ○ | ○ |
| `line` | LINE連携 | − | ○ | ○ |
| `ai_summary` | AI要約 | − | − | ○ |
| `analytics` | 高度な分析 | − | − | ○ |

補足:

- **メニュー管理は `reservation` に含める。** 施術時間・料金の定義であり、予約と公開Web予約からしか
  参照されないため、独立した機能単位にしない
- **営業時間はサロンの基本情報**として全プランで編集できる（Feature を持たない）
- **基本ダッシュボード（`GET /dashboard`）は全プランで開ける。** 「高度な分析」は同エンドポイントの
  `sales_trend` / `popular_menus` / `customer_segments` の3キーを指す
- **`ai_summary` / `analytics` の専用画面は作らない。** 既存画面の中で出し分ける

---

## 3. 契約状態

`App\Enums\SubscriptionStatus`。**Stripe の `status` をそのまま保持**し、アプリ側で読み替えない。
「利用できるか」の判断は `grantsAccess()` に一本化し、画面やサービスが status を直接比較しない。

| status | 日本語ラベル | `grantsAccess()` | 意味 |
|---|---|:---:|---|
| `trialing` | トライアル中 | **true** | 無料期間中 |
| `active` | 利用中 | **true** | 正常に課金されている |
| `past_due` | お支払い確認中 | **true** | 支払いに失敗し、Stripe が自動再試行中 |
| `canceled` | 解約済み | false | 期間終了で解約された |
| `unpaid` | 利用停止中 | false | 回収フローが尽きた |
| `incomplete` | お支払い手続き未完了 | false | 初回決済（3DS 等）が完了していない |
| `incomplete_expired` | お支払い手続き期限切れ | false | 初回決済が期限内に完了しなかった |
| `paused` | 一時停止中 | false | Stripe 側で一時停止された |

### past_due で止めない理由

`past_due` は「1回目の請求に失敗した」だけの状態で、原因はカードの有効期限切れや一時的な
限度額超過が大半である。Stripe Billing はこの間に自動で再試行とメール督促を行う。
ここで機能を止めると、**数時間後に回収できる見込みの支払いのために営業を止めてしまう**。

回収フローが尽きると Stripe が `unpaid` へ遷移させるので、**利用停止はその時点で初めて起きる**。
画面には `needs_payment_attention`（`past_due` / `unpaid` / `incomplete` で true）を使って
警告バナーを出し、止める前に気づけるようにする。

### 解約申請中

`cancel_at_period_end = true` の間、Stripe 上の status は `active` のままである。
そのため特別な分岐を書かなくても**期間終了までは自然に使える**。期間が終わると Stripe が
`canceled` にし、Webhook 経由で利用停止になる。

### 契約が無い場合

`subscriptions` に行が無い、または `grantsAccess()` が false のサロンは**プランなし＝全機能不可**。
課金のゲートは fail closed でなければならないため、既定プランで救済しない。

契約行はサロンの作成時点で用意する。`php artisan salon:create-owner` は
`--plan`（既定 `lite`）/ `active` の行を Stripe 未紐づけの状態で作る。
Stripe 側の Customer・Subscription は最初の Checkout 完了時に紐づく。

> 課金導入前から存在するサロンには、マイグレーション
> `2026_09_03_000004_backfill_salon_subscriptions.php` が Pro / active を投入する
> （`BILLING_BACKFILL_PLAN` で変更可）。既存利用者から機能を取り上げないため。

---

## 4. 判定の仕組み

```
Stripe Price ID
  → subscriptions.stripe_price_id / subscriptions.plan（SubscriptionPlan enum）
  → config/billing.php の plans[plan].features
  → Feature enum
  → EntitlementService::can() / ensure()
```

`App\Services\Billing\EntitlementService` が唯一の判定窓口。

| メソッド | 用途 |
|---|---|
| `planFor(int $salonId)` | 現在有効なプラン。契約なし・失効なら `null` |
| `can(int $salonId, Feature $feature)` | 利用可否を bool で返す |
| `ensure(int $salonId, Feature $feature)` | 使えなければ `FeatureRequiredException`（403）を投げる。副作用の前に呼ぶ |
| `features(int $salonId)` | 全 Feature を漏れなく列挙した `array<string, bool>`。フロントへ渡す |
| `forget(int $salonId)` | 契約を更新した直後にキャッシュを捨てる |

`AppServiceProvider` で **scoped** に束ねているため、同一リクエスト内で同じサロンを何度問い合わせても
DB アクセスは1回で済む。

### プラン名で分岐しない理由

`if ($plan === 'pro')` を書くと、プラン構成を変えるたびに**アプリ中の分岐を全部探して直す**ことになり、
1箇所直し漏れるとそこだけ古い条件で動き続ける。しかも直し漏れに気づけるのは、
たいてい課金済みの利用者から「使えない」と連絡が来たときである。

Feature キーで判定しておけば、**プラン構成の変更で触るのは `config/billing.php` の `features` 配列だけ**
で済む。「Standard に AI要約を入れる」は config の1行追加で完了し、ミドルウェア・Service・Job・
SQL のすべてに同時に反映される。

横断クエリも同じ根拠を使う。`Subscription::scopeGranting(Feature)` が
`SubscriptionPlan::valuesWithFeature()` と `SubscriptionStatus::grantingAccessValues()` から
`whereIn` を組み立てるため、**SQL にプラン名を直接書く箇所は無い**。

---

## 5. 遮断の3層

利用者が通る経路は API だけではない。認証ユーザーが居ない経路（キュー、定期実行、外部 Webhook）が
あるため、遮断は3層に分けて掛ける。

### (a) ルートミドルウェア `feature:<key>`

`App\Http\Middleware\EnsureFeatureEnabled`（`bootstrap/app.php` で `feature` として alias 登録）。
`auth:sanctum` の**内側でのみ**使う（認証ユーザーからサロンを解決するため）。
複数キーは `feature:medical_record,ai_summary` のように書き、**すべてを満たす必要がある**。

| feature | エンドポイント |
|---|---|
| `customer` | `apiResource('customers')` の5本（`GET/POST /customers`, `GET/PUT/PATCH/DELETE /customers/{id}`） |
| `medical_record` | `GET /records`, `GET/POST /customers/{customerId}/records`, `GET/PATCH/DELETE /records/{recordId}` |
| `medical_record,ai_summary` | `POST /records/{recordId}/summarize` |
| `photo` | `POST /records/{recordId}/photos`, `DELETE /photos/{photoId}` |
| `reservation` | `apiResource('menus')` の5本, `GET/POST /reservations`, `GET/PATCH/DELETE /reservations/{reservationId}`, `GET /booking-page` |
| `line` | `GET/PUT/DELETE /line-settings`, `POST /line-settings/verify` |
| `google_calendar` | `GET /google-calendar`, `GET /google-calendar/busy-blocks`, `PUT /google-calendar/mode`, `POST /google-calendar/auth-url`, `GET /google-calendar/connections/{connectionId}/calendars`, `PUT/DELETE /google-calendar/connections/{connectionId}` |

### (b) Service / Job / Console の guard clause

認証ユーザーが居ない経路は、ミドルウェアが効かないので自前で再判定する。

**公開Web予約（slug 経路）** — `PublicBookingService::findBookableSalonOrFail()` が `reservation` を検査し、
無ければ `ModelNotFoundException` を投げて **404** にする。対象は `findSalon` / `listAvailability` / `create` の3本
（`GET /public/v1/salons/{slug}`, `GET .../availability`, `POST .../reservations`）。

> 403 ではなく 404 にするのは、**403 だとスラッグが実在することを外部に知らせてしまう**ため。
> `is_active` が false のサロンと同じ扱いに揃える。

**ジョブ6本** — 投入後にプランが下がる窓があるため、`handle()` の冒頭で必ず再判定する。

| ジョブ | 必要な feature |
|---|---|
| `SendReservationReminderJob` | `line` |
| `SendBookingConfirmationJob` | `line` |
| `ProcessLineEventJob` | `line` |
| `SendLineReplyJob` | `line` |
| `SyncGoogleCalendarJob` | `google_calendar` |
| `SyncReservationToGoogleJob` | `google_calendar` |

**投入側** — `ReservationService::dispatchGoogleSync()` と `PublicBookingService` の3箇所
（予約作成時の LINE 通知と Google 同期、キャンセル時の Google 同期）でも判定し、
そもそもキューに積まない。キャンセルは feature でガードしないトークン経路だが、
**Google への同期だけは `google_calendar` を持つ場合に限る**。

**定期実行の横断クエリ** — 全サロンを走査するので SQL で絞る。
`ReservationRepository::listForReminder`（`line`）、
`GoogleCalendarConnectionRepository::listActive` / `listExpiringChannels`（`google_calendar`）が
`whereHas('salon.subscription', fn ($q) => $q->granting(Feature::X))` を使う。

### (c) 外部 Webhook はプラン対象外でも 200

`LineWebhookService` と `GoogleCalendarWebhookService` は、プラン対象外なら `Log::info` して
`return` する。**HTTP は 200 のまま**返す。

非 2xx を返すと LINE / Google が再送を繰り返し、最終的にエンドポイント自体を無効化してしまう。
プランを戻したときに連携が死んでいる状態を避けるため、受理はして処理だけ止める。

> Stripe Webhook だけは扱いが異なる。署名検証に失敗したら **400** を返す（[stripe.md](stripe.md) §7）。

---

## 6. ガードしないもの

| 対象 | 理由 |
|---|---|
| `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` | ログインできなければ契約状態の確認もプラン変更もできない |
| `GET /dashboard` | 全プランで開ける。分析3セクションだけを null にして出し分ける |
| `GET/PUT /business-hours` | 営業時間はサロンの基本情報。プランで取り上げるものではない |
| `GET /users` | スタッフ一覧は顧客・カルテ画面の担当者表示に必要で、単独の機能ではない |
| `GET /public/v1/bookings/{token}`, `POST /public/v1/bookings/{token}/cancel` | **ダウングレード前に受けた予約は最後まで扱えるようにする。** 予約済みの顧客が照会・キャンセルできなくなるのは、サロンの都合を顧客に押し付けることになる |
| `subscription` 系6本 | プラン制限の対象外。契約が切れた状態から再契約する導線を塞いではならない |
| `GET /google-calendar/callback` | Google からのブラウザリダイレクトで Bearer を持たない（state で検証）。作られた接続は他の `google_calendar` 経路とジョブが塞ぐので、実際には使えない |
| 各 Webhook（LINE / Google / Stripe） | 認証ユーザーが居ない。§5(c) のとおり Service 層で処理を止める |

### ダッシュボードの分析セクション

`DashboardService::getSummary()` は `analytics` を持たないプランで
`sales_trend` / `popular_menus` / `customer_segments` を **キーを残したまま null** にする。

キーごと消さないのは、**レスポンスの形を変えずに出し分けだけを行う**ため。
OpenAPI の契約と SPA の型（`types/dashboard.ts` の該当3キーは nullable）を壊さずに済み、
フロントは「null ならアップグレード導線」と1箇所で書ける。

---

## 7. 403 のレスポンス形式

`App\Exceptions\FeatureRequiredException` の `render()` が返す。

```json
{
  "message": "予約管理はStandardプラン以上でご利用いただけます。",
  "feature": "reservation",
  "required_plan": "standard",
  "current_plan": "lite"
}
```

| フィールド | 内容 |
|---|---|
| `message` | 表示用の文言。`Feature::label()` と `Feature::minimumPlan()` から組み立てる |
| `feature` | 要求された機能キー |
| `required_plan` | その機能を含む最も安いプラン。どのプランにも無ければ `null` |
| `current_plan` | 現在のプラン。未契約・失効なら `null` |

機械可読な3フィールドを添えるのは、フロントがアップグレード導線
（`/plan-required/:feature`）を自前で組み立てられるようにするため。

### 401 にしてはならない

SPA の `apiClient`（`frontend/src/services/apiClient.ts`）は **401 を受け取ると
`localStorage` のトークンとユーザーを消して `/login` へ遷移する**。

プラン外の機能に触れただけでログアウトさせられ、ログインし直すとまた同じ画面で蹴られる、
という抜け出せないループになる。「認証はできているが権限が足りない」は 403 である。

---

## 7.4. Stripe との同期で守っていること

| 守るもの | 仕組み |
|---|---|
| 順序の入れ替わり | `subscriptions.last_stripe_event_at`（保持している情報の鮮度）より古い情報は適用しない |
| 別契約による上書き | `SubscriptionService::mayTakeOver()`。**いま使えている契約**は、別契約からの「アクセスを与えない状態」のイベントでは上書きしない。3D Secure を中断した Checkout が生む `incomplete` や、その約24時間後の `incomplete_expired` で既存契約を壊さないため。判定は Stripe 連携の有無ではなく `status->grantsAccess()` で行う（バックフィル移行と `salon:create-owner` が作る行は Stripe 未連携のまま有効なので、連携の有無で判定すると既存サロン全件が無防備になる） |
| 二重契約 | ①戻り先の `session_id` を `POST /subscription/sync-checkout` で即取り込み、Webhook を待たずに窓を閉じる ②`startCheckout` が Stripe の `/v1/subscriptions` も引いて2本目を作らせない（初回契約はまだ Customer が無いため①が担う） |
| 二重処理 | `stripe_webhook_events.stripe_event_id` の unique 制約 |
| 処理中の異常終了 | `processing` のまま15分を過ぎた記録は、Stripe の再送で再処理する |
| 決済済みの取りこぼし | 契約行が無いサロンでも、Checkout 完了時に行を起こす（`openContractFor`） |

---

## 7.5. 契約操作の権限

| 操作 | owner | manager | staff |
|------|-------|---------|-------|
| 契約状態の参照（`GET /subscription`） | ○ | ○ | ○ |
| 契約開始（Checkout） | ○ | ○ | × |
| プラン変更 | ○ | ○ | × |
| 解約・解約取消 | ○ | ○ | × |
| お支払い情報の管理（カスタマーポータル） | ○ | ○ | × |

参照を全ロールに開くのは、機能が使えない理由を確認できる必要があるため。変更操作を絞るのは、一般スタッフの操作でサロンの請求が変わらないようにするためで、Googleカレンダーの連携モード変更（[ADR-025](decisions/ADR-025-google-calendar-sync.md)）と同じ扱いとする。判定は `SubscriptionService::assertCanManageBilling()` に集約し、`AuthorizationException`（403）を投げる。

---

## 8. 解約・ダウングレード時のデータの扱い

**顧客・カルテ・写真は一切削除しない。** 解約は「機能へのアクセスを止める」ことであって、
「データを消す」ことではない。カルテは施術の記録であり、サロンが後から参照する必要が生じうる。

**外部連携の接続情報も保持する。** Google カレンダーの接続、LINE のチャネル設定は、
ダウングレードしても行を残す。**能動的な teardown はしない**ため、再契約すればそのまま復帰する。
接続が使われないことは §5 の3層が保証しており、行が残っていても同期は走らない。

`subscriptions` は Soft Delete を持たず、解約後も行を残す（`status` が `canceled` になるだけ）。
`stripe_customer_id` を保持しておくことで、再契約時に**同じ Stripe Customer を再利用**できる
（1サロン1 Customer）。請求履歴が分断されない。

解約は即時停止ではなく `cancel_at_period_end`。支払い済みの期間は最後まで使える。
`POST /subscription/resume` で期間内なら取り消せる。

---

## 9. フロントエンドの出し分け

> **表示制御はセキュリティ対策ではない。** メニューを隠しても URL を直接叩けば API には届く。
> 実際の遮断は常にサーバ側の 403（および公開予約の 404）が担う。
> フロントの `useFeatures()` は**導線と文言のため**だけに存在する。

判定材料は `POST /auth/login` と `GET /auth/me` が返す `user.features` / `user.plan`。
`composables/useFeatures.ts` の `can()` / `requiredPlanFor()` / `featureLabel()` を経由して使う。

| 箇所 | 出し分け |
|---|---|
| `AppLayout.vue` のナビ | `navItems` を computed 化し、`feature` を持つ項目は `can()` が true のときだけ表示。Lite は ダッシュボード / 顧客 / カルテ / 設定、Standard 以上で 予約 が加わる |
| `router/index.ts` | 該当ルートに `meta: { feature }` を付け、`beforeEach` で未契約なら `/plan-required/:feature` へ振り替える |
| [`FeatureLockedPage.vue`](ui/plan-required.md)（`/plan-required/:feature`） | 「この機能は◯◯プラン以上でご利用いただけます」＋そのプランで増える機能の説明＋「プランを見る」ボタン |
| [`PlanSettingsPage.vue`](ui/settings-plan.md)（`/settings/plan`） | 現在のプラン・状態・期間・解約申請の有無、プラン一覧、契約開始 / プラン変更 / 解約 / 解約取消 / 支払い方法の管理。`needs_payment_attention` が true なら警告バナー |
| `SettingsPage.vue` | 「プラン・お支払い」カードを追加。メニュー管理 / LINE連携 / Googleカレンダー連携の導線カードを feature で出し分ける（営業時間設定は常に表示） |
| `DashboardPage.vue` | `sales_trend` / `popular_menus` / `customer_segments` が null のとき、該当カードの代わりにアップグレード導線を表示 |
| `useFeatures.can()` | 機能フラグを**まだ持っていない**セッション（課金導入前に保存された localStorage のユーザー情報）では、出し分けを保留して素通しする。ここで閉じるとデプロイ直後の既存ログインが全画面から締め出されるため。`App.vue` が起動時に `/auth/me` を取り直して解消する。遮断はサーバの 403 が担うので開いていても安全側に倒れる |
| `RecordDetailPage.vue` | AI要約ボタンは**隠さず無効化**し、要約が無い場合は本文側にアップグレード導線（`FeatureUpsell`）を出して理由と導線を添える（`GoogleCalendarSettingsPage` の `isOwnerOrManager` と同じ流儀） |

ナビは隠し、機能内のボタンは無効化して理由を出す。使えるはずの機能が見当たらないより、
「Pro で使えます」と書いてある方が迷わないため。

---

## 10. プランや機能を追加する手順

**順序を守る。** config を先に足しておけば、途中の状態でも既存プランの挙動は変わらない。

### 機能を1つ追加する

1. **`config/billing.php`** — 対象プランの `features` 配列にキーを足す
2. **`App\Enums\Feature`** — case とラベル（`label()`）を追加する
3. **`routes/api.php`** — 対応するエンドポイントに `feature:<key>` を付ける
4. **認証外の経路** — その機能がジョブ・定期実行・外部 Webhook から動くなら、
   §5(b)(c) と同じく guard clause と `scopeGranting` を足す
5. **フロント** — `types/subscription.ts` の `FeatureKey`、`useFeatures.ts` の
   `FEATURE_LABELS` と `PLAN_FEATURES` に同じキーを足す
6. **テスト** — `backend/tests/Feature/` に「含むプランで 200 / 含まないプランで 403」を1組ずつ書く

### プランを1つ追加する

1. **`config/billing.php`** — `plans` にエントリを足し、`label` / `monthly_price` /
   `stripe_price_id`（env 経由）/ `features` を書く
2. **`App\Enums\SubscriptionPlan`** — case を**安い順の正しい位置に**追加する
   （`Feature::minimumPlan()` が宣言順に依存する）
3. **`.env.example` と各環境の `.env`** — `STRIPE_PRICE_<PLAN>` を追加し、
   Test / Live 双方の Price ID を用意する（[stripe.md](stripe.md) §2）
4. **フロント** — `PlanCode` と `useFeatures.ts` の `PLAN_LABELS` / `PLAN_ORDER` / `PLAN_FEATURES`
5. **`php artisan stripe:check`** で Price ID の設定漏れを確認する

既存プランの機能を**減らす**場合は、そのプランの利用者が翌デプロイで機能を失うことになる。
config を変える前に周知し、ダウングレード扱いになる利用者を `subscriptions` から数えておく。
