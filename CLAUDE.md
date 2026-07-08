# CLAUDE.md

このリポジトリで作業する際のガイドです。AI向けの開発ルールは [AGENTS.md](AGENTS.md) を正典とし、本ファイルはそれを補完する運用情報（スタック・コマンド・構成）をまとめます。ルールが矛盾する場合は AGENTS.md と `docs/` 配下の設計書を優先してください。

## プロジェクト概要

Realize Beauty — 小規模美容サロン向けの顧客管理・電子カルテ・写真管理・AI要約 SaaS。MVP 段階からマルチテナント（`salon_id`）・Role 権限・SoftDelete・API First を前提に設計する。詳細は [docs/PROJECT.md](docs/PROJECT.md)。

## 技術スタック

- Backend: Laravel 13 / PHP 8.3+ / Laravel Sanctum 認証（`backend/`）
- Frontend: Vue 3 + TypeScript + PrimeVue / Pinia / Vue Router（`frontend/`）
- DB: PostgreSQL ／ Storage: Cloudflare R2 ／ AI: OpenAI API ／ CI: GitHub Actions

## 開発方針（最重要）

Documentation Driven Development。**まず設計を書き、設計を元に実装する。** 実装前に必ず `docs/` を以下の優先順で確認する:

1. `docs/requirements/MVP.md`
2. `docs/db/ERD.md`
3. `docs/api/endpoints.md`（OpenAPI: `docs/api/openapi.yaml`）
4. `docs/ui/`（`wireframe.md` ほか画面別）
5. `docs/roadmap/ROADMAP.md`
6. `docs/standards/`

- 設計書と実装が矛盾する場合は設計書を優先。仕様が曖昧なら推測せず質問する。
- **MVP 外の機能は実装しない**（Future Features は対象外）。
- 設計判断は `docs/decisions/` に ADR として記録する（テンプレート: `docs/decisions/TEMPLATE.md`）。
- `role` カラムは将来の権限制御用に予約済み。MVP で未使用でも削除・変更しない。

## アーキテクチャ規約

Backend は Controller → Service → Repository → Model の層構造:

- Controller にビジネスロジックを書かない（Fat Controller 禁止）
- Service = ビジネスロジック、Repository = DB 操作のみ
- Validation は FormRequest、レスポンスは Resource で返す
- RESTful かつ Laravel 標準（Laravel Way）に従う

Frontend は `pages/ components/ layouts/ composables/ services/ stores/ types/ utils/` 構成:

- 画面=Pages、UI=Components、API 通信=Services、状態管理=Pinia、共通ロジック=Composable
- Vue 3 Composition API を使用。TypeScript の `any` は極力使わない
- 過剰なコメントを避け、命名で意図を表現する

詳細は [docs/standards/backend.md](docs/standards/backend.md) / [docs/standards/frontend.md](docs/standards/frontend.md)。

## コマンド

Backend（`backend/`）:

```bash
composer dev      # server + queue + logs(pail) + vite を並行起動
composer test     # config:clear 後に artisan test
./vendor/bin/pint # コード整形（Laravel Pint）
php artisan migrate
```

Frontend（`frontend/`）:

```bash
npm run dev         # Vite 開発サーバ
npm run build       # type-check + build
npm run type-check  # vue-tsc
npm run lint        # oxlint + eslint（--fix）
npm run format      # oxfmt
```
