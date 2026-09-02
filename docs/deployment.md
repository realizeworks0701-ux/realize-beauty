# デプロイ手順（Render + Cloudflare Pages + R2）

初回デプロイ向けの手順書。構成は [ADR-022](decisions/ADR-022-deployment.md) を参照。

- **フロント（Vue SPA）** → Cloudflare Pages（静的ホスティング）
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
2. Blueprint が API サービスと PostgreSQL を作成する。`sync: false` の環境変数を入力:
   - `APP_KEY`: ローカルで `php artisan key:generate --show` を実行して出た `base64:...` を貼る
   - `APP_URL`: 払い出された API のURL（例 `https://realize-beauty-api.onrender.com`）
   - `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` / `R2_BUCKET` / `R2_ENDPOINT` / `R2_PUBLIC_URL`
   - `CORS_ALLOWED_ORIGINS`: いったん空（Pages のURL確定後に §3 で入れる）。
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

## 2. フロント（Cloudflare Pages）

1. Cloudflare → **Workers & Pages → Create → Pages → Connect to Git** で本リポジトリを選択
2. ビルド設定:
   - **Root directory**: `frontend`
   - **Build command**: `npm run build`
   - **Build output directory**: `dist`
3. 環境変数:
   - `VITE_API_BASE_URL` = `https://<api>/api/v1`（Render のAPI URL + `/api/v1`）
4. デプロイ後、`https://<project>.pages.dev` が払い出される
   - SPA ルーティングは `frontend/public/_redirects` で index.html にフォールバック済み

---

## 3. 仕上げ（CORS でフロントのURLを許可する）

CORS は未設定なら全拒否（フェイルクローズ）のため、ここを飛ばすとフロントから API を
呼べない。逆に、設定を忘れても全オリジンに開放されることはない。

1. Render の API サービスの環境変数 `CORS_ALLOWED_ORIGINS` に Pages のURL
   （例 `https://realize-beauty.pages.dev`）を設定して再デプロイ
2. ブラウザで Pages のURLを開き、`admin@example.com` / `password` でログイン確認
3. 顧客登録 → カルテ作成 → 写真アップロード（R2に保存され表示される）まで通ればOK

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
