# develop 環境と本番環境の分離 設計書

- 日付: 2026-09-07
- ステータス: 設計中
- 関連: ADR-031(新規作成予定) / [ADR-022](../../decisions/ADR-022-deployment.md) / [ADR-029](../../decisions/ADR-029-subscription-billing.md) / [ADR-010](../../decisions/ADR-010-git-workflow.md) / [docs/deployment.md](../../deployment.md) / [docs/stripe.md](../../stripe.md)

## 背景と目的

デプロイ先が本番1つしかない。見込み客に画面を見せるにも、機能を検証するにも、実顧客のデータが入った本番を触るしかない状態にある。

分けたい理由は2つで、性質が違う。

- **develop**: 見込み客に安全に見てもらう。デモデータが入っていて、壊れても影響がなく、サブスクリプションを**Stripe テストモードで無料で試せる**。
- **本番**: 実際のサロンに試運転してもらう。実データが入り、Stripe は Live のまま。

develop は「未リリースコードの先行検証」と「顧客向けデモ」の二役を兼ねる。この2つは本来要求が逆（前者は壊れてよい、後者は壊れてはいけない）だが、MVP 段階では1つの環境に載せる。デモ側の要求（データのリセット、外部連携の分離、Stripe テストモード）が作業量の大半を占める。

## 決定事項（ユーザー承認済み）

1. **DB は環境ごとに完全分離**。develop は Neon の無料プラン（期限なし・自動ウェイク・Singapore）を `DB_URL` で接続する。追加課金なし。
2. **develop の Web サービスは Render 無料プラン**。15分無通信でスリープし復帰に約1分かかること、Shell が使えないことを許容する。運用コマンドはローカルから develop DB に向けて実行する。
3. **デモデータを投入しておく**。見込み客がログイン直後に画面を見て触れる状態にする。
4. **外部連携は develop 専用の資格情報を用意する**（LINE チャネル / Google OAuth クライアント / OpenAI キー）。本番の資格情報は共有しない。
5. **R2 は共用せず、develop 専用バケットを新規作成する**（当初「共用」で合意したが、下記の検証結果により変更）。

## 提案（未承認・レビューで確認したい）

- **ブランチは `feature/* → develop → main`**。`develop` が develop 環境、`main` が本番へデプロイされる。README.md と docs/standards/git.md が既にこの形を記述しているため実態を合わせる案だが、[ADR-010](../../decisions/ADR-010-git-workflow.md) は「GitHub Flow、develop なし」を Accepted としており、変更には新 ADR が要る。「main のみを使い、develop 環境へは main から自動、本番へは手動昇格」という別案もある。

## 検証で判明した、設計を変えた事実

### 1. `render.yaml` の `plan: free` は本番を落とす指示（最優先で是正）

Web サービスと PostgreSQL は既に有料プランへ移行済みだが、[render.yaml](../../../render.yaml) は両方とも `plan: free` のまま。Render のドキュメントは、ダッシュボードでの変更が Blueprint と衝突した場合の挙動を明示している。

> "You *can* still make changes to a Blueprint-managed resource in the Render Dashboard. However, if any of those changes conflict with configuration defined in the Blueprint, **they're overwritten the next time you sync your Blueprint**."
> — [render.com/docs/infrastructure-as-code](https://render.com/docs/infrastructure-as-code)

かつ **同期は差分ではなくファイル全体を適用**し、Auto Sync は既定で ON、既存 Blueprint には同期前の差分確認画面がない。つまり develop サービスを追記して push した同じ同期で、本番の Web サービスと DB に `plan: free` が適用される。Postgres が free に落ちれば 256MB・数分のダウンタイム・**作成30日で失効し猶予14日後に削除**の経路に乗る。

救いは省略が安全であること。

> **If you omit this field:** … Render uses `0.1c-256mb` for a new database. **Render retains the current compute plan for an existing database.**
> — [render.com/docs/blueprint-spec](https://render.com/docs/blueprint-spec)

**この是正は develop の作業と切り離し、先に単独で完了させる。**

さらに、作業ツリーには `render.yaml` への未コミット変更（`APP_LOCALE: ja` の追加）が既にある。Blueprint 同期は**Blueprint ファイルを変更した push で走る**ため、この1行を push するだけで `plan: free` が本番へ適用されうる。**この未コミット変更を push する前に Phase 0 を終わらせる。**

### 2. R2 を共用する理由がない

R2 はバケット単位の課金がなく、料金は保存量とオペレーション数（無料枠 10GB/月）。バケットを分けても費用は変わらない。

共用にすると `R2_PATH_PREFIX`（S3 ディスクの `root`）が必要になる。この仕組み自体は検証済みで、`put` / `get` / `delete` / `exists` / 一覧 / `temporaryUrl` の全経路にプレフィックスが効き、DB に保存されるパスはプレフィックスを含まないため本番との後方互換もある。それでも採用しない理由:

- **未設定時に fail-open する**。`R2_PATH_PREFIX` が空だと develop の書き込みと削除がそのまま本番のキー空間に載る。エラーにならない。
- **R2 の API トークンはバケット単位スコープ**。プレフィックスは権限の境界にならない。
- [PhotoRepository.php:34](../../../backend/app/Repositories/PhotoRepository.php#L34) の `Storage::delete()` は実削除であり、事故は読み取りではなく破壊になる。
- 見知らぬ人物がアップロードする写真を、本番の写真ストレージに同一資格情報で受け入れることになる。

**同じ費用でコード変更ゼロ・リスクゼロのバケット分離を採る。**

### 3. `QUEUE_CONNECTION=sync` はデモを壊す

LINE 送信ジョブは `DB::transaction` の内側で dispatch されている（[PublicBookingService.php:135](../../../backend/app/Services/PublicBookingService.php#L135), [:140](../../../backend/app/Services/PublicBookingService.php#L140)）。`sync` ドライバは例外を再スローするため、LINE API がタイムアウトや 4xx を返すと**予約行はコミットされたまま HTTP 500 が返る**。見込み客は「予約が失敗した」と判断して再送信する。

`deferred` は [config/queue.php:76](../../../backend/config/queue.php#L76) に既に定義済みで、レスポンス送出後に同一プロセスで実行される。コード変更なしで採用できる。

なお、リマインダーと Google カレンダーのチャネル更新はキューではなく**スケジューラ駆動**であり（[routes/console.php](../../../backend/routes/console.php)）、cron サービスがどの環境にも存在しないため `QUEUE_CONNECTION` の値によらず動かない。これは既存課題であり本設計のスコープ外（[runbook §6](../../runbook-hardening.md)）。

### 4. `APP_ENV=staging` は Laravel の確認プロンプトも外す

Stripe のモード強制は `APP_ENV === 'production'` の二値判定なので（[StripeClient.php:154](../../../backend/app/Services/Billing/StripeClient.php#L154)）、develop をテストモードにするには `production` 以外にするしかない。

アプリ側で挙動が変わるのは既知の4箇所のみだが、**フレームワーク側の `ConfirmableTrait` も production 限定**であるため、`staging` では `migrate:fresh` / `db:wipe` / `db:seed` / `key:generate` が**確認なしで実行される**。DatabaseSeeder のガードも `APP_ENV` 判定であり、ローカルから `DB_URL` を本番に向けた実行は防げない（[DatabaseSeeder.php:27](../../../backend/database/seeders/DatabaseSeeder.php#L27) のコメントが自認している）。

Shell のない無料プランでは運用コマンドをローカルから実行することになるため、**最も安全網のない操作が日常操作になる**。後述の `demo:reset` に接続先の実体を検査するガードを持たせて補う。

### 5. Stripe テストモードには3つの独立した設定が要る

- **named sandbox を使う**。レガシーのテストモードは Dashboard 設定の一部を Live と共有する（`docs.stripe.com/test-mode`）。テスト側のポータル設定をいじると Live 側が変わりうる。
- **テストモードのカスタマーポータル設定を保存する**。未保存だと `/v1/billing_portal/sessions` が 400 を返し、コードでは `StripeApiException` → **英語の "Server Error"** としてお客さんの画面に出る。
- **develop 用の Webhook エンドポイントと署名シークレット**を別途登録する。Price ID もテストモード側の別の値で、接頭辞では判別できない。

`php artisan stripe:check` は env の静的リンタであり Stripe と通信しないため、上記3つのいずれも検出できない。

### 6. デモは「環境」より先に「リセット」が要る

シーダーは予約日時を投入時点の `Carbon::today()` で固定するため、**数日で「本日の予約」が空**になりダッシュボードが死んで見える。再実行しても各レコードは `firstOrCreate` なので古い行は消えず積み上がるだけ。

さらに Stripe デモは**1人目でしか成立しない**。シードされたサロンは Pro/Active かつ `stripe_subscription_id` が null なので初回の Checkout は通るが、完了後は `stripe_subscription_id` が入り、以降は「すでに契約中です」で弾かれる（[SubscriptionService.php:48](../../../backend/app/Services/Billing/SubscriptionService.php#L48)）。

デモのログインパスワードも文書と食い違う。[DatabaseSeeder.php:55](../../../backend/database/seeders/DatabaseSeeder.php#L55) は `Hash::make('email')`、[docs/deployment.md](../../deployment.md) は `password`。ログイン制限は**メールアドレス単位**（5回/分・20回/5分）なので、2人が同時に間違えると全員が締め出される。

---

## 環境定義

| | production | develop |
|---|---|---|
| ブランチ | `main` | `develop` |
| Render Web | `realize-beauty-api`（有料・現状維持） | `realize-beauty-api-dev`（free） |
| DB | Render Postgres（有料・現状維持） | Neon 無料プラン（Singapore）を `DB_URL` で |
| `APP_ENV` | `production` | `staging` |
| `APP_DEBUG` | `false` | `false`（明示する） |
| `APP_KEY` | 既存 | **別の値**（暗号化列を相互に読めなくするため） |
| Stripe | Live | Test（named sandbox） |
| `QUEUE_CONNECTION` | 現状維持（別課題） | `deferred` |
| R2 | 既存バケット | **専用バケット + 専用トークン** |
| LINE | 本番チャネル | **専用チャネル** |
| Google | 本番 OAuth クライアント | **専用 OAuth クライアント** |
| OpenAI | 本番キー | **専用キー** |
| フロント | Worker `realize-beauty` | Worker `realize-beauty-develop` |

---

## Phase 0: render.yaml のプランドリフト是正（先行・独立）

develop の作業に着手する**前に**、単独で完了させる。

1. Render ダッシュボード → Blueprint → Settings → **Auto Sync を No** にする。編集中の誤 push で同期が走らないようにする。
2. ダッシュボードで `realize-beauty-api` と `realize-beauty-db` を選択 → **Generate Blueprint** で現在の実設定を書き出し、リポジトリの `render.yaml` と差分を取る。`plan` 以外にも `region` / `diskSizeGB` / `numInstances` / `ipAllowList` / `connectionPool` などがずれている可能性がある。
3. `plan:` を実プラン ID に書き換える。**実 ID を確定できない場合は行ごと削除する**（既存リソースは現行プランを保持する、と仕様に明記がある）。`diskSizeGB` は書かない（減らす方向は不可逆かつ拒否される）。
4. `render blueprints validate render.yaml` を通す。これはスキーマ検査のみで、`plan: free` を通してしまう点に注意。
5. push → **Manual Sync** を手動で実行し、デプロイ結果を確認する。
6. Auto Sync を戻すかどうかは、ファイルが実態と一致していることを確認してから判断する。

**成果物**: `render.yaml` の `plan` が実態と一致し、以後の Blueprint 同期が本番を降格させない状態。

---

## Phase 1: インフラの用意（コード外の作業）

いずれも `APP_URL` が確定してからでないと登録できないものがあるため、順序を守る。

1. **Neon**: プロジェクトを AWS Singapore (`aws-ap-southeast-1`) に作成。接続文字列（**プーラー経由ではない直接エンドポイント**）を控える。Laravel が `search_path` と `time zone` をセッションで設定するため、直接エンドポイントを使う。
2. **R2**: `realize-beauty-photos-develop` バケットを作成し、そのバケットにスコープした API トークンを発行する。公開アクセスは本番同様に無効のまま。
3. **Cloudflare Worker**: `realize-beauty-develop` を作成する（初回はローカルから `npx wrangler deploy --env develop` で作る必要がある）。払い出される `https://realize-beauty-develop.<subdomain>.workers.dev` を控える。この URL はバージョン間で安定するため、完全一致の CORS 許可リストに載せられる。
4. **Render**: develop Web サービスを作成し `APP_URL` を確定させる。
5. `APP_URL` 確定後に、**サードパーティのコンソール側**へ登録する（Blueprint 同期では絶対に更新されない）:
   - Google Cloud: `{develop APP_URL}/api/v1/google-calendar/callback` を承認済みリダイレクト URI に追加
   - Stripe（sandbox）: Webhook エンドポイント `{develop APP_URL}/api/webhooks/stripe` を登録し、`whsec_` を控える
   - LINE Developers: develop 用チャネルの Webhook URL を設定
6. **Stripe sandbox**: 3プランの Price を JPY で作成し ID を控える。**カスタマーポータル設定を保存する**（未保存だとポータルが 500 になる）。

### render.yaml に追加する develop サービス

```yaml
  - type: web
    name: realize-beauty-api-dev
    runtime: docker
    rootDir: backend
    dockerfilePath: ./Dockerfile
    plan: free
    branch: develop
    healthCheckPath: /up
    envVars:
      - key: APP_ENV
        value: staging          # production 以外にすることが Stripe テストモードの前提
      - key: APP_DEBUG
        value: false            # staging でも既定で false だが明示する
      - key: QUEUE_CONNECTION
        value: deferred         # sync だと LINE 障害が予約 API の 500 になる
      - key: DB_CONNECTION
        value: pgsql
      - key: DB_URL             # Neon の接続文字列
        sync: false
      - key: DB_SSLMODE
        value: require          # URL のクエリと二重にならないよう明示する
      # 以下は本番と同じキーだが値はすべて別（APP_KEY / R2 / Stripe / Google / OpenAI）
```

本番サービスが持つ環境変数のうち、`fromDatabase` 参照（Neon を使うため不要）を除く**すべて**を develop にも用意する。`APP_NAME` / `APP_LOCALE` / `LOG_CHANNEL` / `LOG_LEVEL` / `SALON_TIMEZONE` / `PHP_CLI_SERVER_WORKERS` / `SANCTUM_EXPIRATION` / `PHOTO_URL_TTL_MINUTES` / `FILESYSTEM_DISK` はリテラル値として同じ、`APP_KEY` / `APP_URL` / `CORS_ALLOWED_ORIGINS` / `FRONTEND_URL` / `R2_*` / `OPENAI_API_KEY` / `GOOGLE_*` / `STRIPE_*` は `sync: false` かつ本番とは別の値。

既存の本番サービスには `branch: main` を明示する。Blueprint 仕様上 `branch` 省略はリポジトリの既定ブランチを指すため、ブランチ束縛された兄弟サービスが増える状況では暗黙のままにしない。

**注意**: 既に作成済みの Blueprint に新サービスを足しても、`sync: false` の値は**入力を促されない**。Render は初回作成時にしか尋ねない。develop サービスの全シークレットは作成後にダッシュボードで手入力する。未入力のまま起動すると `entrypoint.sh` の `set -e` + `migrate --force` でコンテナが落ち、劣化ではなく起動不能になる。

---

## Phase 2: バックエンドの変更

いずれも小さく、デモとして見せられるかに直結する。

### 2-1. Stripe 例外をユーザーに読める形で返す

`StripeConfigException` と `StripeApiException` は素の `RuntimeException` で `render()` を持たないため、`APP_DEBUG=false` では `{"message":"Server Error"}` になり、SPA のトーストがその英語をそのまま表示する（[apiError.ts:17](../../../frontend/src/utils/apiError.ts#L17)）。初回デプロイで最も起きやすい誤設定3種（テストポータル未保存 / Price ID のモード違い / キーとモードの不一致）がすべてここに落ちる。

[FeatureRequiredException](../../../backend/app/Exceptions/FeatureRequiredException.php) と同じ house pattern に従い、両クラスに `render(Request): JsonResponse` を実装して日本語メッセージと適切なステータス（設定不備は 503、Stripe API 側の失敗は 502）を返す。

### 2-2. Webhook に `livemode` 検査を追加

`livemode` はコード中に1箇所も出てこない（grep 0件）。現在モードの分離はエンドポイントごとの `whsec_` のみに依存している。本番の `whsec_` を develop に貼り間違えると Live イベントが develop DB に適用される。両 DB のサロン ID は 1 から始まるため、`metadata.salon_id` で解決される先は必ず存在してしまう。

`StripeWebhookService` の dispatch 前に、イベントの `livemode` と `str_starts_with(config('billing.stripe.secret'), 'sk_live_')` の一致を検査し、不一致なら `skipped` として記録し 200 を返す。ペイロードに元から入っている値なので追加コストはない。

### 2-3. `demo:reset` コマンド

```
php artisan demo:reset [--plan=pro|lite] [--force --expect-database=<name>]
```

- `app()->isProduction()` なら即座に拒否する。
- 接続先の**ホストとデータベース名を表示**し、データベース名の入力を求めて確認する。`--force` を使う場合は `--expect-database` が実際の接続先と一致することを必須にする。`APP_ENV` ではなく**接続先の実体**を見るのが要点で、ローカルから `DB_URL` を向け違えた場合を捕まえられる唯一の方法。
- 実処理は `migrate:fresh --force` + `db:seed --force`。シーダーは実行時の `today()` で予約を作るため、これで日付が現在に戻り、古い行も残らない。
- `--plan=lite` を指定すると、シード後にサブスクリプションを Lite に落とす。アップグレードの導線を見せたい場合に使う。既定は `pro`（全機能を見せられる状態）。

Shell のない無料プランのため、実行はローカルから develop の `DB_URL` に向けて行う。デモの前に叩く運用とし、cron 化は行わない（Render の cron サービスは有料で、本設計の「追加課金なし」に反する）。

### 2-4. シーダーのパスワード修正

`Hash::make('email')` を `Hash::make('password')` に戻し、[docs/deployment.md](../../deployment.md) と [ADR-028](../../decisions/ADR-028-production-hardening.md) の記述と一致させる。

### 2-5. entrypoint の migrate にリトライを入れる

Neon のコンピュートは5分のアイドルで停止し、次の接続で自動復帰する（数百ms）。ただし `entrypoint.sh` は `migrate --force` を `set -e` の下で一度だけ実行するため、初回接続がタイムアウトするとコンテナが起動しない。数回のバックオフ付きリトライを入れる。本番の DB 再起動時にも効く。

---

## Phase 3: フロントエンドの変更

### 3-1. wrangler の環境定義

[frontend/wrangler.jsonc](../../../frontend/wrangler.jsonc) に1行だけ足す。

```jsonc
  "env": { "develop": {} }
```

`assets` と `compatibility_date` は**継承されるキー**なので env ブロック内に再掲しない（再掲は差異の発生源になる）。Worker 名も `env` ブロック内に書かない — wrangler が `realize-beauty-develop` を導出する。

`wrangler` を devDependency に追加し `deploy` / `deploy:develop` スクリプトを置く。Workers Builds は package.json の wrangler バージョンを使うため、未指定だとビルドごとにその日の `npx wrangler` が引かれる。

Cloudflare 側の設定（リポジトリには入らない）:

| | production Worker | develop Worker |
|---|---|---|
| Branch control | `main` | `develop` |
| Deploy command | `npx wrangler deploy` | `npx wrangler deploy --env develop` |
| `VITE_API_BASE_URL` | 本番 API | develop API |
| `VITE_ENV_LABEL` | （未設定） | `DEVELOP` |

**非 production ブランチのビルドは有効にしない。** 既定の `wrangler versions upload` はバージョンごとに別ホスト名を払い出し、完全一致の CORS 許可リストが毎回弾く。

### 3-2. 環境バッジ

`VITE_ENV_LABEL` が設定されているときだけ、画面上部に固定のバッジを出す。develop を本番と誤認させないための最小限の表示で、本番ビルドでは未設定なので何も描画されない。

---

## Phase 4: ブランチと CI

- `develop` ブランチを作成して push する（現在 git 上に存在しない）。
- 日常の PR 先を `develop` にし、検証後に `develop → main` で本番へ出す。
- [.github/workflows/ci.yml](../../../.github/workflows/ci.yml) の `push: branches:` に `develop` を追加する（`pull_request:` は既に全ブランチを対象にしている）。
- `main` にブランチ保護を入れる。直近15コミットが直接 main に入っており、[ADR-010](../../decisions/ADR-010-git-workflow.md) と [CONTRIBUTING.md](../../../CONTRIBUTING.md) の「main へ直接コミットしない」が守られていない。

ドキュメントが矛盾している点も併せて解消する。ADR-010 と CONTRIBUTING.md は「GitHub Flow、develop なし」、README.md と docs/standards/git.md は「main → develop → feature/*」と書いている。ADR を正典とする AGENTS.md の規定に従い、**新 ADR で ADR-010 を更新**し、standards 側を実態に合わせる。

---

## Phase 5: ドキュメント

- **ADR-031（新規）**: 2環境構成の決定。develop の位置づけ（デモ兼先行検証）、`APP_ENV=staging` を選ぶ理由と副作用、R2 を共用しない理由、Neon を選ぶ理由、ブランチ運用の変更。
- **ADR-022 更新**: 2環境構成を反映。併せて Cloudflare Pages → Workers Static Assets の陳腐化を修正する（`frontend/public/_redirects` は既に存在せず、SPA フォールバックは `wrangler.jsonc` の `not_found_handling` が担っている）。
- **ADR-010 更新**: ブランチ運用。
- **docs/deployment.md**: 2環境の手順に再構成。デモ用パスワードの記述を修正。
- **docs/stripe.md**: 現在の DEV / 本番の2列は「DEV = localhost + Stripe CLI」を前提にしており、ホストされた develop 環境で崩れる。**local / develop / production の3列**に改める。
- **docs/runbook-hardening.md**: develop の URL とプロビジョニング手順を追記。

---

## スコープ外（既存課題として記録するのみ）

- **本番のキューワーカーと cron**。`type: worker`（`queue:work`）と `type: cron`（`schedule:run`）はどの環境にも存在せず、LINE ジョブは `jobs` テーブルに溜まり続け、登録済みの3スケジュールコマンドは一度も動いていない（[runbook §6](../../runbook-hardening.md)）。本設計は develop に `deferred` を置いて回避するのみで、本番側は変更しない。
- **`DB_SSLMODE=require` の本番有効化**（[runbook §1](../../runbook-hardening.md)）。
- **`envVarGroups` による共通変数の集約**。サービス2つ・共通リテラル8個程度では割に合わない。
- **Stripe のテストクロック**を使った請求サイクル前倒しデモ。

---

## リスクと対策

| リスク | 対策 |
|---|---|
| Blueprint 同期が本番を free に降格させる | Phase 0 を先行・単独で完了。Auto Sync を一時停止し Manual Sync で確認 |
| ローカルからの `db:seed` / `demo:reset` を本番 DB に向けてしまう | `demo:reset` が接続先のホストとデータベース名を検査。`APP_ENV` ではなく実接続先を見る |
| 本番の `whsec_` を develop に貼り間違える | `livemode` 検査（Phase 2-2） |
| develop の初回アクセスが約1分待ちになる | 仕様として受け入れる。デモ直前に一度アクセスして温めておく運用で回避 |
| デモデータが数日で陳腐化する | デモ前に `demo:reset` を実行する運用 |
| Stripe デモが2人目以降で弾かれる | 同上。`demo:reset` が `stripe_subscription_id` ごとリセットする |
| develop の env 未入力でコンテナが起動しない | Phase 1 の順序を守り、全 `sync: false` を入力してから初回デプロイ |
| `config:cache` により env 変更が反映されない | ダッシュボードでの env 変更後は必ず再デプロイする。runbook に明記済み |

## 未確定事項

- 本番の `autoDeployTrigger` を `commit`（現行）のままにするか `checksPass` にするか。CI が通ってからデプロイする方が安全だが、起動時マイグレーションと組み合わせた挙動を確認したうえで別途判断する。本設計では変更しない。
