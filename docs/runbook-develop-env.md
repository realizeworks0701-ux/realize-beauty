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

> **✅ コード側は対応済み（`90a5dbb` / `main` にマージ済み）。** `render.yaml` から本番リソースの
> `plan` 行を削除し、`APP_LOCALE: ja` も同じコミットで入っている。以下は**なぜその対応が必要だったか**の
> 記録として残す。ダッシュボード側の 1.〜4.（Auto Sync の停止と Generate Blueprint による突き合わせ）は
> **今後 `render.yaml` を触るときに毎回効く手順**なので、読み飛ばさないこと。

`render.yaml` は Web サービスと PostgreSQL の両方に `plan: free` と書いてあったが、実際は両方とも有料プラン。Render は **Blueprint がダッシュボードの設定を上書きする**と明記しており、かつ**同期は差分ではなくファイル全体を適用**する。Auto Sync は既定 ON、既存 Blueprint には同期前の差分確認画面がない。

> "if any of those changes conflict with configuration defined in the Blueprint, they're overwritten the next time you sync your Blueprint."
> — [render.com/docs/infrastructure-as-code](https://render.com/docs/infrastructure-as-code)

つまり **`render.yaml` を変更した push が1回走るだけで、本番の Postgres が 256MB に降格し、数分停止し、「作成30日で失効→猶予14日→削除」の経路に乗る。**

当時は作業ツリーに `render.yaml` の未コミット変更（`APP_LOCALE: ja` の追加）があり、**それを push する前に以下を終わらせる**必要があった。`render.yaml` を変更するときは毎回同じ確認をする。

**Auto Sync を止める:**

1. `https://dashboard.render.com/blueprints` を開く（左ペインの **Blueprints**。**Projects** のすぐ下、**Environment groups** の上にある）。
   - **ここが空なら、サービスは Blueprint 管理下にない。** その場合 `render.yaml` を push しても何も起きず、develop サービスも作られない。先へ進む前にこの前提を確認すること。
2. Blueprint 名をクリックする。**作成時に入力した名前であり、リポジトリ名とは限らない。** 見覚えがなければリポジトリで照合する。
3. その Blueprint の **Settings** を開く。
4. **Auto Sync** セクション（説明文は "Automatically sync changes to your Blueprint file? Select "No" to handle syncs manually."）。
5. 鉛筆の **Edit** を押すと編集可能になる。トグルではなく**ドロップダウン**（Yes / No）。**No** にして **Save changes**。

**実設定との差分を取る:**

6. `https://dashboard.render.com`（左ペインの **Projects**）を開き、**Ungrouped Services** のテーブルまでスクロールする。`render.yaml` に `projects:` を書いていないため、2つのリソースはここに並ぶ。
7. 各行の**左端のチェックボックス**で `realize-beauty-api` と `realize-beauty-db` を選ぶ。
8. 選択すると**画面下部に黒いバー**が出る: `N services selected: [Move] [Generate Blueprint] [Suspend]`。**Generate Blueprint** を押してダウンロードまたはコピーする。
9. ダウンロードした YAML とリポジトリの `render.yaml` を見比べ、**`plan` 以外にずれている項目がないか**確認する。特に `region` / `diskSizeGB` / `numInstances` / `ipAllowList` / `connectionPool` / `storageAutoscalingEnabled` / `postgresMajorVersion`。
   - 生成される YAML は環境変数の**名前だけで値を含まず**、すべて `sync: false` になる。値の差分は見られない。
10. ずれがあれば相談すること。`plan` は**行ごと削除**してある（既存リソースは現行プランを保持する、と Blueprint 仕様に明記がある。実 ID を書くより安全）。

**CLI で代替する（ダッシュボードを触らずに済む）:**

`brew install render` で入る Render CLI には、push 前に `render.yaml` を**実際のワークスペースに対して**検証するコマンドがある。YAML 構文とスキーマに加え、プラン名やリージョンの妥当性、**既存リソースとの衝突**、そして**ブランチの存在**まで見る。

```sh
render login
render blueprints validate render.yaml
```

ブランチ未作成のときの出力はドキュメントに例がある: `services[0].branch (line 19, column 5): branch prod could not be found`。
失敗時は非ゼロで終了する。CLI v2.7.1 以降が必要。

> **注意**: 新規に作る develop サービスの方は `plan: free` を**明示する**。新規リソースで `plan` を省略すると既定の `0.5c-512mb`（$7/月）になる。

STEP 0 の修正を push したあと、**Manual Sync を手動で実行**してデプロイ結果を確認する。問題なければ Auto Sync を戻すかどうかを判断する。

---

## STEP 0.5. push の順序と GitHub のブランチ保護

**順序を守ること。ブランチ保護は最後。**

1. **`develop` を先に push する。**

   ```sh
   git push -u origin develop
   ```

   Blueprint が追跡しているのは `main` なので、これでは**同期は走らない**。目的は、Render が
   `branch: develop` を解決しにくる前に ref を存在させること。**ブランチの存在は Render 側で実際に
   検証される** — ドキュメントのエラー例が `services[0].branch (line 19, column 5): branch prod could not be found`。

2. **`main` を push する。**

   ```sh
   git push origin main
   ```

   **Blueprint 同期を起こすのはこの push だけ**（正確には「追跡ブランチへの push のうち Blueprint ファイルを
   変更したもの」）。`realize-beauty-api-dev` が作られる。既存の本番サービスも定義を変更した扱いになるため
   再デプロイされる。

3. **両ブランチの CI が green になるのを待つ。** これは礼儀ではなく門。壊れた `main` を直 push で
   修正できる最後の瞬間であり、次のステップの前提でもある。

4. **STEP 5 に進んで develop サービスの環境変数を入れる。** `render.yaml` の修正が要ると分かった場合、
   まだ直 push で直せる。

5. **最後に `main` の保護を設定する。** リポジトリ → Settings → Rules → Rulesets → New branch ruleset。
   - Target: `main` のみ
   - **Require a pull request before merging**
   - **Require status checks to pass** — 選ぶのは **`Backend (Laravel)`** と **`Frontend (Vue)`**。
     `CI` でも `backend` でもなく、ジョブの `name:` の値。
   - Bypass list は意識して決める。空なら自分も PR 必須になる。

> **保護を先にかけると push が拒否されうる。** 方式で挙動が違う。
> **Classic branch protection rule** は「Do not allow bypassing the above settings」が既定 OFF で、
> リポジトリ管理者は免除される。**Ruleset**（今の GitHub UI が誘導する方）は bypass リストが既定で空で、
> 管理者も免除されない。後者で先に保護をかけると、溜まっているコミットの直 push が弾かれる。
>
> **必要ステータスチェックの選択肢には、過去7日に成功したチェックしか出ない。** CI がしばらく走って
> いないと、そもそも選べない。ステップ3で green にしてから設定すること。

[ADR-010](decisions/ADR-010-git-workflow.md) と [CONTRIBUTING.md](../CONTRIBUTING.md) は「main へ直接
コミットしない」と定めているが、実際には守られてこなかった。2環境に分けた今、`main` への直接コミットは
**本番への直接デプロイ**を意味する。

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

> **`render.yaml` は Blueprint が追跡しているブランチ（通常 `main`）に載らないと効かない。** feature ブランチを
> `develop` にマージしただけでは `realize-beauty-api-dev` は作られない。Blueprint の Settings でどのブランチを
> 追跡しているかを確認し、そのブランチへマージしてから Manual Sync すること。

> **リージョンを本番と揃える。** `render.yaml` に `region` は書いていない（本番の現在のリージョンが
> ここからは確認できず、かつ**リージョンは作成後に変更できない**ため、書き違えると作り直しになる）。
> ダッシュボードで `realize-beauty-api` のリージョンを確認し、develop サービスも**同じリージョン**で作ること。
> STEP 1 の Neon プロジェクトのリージョン（AWS Singapore を選ぶ前提で書いてある）も、これに合わせる。
> 揃っていないと DB との往復が1リクエストごとに効いてくる。

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

ローカルからの手動デプロイも同じで、本番は `npm run deploy`、develop は `npm run deploy:develop` を使う。どちらも `npm run build` を実行してから `wrangler deploy` する（`dist/` は最後にビルドしたものが残っているだけで、どの環境向けかを持たない）。

> **本番へ出すときは、本番のビルド変数を与えて実行すること。** `VITE_API_BASE_URL` と `VITE_ENV_LABEL` は
> ビルド時にバンドルへ焼き込まれるため、スクリプトが `npm run build` を挟んでも**値までは面倒を見ない**。
> ビルド時の値はスクリプトの中には入っていない。この STEP の 1. のようにコマンドの前置きで
> 環境変数を渡している場合、同じシェルでそのまま `npm run deploy` しても値は引き継がれず、
> **`VITE_API_BASE_URL` も `VITE_ENV_LABEL` も未設定のバンドルが本番 Worker に出る**。
> API のベース URL は同一オリジンの `/api/v1` にフォールバックし、Worker には API がないので
> 公開予約ページも管理画面も動かない（[apiClient.ts:8](../frontend/src/services/apiClient.ts#L8)）。
> 逆に `export` していた場合は develop 向けのバンドルがそのまま本番に出る。どちらにしても事故なので、
> **デプロイ先に対応する変数を毎回明示的に渡す**。
>
> ```sh
> cd frontend
> VITE_API_BASE_URL=https://<本番 API>/api/v1 npm run deploy    # VITE_ENV_LABEL は渡さない（バッジを出さない）
> VITE_API_BASE_URL=https://<develop API>/api/v1 VITE_ENV_LABEL=DEVELOP npm run deploy:develop
> ```
>
> ふだんの本番デプロイは Workers Builds（ビルド変数がダッシュボードに入っている）に任せ、
> ローカルからの手動デプロイは復旧時などに限るのが安全。

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

コマンドは実行前に**接続先のホストとデータベース名を表示し、そのデータベース名の入力を求める**。`APP_ENV` ではなく接続先の実体を見ている。

ただし、この確認が止められるのは**打ち間違い**であって、向け違えそのものではない。対話実行では期待値（接続先のデータベース名）が入力を求める2行上に表示されるため、`DB_URL` を本番へ向けたまま表示どおりに入力すれば、そのまま通る。`--force` を使う場合だけは、`--expect-database` に**自分で**書いた名前が接続先と一致しなければ止まる（CI やスクリプトから叩くときはこちらを使う）。**接続先が正しいことは、実行者が表示されたホスト名で確かめること。**

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

> **この最後の1項目は本番と挙動が違う。** develop だけ `QUEUE_CONNECTION=deferred` で、LINE 通知と
> Google カレンダー連携のジョブがレスポンス送出後に実際に実行される。本番は `database` のままで
> キューワーカーが居ないため、同じジョブは `jobs` テーブルに積まれるだけで**実行されない**。
> つまり develop で通っても本番で通ったことにはならない（この点だけ develop のほうが機能する）。
> 本番のキューワーカーは未対応の課題として残っている（[ADR-031](decisions/ADR-031-two-environment-deployment.md) 参照）。

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
