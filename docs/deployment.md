# 本番デプロイ手順（Render + Cloudflare Workers + R2）

**本書は本番環境（`main` ブランチ）の初回デプロイ手順である。**
develop 環境（デモ兼先行検証、`develop` ブランチ）の構築は
[runbook-develop-env.md](runbook-develop-env.md) を参照する。
2環境に分けた理由は [ADR-031](decisions/ADR-031-two-environment-deployment.md)、
構成そのものは [ADR-022](decisions/ADR-022-deployment.md) を参照。

- **フロント（Vue SPA）** → Cloudflare Workers Static Assets（`frontend/wrangler.jsonc`）
- **バックエンド（Laravel API）** → Render（Docker）+ Render の Managed PostgreSQL
- **写真ストレージ** → Cloudflare R2（[ADR-005](decisions/ADR-005-cloudflare-r2.md)）
- 認証は Sanctum の Bearer トークンなので、フロントとAPIが別ドメインでも CORS 許可のみで動く

---

## 0. 事前準備

- GitHub にリポジトリを push 済みにする
- Cloudflare アカウント / Render アカウントを作る
- **Cloudflare R2 バケットを作成**し、以下を控える
  - Access Key ID / Secret Access Key（R2 の API トークン）
  - バケット名、S3 API エンドポイント（`https://<accountid>.r2.cloudflarestorage.com`）
  - 公開URL（R2 のパブリックバケットURL、または独自ドメイン）

> **既存環境へハードニング（2026-09-02）を反映する場合は、本書ではなく
> [runbook-hardening.md](runbook-hardening.md) の手順に従うこと。**
> `DB_SSLMODE` の疎通確認・本番パスワードのローテーション・R2 の公開停止など、
> 順序を誤ると全断や情報漏洩につながる手動作業が含まれる。

---

## 1. バックエンド（Render）

1. Render → **New → Blueprint** → 本リポジトリを選択（ルートの `render.yaml` を自動検出）
2. Blueprint が API サービス（`realize-beauty-api`、`branch: main`）と PostgreSQL を作成する。
   同じ `render.yaml` には develop 用の `realize-beauty-api-dev` も含まれる
   （[runbook-develop-env.md](runbook-develop-env.md) STEP 5 で設定する）。
   `sync: false` の環境変数を入力:
   - `APP_KEY`: ローカルで `php artisan key:generate --show` を実行して出た `base64:...` を貼る
   - `APP_URL`: 払い出された API のURL（例 `https://realize-beauty-api.onrender.com`）
   - `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` / `R2_BUCKET` / `R2_ENDPOINT` / `R2_PUBLIC_URL`
   - `CORS_ALLOWED_ORIGINS`: いったん空（フロントのURL確定後に §3 で入れる）。
     空の間は別オリジンからの API 呼び出しがブラウザにブロックされる
   - `OPENAI_API_KEY`: 今は空でよい（AI要約は後日）
3. デプロイ完了後、`https://<api>/up` が 200 を返すことを確認
4. **初期オーナー作成**: 以下を実行してログインユーザーを作る。パスワードは対話入力で、
   12文字以上が必須。API サービスの **Shell** から実行する
   ```sh
   php artisan salon:create-owner
   ```
   （無料プランなど Shell が使えない環境では、ローカルから
   `DB_URL='postgres://...' php artisan salon:create-owner` として本番DBに向ける）
   `php artisan db:seed` は**使わない**。デモの顧客・予約と既定パスワード（`password`）の
   ユーザーを投入してしまうため。Render 上（`APP_ENV=production`）では `DatabaseSeeder` が
   例外を投げて止まるが、**上のようにローカルから本番DBへ向けて実行した場合はこのガードは効かない**
   （環境変数を見ているため）。本番DBに対して `db:seed` を実行しないこと。

---

## 2. フロント（Cloudflare Workers Static Assets）

配信設定は `frontend/wrangler.jsonc` にある。Worker 名は `realize-beauty`。

1. Cloudflare → **Workers & Pages → Create → Worker** で本リポジトリを接続する
2. **Settings → Build** の設定:

   | 項目 | 値 |
   |---|---|
   | Root directory | `frontend` |
   | Build command | `npm run build` |
   | Deploy command | `npx wrangler deploy --env=""` |
   | Branch control（production branch） | `main` |

   > **Deploy command は `--env=""` を付ける。** `wrangler.jsonc` が `env`（develop）を
   > 定義しているため、引数なしの `wrangler deploy` は「環境が定義されているのに対象が
   > 指定されていない」という警告を出す（非致命的だがログが読みにくくなる）。
   > **`--env production` は使わない** —— `realize-beauty-production` という別の Worker を
   > 新規作成してしまう。`--env=""` は警告を出さず、Worker 名 `realize-beauty` に解決される。

3. ビルド変数:
   - `VITE_API_BASE_URL` = `https://<api>/api/v1`（Render のAPI URL + `/api/v1`）
   - `VITE_ENV_LABEL` は**設定しない**（設定すると画面上部に環境バッジが出る。develop 専用）
4. デプロイ後、`https://realize-beauty.<subdomain>.workers.dev` が払い出される。
   この URL はデプロイをまたいで安定するので、完全一致の CORS 許可リストに載せられる
   - SPA ルーティングは `wrangler.jsonc` の
     `assets.not_found_handling: "single-page-application"` で index.html にフォールバック済み
5. **「非 production ブランチのビルド」は有効にしない。** 既定の `wrangler versions upload` は
   バージョンごとに別ホスト名を払い出すため、完全一致の CORS 許可リストが毎回弾く

---

## 3. 仕上げ（CORS でフロントのURLを許可する）

CORS は未設定なら全拒否（フェイルクローズ）のため、ここを飛ばすとフロントから API を
呼べない。逆に、設定を忘れても全オリジンに開放されることはない。

1. Render の API サービスの環境変数 `CORS_ALLOWED_ORIGINS` にフロントのURL
   （例 `https://realize-beauty.<subdomain>.workers.dev`）を設定して再デプロイ
2. ブラウザでフロントのURLを開き、**§1-4 の `salon:create-owner` で作ったオーナー**で
   ログイン確認する。`admin@example.com` / `password` は `db:seed` が投入するデモ用の
   アカウントであり、**本番には存在しない**（§1-4 のとおり本番で `db:seed` は使わない）
3. 顧客登録 → カルテ作成 → 写真アップロード（R2に保存され表示される）まで通ればOK
4. 画面上部に環境バッジ（`DEVELOP` など）が**出ていない**ことを確認する。
   出ていたらフロントのビルド変数に `VITE_ENV_LABEL` が残っている

---

## 4. AI要約を後から有効化

Render の環境変数に `OPENAI_API_KEY` を設定して再デプロイするだけ。
未設定でもアプリは動作し、AI要約ボタンだけがエラートーストを出す状態になる。

---

## 補足

- **API サーバは MVP 構成**（`php artisan serve`）。トラフィックが増えたら
  Laravel Octane / FrankenPHP や nginx + php-fpm への移行を検討する。
- **Render/PostgreSQL の無料枠は制約あり**（一定期間で削除・スリープ等）。
  本番運用時は有料プランへ。
- マイグレーションはコンテナ起動時（`backend/docker/entrypoint.sh`）に自動実行される。
  DB のコールドスタートで初回接続が失敗しても、最大5回のバックオフ付きで再試行する
  （全試行が失敗すればコンテナは起動しない）。
- **`render.yaml` に既存リソースの `plan` を書かない。** Blueprint の同期は差分ではなく
  ファイル全体を適用してダッシュボードの設定を上書きするため、実態とずれた `plan` を
  書いておくと、同期のたびに本番が降格する。既存リソースで省略すれば現行プランが保持される
  （[ADR-031](decisions/ADR-031-two-environment-deployment.md)）。
- 環境変数を変更したら**再デプロイする**。`entrypoint.sh` が起動時に `config:cache` を
  実行するため、値を入れ替えただけでは反映されない。
