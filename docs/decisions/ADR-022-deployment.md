# ADR-022: デプロイ構成（Render + Cloudflare Workers + R2）

## Status

Accepted

---

## Date

2026-07-13

---

## Context

MVP を公開するため、フロント（Vue SPA）とバックエンド（Laravel API）を
デプロイする必要がある。開発者はデプロイ初心者であり、サーバ運用の手間を
最小化しつつ、[ADR-005](ADR-005-cloudflare-r2.md)（R2）と整合する構成が望ましい。

SPA と API は分離構成（ADR-001 / ADR-011）であり、それぞれに適したホスティングを選ぶ。

---

## Decision

- **フロント**: Cloudflare Workers Static Assets（`frontend/wrangler.jsonc`、git連携で自動デプロイ）
- **バックエンド**: Render（Docker）+ Render Managed PostgreSQL
- **写真ストレージ**: Cloudflare R2（`FILESYSTEM_DISK=r2`）
- **CI と分離**: デプロイはホスト側の git 連携で行い、GitHub Actions は検証のみ（ADR-013）

構成を支える実装:

- フロントの API 接続先を `VITE_API_BASE_URL` で注入（本番は API の絶対URL、
  開発は未設定で Vite プロキシ経由の相対パス）
- 別ドメイン間通信のため CORS を `config/cors.php`（`CORS_ALLOWED_ORIGINS`）で制御。
  認証は Bearer トークンのため Cookie/CSRF は不要で `supports_credentials=false`。
  未設定時は全拒否（フェイルクローズ）とし、環境変数の設定漏れが全オリジン開放に
  ならないようにする
- `filesystems.php` に `r2` ディスク（S3互換）を追加し、`league/flysystem-aws-s3-v3` を導入
- Render 用の `backend/Dockerfile`・`backend/docker/entrypoint.sh`・ルートの `render.yaml`（Blueprint）
- SPA ルーティングは `wrangler.jsonc` の `assets.not_found_handling: "single-page-application"` で
  index.html にフォールバック

---

## Alternatives Considered

### Laravel Cloud

Laravel 専用で最も手間が少ないが有料。無料〜低コストで始めたい要望から Render を選択。

### 同一オリジン配信（Laravel が SPA も配信）

CORS 不要になるが、フロントのビルド成果物をバックエンドに同梱する結合が生じ、
CDN 配信の利点も失う。分離構成の方が SPA 設計（ADR-011）と整合する。

### VPS + Laravel Forge

自由度は高いがサーバ管理の手間が最大。初回デプロイには不向き。

---

## Consequences

### Advantages

- git push で自動デプロイ、サーバ管理はほぼ不要
- フロントは CDN 配信、API と独立してスケール/デプロイ可能
- R2 で写真を永続化（揮発しない）

### Disadvantages

- API は MVP 構成（`php artisan serve`）で、高トラフィックには非対応（将来 Octane 等へ）
- 無料枠には制約（スリープ・期限）があり、本番運用では有料化が必要
- デプロイ対象が2つ + R2 で、初期設定の手順がやや多い

---

## Note: フロントの配信方式（2026-09-07 追記）

**本 ADR は当初「Cloudflare Pages」と記述していたが、実際の配信は Cloudflare Workers Static Assets である。**
2026-07-22（`03b5820`）に `frontend/wrangler.jsonc` を追加して移行しており、本文の記述を実態に合わせた。

- SPA フォールバックを担うのは `frontend/public/_redirects` ではなく、`wrangler.jsonc` の
  `assets.not_found_handling: "single-page-application"`。`frontend/public/` には `favicon.ico` しか置いていない
- 払い出される URL は `https://realize-beauty.<subdomain>.workers.dev` で、デプロイをまたいで安定する。
  そのため完全一致の `CORS_ALLOWED_ORIGINS` に載せられる

## Note: 2環境構成への拡張（2026-09-07 追記）

本 ADR のデプロイ先は本番の1つだけだった。[ADR-031](ADR-031-two-environment-deployment.md) で
**`develop` ブランチ → develop 環境、`main` → 本番**の2環境構成に拡張した。

| | production | develop |
|---|---|---|
| Render Web | `realize-beauty-api` | `realize-beauty-api-dev`（free） |
| DB | Render Managed PostgreSQL | Neon 無料プラン（`DB_URL`） |
| フロント Worker | `realize-beauty` | `realize-beauty-develop` |
| `APP_ENV` | `production` | `staging` |
| R2 | 既存バケット | 専用バケット |

本 ADR の構成要素（Render / Workers / R2 / `VITE_API_BASE_URL` / `CORS_ALLOWED_ORIGINS`）は
そのまま2組に増える。develop 側の値・資格情報を本番と共有しない理由は ADR-031 を参照。

---

## References

- docs/deployment.md
- docs/decisions/ADR-001-api-first.md
- docs/decisions/ADR-005-cloudflare-r2.md
- docs/decisions/ADR-011-frontend-architecture.md
- docs/decisions/ADR-013-ci-cd.md
- [ADR-031](ADR-031-two-environment-deployment.md)（develop 環境と本番環境の分離）
