# develop 環境の構築手順（手作業分）

[設計書](superpowers/specs/2026-09-07-develop-production-split-design.md) の Phase 0 / Phase 1 / Phase 3（Cloudflare 側）にあたる、**ダッシュボード操作と外部サービス登録**をまとめた手順書。コード変更は別途リポジトリ側で行う。

**順序に依存関係がある。** 特に STEP 0 は他のすべてに先行する。飛ばすと本番が落ちる。

---

## 控える値（先に空欄の表を作っておくと楽）

| # | 値 | 取得する STEP | 使う場所 |
|---|---|---|---|
| 1 | Neon の接続文字列（直接エンドポイント） | 1 | Render `DB_URL` |
| 2 | R2 develop バケット名 / エンドポイント / アクセスキー / シークレット | 2 | Render `R2_*` |
| 3 | Stripe sandbox の Price ID ×3 | 3 | Render `STRIPE_PRICE_*` |
| 4 | Stripe sandbox の `sk_test_` / `pk_test_` | 3 | Render `STRIPE_SECRET` / `STRIPE_KEY` |
| 5 | develop 用 `APP_KEY` | 4 | Render `APP_KEY` |
| 6 | develop API の URL | 5 | 下の 8/9/10、Cloudflare `VITE_API_BASE_URL` |
| 7 | develop フロントの URL | 6 | Render `CORS_ALLOWED_ORIGINS` / `FRONTEND_URL` |
| 8 | Stripe sandbox の `whsec_` | 8 | Render `STRIPE_WEBHOOK_SECRET` |
| 9 | develop 用 Google OAuth のクライアント ID / シークレット | 9 | Render `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` |
| 10 | develop 用 OpenAI キー | 3.5 | Render `OPENAI_API_KEY` |

---

## STEP 0. 本番を守る（最優先・単独で完了させる）

`render.yaml` は Web サービスと PostgreSQL の両方に `plan: free` と書いてあるが、実際は両方とも有料プラン。Render は **Blueprint がダッシュボードの設定を上書きする**と明記しており、かつ**同期は差分ではなくファイル全体を適用**する。Auto Sync は既定 ON、既存 Blueprint には同期前の差分確認画面がない。

> "if any of those changes conflict with configuration defined in the Blueprint, they're overwritten the next time you sync your Blueprint."
> — [render.com/docs/infrastructure-as-code](https://render.com/docs/infrastructure-as-code)

つまり **`render.yaml` を変更した push が1回走るだけで、本番の Postgres が 256MB に降格し、数分停止し、「作成30日で失効→猶予14日→削除」の経路に乗る。**

作業ツリーには既に `render.yaml` の未コミット変更（`APP_LOCALE: ja` の追加）がある。**これを push する前に以下を終わらせること。**

1. Render ダッシュボード → 該当 Blueprint → **Settings → Auto Sync を No** にする。
2. ダッシュボードで `realize-beauty-api` と `realize-beauty-db` を選択 → **Generate Blueprint** → ダウンロード。
3. ダウンロードした YAML とリポジトリの `render.yaml` を見比べ、**`plan` 以外にずれている項目がないか**確認する。特に `region` / `diskSizeGB` / `numInstances` / `ipAllowList` / `connectionPool` / `storageAutoscalingEnabled` / `postgresMajorVersion`。
4. ずれがあれば教えてほしい。`plan` はこちらで**行ごと削除**する（既存リソースは現行プランを保持する、と Blueprint 仕様に明記がある。実 ID を書くより安全）。実 ID を書きたい場合は `realize-beauty-api` と `realize-beauty-db` のプラン ID を教えてほしい。

> **注意**: 新規に作る develop サービスの方は `plan: free` を**明示する**。新規リソースで `plan` を省略すると既定の `0.5c-512mb`（$7/月）になる。

STEP 0 の修正を push したあと、**Manual Sync を手動で実行**してデプロイ結果を確認する。問題なければ Auto Sync を戻すかどうかを判断する。

---

## STEP 0.5. GitHub のブランチ保護

`develop` ブランチはこちらで作成して push する。そのうえで、GitHub 側の設定はあなたの作業になる。

1. リポジトリ → Settings → Branches → **`main` にブランチ保護ルールを追加**する。
   - Require a pull request before merging
   - Require status checks to pass（`backend` と `frontend` のジョブを選ぶ）
2. 同様に `develop` にも同じルールを付ける（任意）。

直近15コミットが `main` に直接入っており、[ADR-010](decisions/ADR-010-git-workflow.md) と [CONTRIBUTING.md](../CONTRIBUTING.md) の「main へ直接コミットしない」が実際には守られていない。2環境に分けると、`main` への直接コミットは**本番への直接デプロイ**を意味するようになる。

---

## STEP 1. Neon（develop の DB）

1. [neon.tech](https://neon.tech) でアカウントを作る。
2. プロジェクトを **AWS Singapore (`ap-southeast-1`)** に作成。
   - Render に日本リージョンがないため、Tokyo に置くとアプリ⇔DB が約70ms 離れる。Laravel は1リクエストで多数のクエリを投げるので、Singapore に揃える方が体感が速い。
3. データベース名は `realize_beauty_develop` にする。
4. 接続文字列をコピーする。**プーラー経由（ホスト名に `-pooler` が入るもの）ではなく、直接エンドポイントを使う。** `config/database.php` の pgsql 接続が `search_path` を持ち、接続ごとにセッションへ設定するため。

   ```
   postgresql://<user>:<password>@ep-xxxx.ap-southeast-1.aws.neon.tech/realize_beauty_develop?sslmode=require
   ```

無料プランは 0.5GB ストレージ / 100 CU 時間・月で、**期限がない**。5分のアイドルでコンピュートが停止するが、次の接続で自動復帰する（数百ms）。この自動復帰が Neon を選んだ理由で、Supabase や Aiven は手動で解除するまで止まったままになり、週明けの初回デプロイが必ず失敗する。

---

## STEP 2. Cloudflare R2（develop の写真保管）

**本番バケットは共用しない。** R2 はバケット単位の課金がないので、分けても費用は変わらない。

1. R2 → **Create bucket** → `realize-beauty-photos-develop`。
2. 公開アクセスは**有効にしない**（本番と同じく署名付き URL で配る）。
3. **このバケットにだけスコープした API トークン**を発行する。本番バケットの読み書き権限を含めないこと。
4. アクセスキー ID / シークレット / エンドポイント（`https://<accountid>.r2.cloudflarestorage.com`）を控える。

---

## STEP 3. Stripe（develop のサブスク）

**必ず「named sandbox」を使う。** レガシーのテストモードは Dashboard 設定の一部を Live と共有するため、テスト側のポータル設定をいじると本番側が変わりうる。

1. Stripe ダッシュボード右上のアカウント切替 → **Sandboxes** → 新規 sandbox を作成（名前は `develop` など）。
2. その sandbox で **商品と価格を3つ**作る（Lite / Standard / Pro）。通貨 **JPY**、**継続（recurring）**。金額は本番と同じにする。`price_xxx` を3つ控える。
3. **カスタマーポータルの設定を保存する。** 設定 → 支払い → カスタマーポータル → 内容を確認して**保存**を押す。
   - これを忘れると「お支払い情報の変更」ボタンが動かない。Stripe が 400 を返し、アプリ側は現状 **英語の "Server Error"** を出す（この見え方はコード側で日本語化する）。
   - Live 側の設定は sandbox に引き継がれない。**別々に保存が必要。**
4. API キー（`sk_test_...` / `pk_test_...`）を控える。
5. Webhook エンドポイントは **STEP 8**（develop API の URL が確定してから）。

### STEP 3.5. OpenAI

develop 用に**別の API キー**を発行する（本番キーと使用量を混ぜないため）。AI 要約はリクエスト内で同期的に呼ばれ、クリックのたびに課金される。予算上限を低く設定しておくとよい。

---

## STEP 4. develop 用 `APP_KEY` を生成する

ローカルで実行し、出力された `base64:...` を控える。**本番の APP_KEY は絶対に流用しない。**

```sh
cd backend && php artisan key:generate --show
```

鍵を分けておくと、万一 develop に本番の DB ダンプを入れてしまっても、LINE のチャネルトークンや Google のリフレッシュトークン（暗号化列）を**復号できない**。これは制約ではなく安全装置。

---

## STEP 5. Render に develop サービスを作る

こちらが `render.yaml` に develop サービスを追記して push したあと、あなたの作業。

1. Blueprint ページで **Manual Sync** を実行する。`realize-beauty-api-dev` が作成される。
2. **初回デプロイは失敗する。** 環境変数が空でコンテナが起動できないため。想定内なのでそのまま進む。
3. サービスの **Environment** タブで、`sync: false` の変数をすべて入力する。

   > Render は Blueprint の**初回作成時にしか**入力を促さない。既存 Blueprint に足したサービスは何も聞かれず、全部が空のまま始まる。

   | 変数 | 値 |
   |---|---|
   | `APP_KEY` | STEP 4 の値 |
   | `APP_URL` | このサービスの URL（下の 4. で確定） |
   | `DB_URL` | STEP 1 の Neon 接続文字列 |
   | `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` / `R2_BUCKET` / `R2_ENDPOINT` / `R2_PUBLIC_URL` | STEP 2 の値 |
   | `STRIPE_SECRET` / `STRIPE_KEY` | STEP 3 の `sk_test_` / `pk_test_` |
   | `STRIPE_PRICE_LITE` / `STRIPE_PRICE_STANDARD` / `STRIPE_PRICE_PRO` | STEP 3 の Price ID |
   | `STRIPE_WEBHOOK_SECRET` | STEP 8 で入れる（今は空でよい） |
   | `OPENAI_API_KEY` | STEP 3.5 の値 |
   | `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | STEP 9 で入れる（今は空でよい） |
   | `CORS_ALLOWED_ORIGINS` / `FRONTEND_URL` | STEP 7 で入れる |

4. 払い出された URL（`https://realize-beauty-api-dev.onrender.com` の形）を控える。`APP_URL` にこの値を入れる。
5. **手動でデプロイし直す。** `entrypoint.sh` が起動時に `config:cache` を実行するため、**環境変数を変えただけでは反映されない。**
6. `https://<develop API>/up` が 200 を返せば OK。

> 無料プランのため、15分無通信でスリープし復帰に約1分かかる。デモの直前に一度アクセスして温めておくこと。Shell も使えないので、運用コマンドはローカルから実行する（STEP 11）。

---

## STEP 6. Cloudflare に develop 用 Worker を作る

初回だけローカルからの手動デプロイが必要（Workers Builds は接続先の Worker が既に存在していることを前提にするため）。

1. こちらが `wrangler.jsonc` に `env.develop` を追加し、`wrangler` を devDependency に入れる。その後ローカルで:

   ```sh
   cd frontend
   VITE_API_BASE_URL=https://<develop API>/api/v1 VITE_ENV_LABEL=DEVELOP npm run build
   npx wrangler deploy --env develop
   ```

2. `https://realize-beauty-develop.realizeworks-0701.workers.dev` が払い出される。この URL は**デプロイをまたいで安定**する。
3. Cloudflare ダッシュボード → その Worker → **Settings → Build** でリポジトリを接続し、以下を設定する。

   | 項目 | 値 |
   |---|---|
   | Root directory | `frontend` |
   | Build command | `npm run build` |
   | Deploy command | `npx wrangler deploy --env develop` |
   | Branch control（production branch） | `develop` |
   | ビルド変数 `VITE_API_BASE_URL` | `https://<develop API>/api/v1` |
   | ビルド変数 `VITE_ENV_LABEL` | `DEVELOP` |

4. **「非 production ブランチのビルド」は有効にしない。** 既定の `wrangler versions upload` はバージョンごとに別ホスト名を払い出すため、完全一致の CORS 許可リストが毎回弾く。

### 既存の `realize-beauty` Worker（本番）も Deploy command だけ直す

| 項目 | 変更前 | 変更後 |
|---|---|---|
| Deploy command | `npx wrangler deploy` | `npx wrangler deploy --env=""` |
| Branch control（production branch） | `main` | `main`（変更なし） |

`wrangler.jsonc` に `env`（develop）を定義したことで、引数なしの `npx wrangler deploy` は次の警告を出すようになった。デプロイ自体は成功する（非致命的）が、毎回ログに残る。

> Multiple environments are defined in the Wrangler configuration file, but no target environment was specified for the deploy command.

`--env=""`（空文字）は「トップレベルの設定を使う」という明示になり、警告が出ず、Worker 名も `realize-beauty` のままに解決される（終了コード 0 で確認済み）。

> **`--env production` は使えない。** `wrangler.jsonc` に `env.production` の節が無いため、wrangler は設定の読み込み段階で止まり、**終了コード 1 で失敗する**（デプロイは走らない）。
>
> ```
> ✘ [ERROR] Processing wrangler.jsonc configuration:
>
>     - No environment found in configuration with name "production".
>       Before using `--env=production` there should be an equivalent environment section in the configuration.
>       The available configured environment names are: ["develop"]
> ```
>
> 静かに間違った先へ出るのではなく、その場で落ちる。仮に `env.production` の節を足せば通るようになるが、そのときは wrangler が env 名から `realize-beauty-production` という**別の Worker 名を導出する**。節を足さず、`--env=""` を使うこと。

ローカルからの手動デプロイも同じで、本番は `npm run deploy`（= `wrangler deploy --env=""`）、develop は `npm run deploy:develop`（= `wrangler deploy --env develop`）を使う。

---

## STEP 7. develop API に CORS とフロント URL を入れる

Render の `realize-beauty-api-dev` の Environment に入力し、**再デプロイする**。

| 変数 | 値 |
|---|---|
| `CORS_ALLOWED_ORIGINS` | `https://realize-beauty-develop.realizeworks-0701.workers.dev` |
| `FRONTEND_URL` | 同上 |

CORS は未設定なら全拒否（フェイルクローズ）なので、ここを飛ばすとフロントから API を一切呼べない。

> **本番側の `CORS_ALLOWED_ORIGINS` に develop の URL を足さないこと。** 逆も同じ。`FRONTEND_URL` を本番で間違えると、実顧客の予約リンクや Stripe の戻り先がデモ環境に飛ぶ。

---

## STEP 8. Stripe の Webhook を登録する（develop API の URL 確定後）

1. STEP 3 で作った **sandbox の中で** 開発者 → Webhook → エンドポイントを追加。
2. URL: `https://<develop API>/api/webhooks/stripe`
   - `/api/v1/` **配下ではない**。
3. 送信するイベント: `checkout.session.completed` / `customer.subscription.created` / `customer.subscription.updated` / `customer.subscription.deleted` / `invoice.payment_failed` / `invoice.paid`
4. 払い出された `whsec_...` を Render の `STRIPE_WEBHOOK_SECRET` に入れ、**再デプロイする**。

> **本番の `whsec_` を develop に貼らないこと。** 貼ると Live のイベントが develop の DB に適用される。両方の DB はサロン ID が 1 から始まるため、宛先は必ず存在してしまう。逆（テストの `whsec_` を本番に貼る）はもっと静かで、本番の Webhook が延々 400 を返し続け、契約状態が同期されなくなる。

---

## STEP 9. Google カレンダー連携（develop 用）

1. Google Cloud Console で **develop 用の OAuth クライアント**を新規作成する（種類: ウェブアプリケーション）。本番のクライアントは使わない。
2. 承認済みリダイレクト URI に `https://<develop API>/api/v1/google-calendar/callback` を登録する。
3. クライアント ID / シークレットを Render の `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` に入れ、**再デプロイする**。

> **既知の制約**: OAuth 同意画面が「テスト」ステータスのあいだ、カレンダーのような機密スコープではリフレッシュトークンが**7日で失効**する。develop の Google 連携は週次で切れる。デモ前に繋ぎ直す運用で回す。
>
> また、Google のプッシュ通知はドメイン所有権の確認を要求するため、`*.onrender.com` では登録できない。これは本番も同じ状況で、develop で新たに壊れるものではない。

---

## STEP 10. LINE 連携（develop 用）

LINE のチャネルトークンは環境変数ではなく、**サロンごとの暗号化された DB 列**に入る（画面から登録する）。したがって「develop 専用の資格情報」とは、**別の LINE チャネルを作り、デモ環境の設定画面からトークンを貼る**ことを意味する。

1. LINE Developers で **develop 用の Messaging API チャネル**を作る。
2. Webhook URL に develop の値を設定する（アプリの LINE 設定画面が表示する URL をそのまま使う）。
3. STEP 11 でシードしたあと、develop にログインして設定画面からチャネルシークレットとアクセストークンを登録する。

> 1つの LINE チャネルには Webhook URL が1つしか設定できない。本番のチャネルを使い回すと**本番の Webhook が develop に向いてしまう**。必ず別チャネルを作ること。
>
> デモ環境のログイン情報は見込み客に渡すことになるため、**誰でも LINE 設定を上書き・切断できる**。そのつもりで扱う。

---

## STEP 11. develop の DB にデモデータを入れる

無料プランには Shell がないため、**ローカルから develop の DB に向けて**実行する。`demo:reset` コマンドはこちらで実装する。

```sh
cd backend
DB_URL='postgresql://…@ep-xxxx.ap-southeast-1.aws.neon.tech/realize_beauty_develop?sslmode=require' \
  php artisan demo:reset
```

コマンドは実行前に**接続先のホストとデータベース名を表示し、データベース名の入力を求める**。`APP_ENV` ではなく接続先の実体を見ているので、`DB_URL` を本番に向け違えた場合もここで止まる。

- デモの前に毎回実行する運用にする。シーダーは実行時の日付で予約を作るため、これで「本日の予約」が現在に戻る。
- 見込み客が Stripe の契約を完了すると、次の人は「すでに契約中です」で弾かれる。**人が変わるたびに実行する。**
- アップグレードの流れを見せたい場合は `--plan=lite` を付ける。

---

## STEP 12. 動作確認

```sh
DEV_API=https://<develop API>
DEV_APP=https://realize-beauty-develop.realizeworks-0701.workers.dev

# 1. API が起きている
curl -s -o /dev/null -w '%{http_code}\n' "$DEV_API/up"

# 2. CORS に develop フロントが載っている（access-control-allow-origin が返ればOK）
curl -sI -H "Origin: $DEV_APP" "$DEV_API/api/v1/auth/login" | grep -i access-control-allow-origin
```

Stripe の設定はローカルから develop の env を渡して検査できる。

```sh
cd backend
APP_ENV=staging \
STRIPE_SECRET=sk_test_… STRIPE_KEY=pk_test_… STRIPE_WEBHOOK_SECRET=whsec_… \
STRIPE_PRICE_LITE=price_… STRIPE_PRICE_STANDARD=price_… STRIPE_PRICE_PRO=price_… \
  php artisan stripe:check
```

> `stripe:check` は環境変数の静的検査であり、**Stripe と通信しない**。「Price ID が sandbox に実在するか」「テスト側のカスタマーポータルが保存済みか」は検出できない。この2つは実際に画面を触って確認するしかない。

### 画面での確認

- [ ] `$DEV_APP` を開き、画面に `DEVELOP` バッジが出る
- [ ] `admin@example.com` / `password` でログインできる
- [ ] ダッシュボードに本日の予約とグラフが出ている（空でない）
- [ ] 顧客 → カルテ作成 → 写真アップロードが通り、写真が表示される
- [ ] R2 の **develop バケット**にオブジェクトが増えている（本番バケットは増えていない）
- [ ] 設定 → プラン → アップグレードで Stripe の Checkout に飛び、テストカード `4242 4242 4242 4242`（有効期限は未来の任意、CVC は任意の3桁）で完了できる
- [ ] 戻ってきた画面で契約状態が反映されている
- [ ] 「お支払い情報の変更」でカスタマーポータルが開く（開かなければ STEP 3-3 の保存漏れ）
- [ ] 公開予約ページから予約でき、develop の LINE チャネルに通知が届く

### 見込み客に見せる前の確認

- [ ] `demo:reset` を実行した直後である
- [ ] デモ直前に一度アクセスして、コンテナを温めてある
- [ ] Stripe の決済画面がテスト環境であることを、先に口頭で伝えてある（Stripe 側にテスト表示が出るため、隠すことはできない）

---

## 参考

- [設計書](superpowers/specs/2026-09-07-develop-production-split-design.md)
- [本番デプロイ手順](deployment.md)
- [本番ハードニング手順](runbook-hardening.md)
- [Stripe 設定手順](stripe.md)
