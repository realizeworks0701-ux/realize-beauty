# ADR Summary

`docs/decisions/` に記録された設計判断（ADR）の要約。AI は本ファイルを設計判断の材料として参照する。ADR を追加・更新したら本ファイルも更新すること（詳細は各 ADR 原本を参照）。

最終更新: 2026-07-08 ／ 対象: ADR-001〜020（すべて Accepted）

## 一覧

| ADR | 決定 | 要点 |
| --- | --- | --- |
| 001 | API First Development | 実装前に API 仕様を設計。OpenAPI を唯一の API 仕様書とし、実装は仕様に従う。 |
| 002 | Multi Tenant Architecture | 基本テーブル（users/customers/records/menus/reservations）に `salon_id` を保持。SaaS 化・複数店舗対応。 |
| 003 | Role Based Access Control | users に `role`（owner/manager/staff）を追加。MVP は全員同一権限、将来 Role Middleware を導入。カラムは予約済みで削除・変更禁止。 |
| 004 | PostgreSQL Adoption | DB は PostgreSQL。MySQL より拡張性・SQL 機能を重視。 |
| 005 | Cloudflare R2 Storage | カルテ写真のオブジェクトストレージに Cloudflare R2（S3 互換、転送料無料）。※実装状況は下記「注意点」参照。 |
| 006 | OpenAPI Specification | OpenAPI 3.1 を採用。`docs/api/openapi.yaml` を唯一の仕様書に。型・クライアント自動生成が可能。 |
| 007 | Documentation Driven Development | 設計書を Single Source of Truth とし、仕様変更はコードより先にドキュメントを更新。順序: PROJECT→MVP→ERD→API→OpenAPI→Wireframe。 |
| 008 | Repository Pattern | Repository はデータアクセス（Query/CRUD）のみ。Service が Repository を利用。 |
| 009 | Service Layer | ビジネスロジック・トランザクション・AI 連携は Service に。Controller は Service 呼び出しのみ（Fat Controller 禁止）。 |
| 010 | Git Workflow | GitHub Flow。ブランチ: feature/fix/docs/refactor/chore。コミット: feat/fix/docs/refactor/test/chore。main へ直接 Push 禁止、PR 必須。 |
| 011 | Frontend Architecture | Vue 3 + TypeScript + PrimeVue + Vue Router + Pinia の SPA。責務分離、Feature First 構成。 |
| 012 | Testing Strategy | Backend: Pest/PHPUnit、Frontend: Vitest、E2E: Playwright（将来）。優先度 Service→Repository→API→UI。 |
| 013 | CI/CD | GitHub Actions。CI で Lint/Format/PHPUnit/Pest/Vitest。CD は将来 Laravel Cloud/VPS へ自動デプロイ。 |
| 014 | AI Development Guidelines | AI は AGENTS.md と ADR を参照して実装。推測実装しない。設計変更はドキュメント先行。AI のコードもレビュー対象。TODO には理由を書く。 |
| 015 | Coding Standards | 命名: Class=PascalCase / Method・Variable=camelCase / Table・Column=snake_case。原則 SRP/DRY/KISS/YAGNI。整形は Pint（BE）/ESLint+Prettier（FE）。 |
| 016 | OpenAPI First | すべての REST API は実装より先に OpenAPI へ定義。Laravel 実装は OpenAPI に従う（006 を開発フローとして具体化）。 |
| 017 | API Documentation | OpenAPI 3.1 を仕様書に、Redocly を正式ドキュメント、Swagger UI を動作確認用に。編集は OpenAPI のみ、他は生成/参照。配置は `docs/api/`。 |
| 018 | Flexible Medical Record Structure | カルテは固定カラムでなくラベル付きブロックの集合。`records`（カルテ本体）+ `record_blocks`（入力内容）+ `record_block_templates`（店舗別項目、初期は空）。業種を問わず拡張可能。 |
| 019 | Customer・Record・Photo API 実装 | 顧客/カルテ/写真 API を Controller→Service→Repository の3層で実装。Resource でレスポンス、FormRequest で検証、カルテブロックは Repository 内で同期、Photo は SoftDelete、認証は Sanctum。 |
| 020 | Frontend Theme | 白×くすみピンク×ベージュ + Glassmorphism。PrimeVue v4 definePreset + CSS変数 `--rb-*`（main.css）に集約。共通コンポーネント（GlassCard/KpiCard等）で構成。写真はInstagram風グリッド。開発用モックは `npm run dev:mock`。 |

## 横断テーマ

- **API First / OpenAPI First**（001, 006, 016, 017）: OpenAPI が唯一の正。実装前に仕様を書く。
- **レイヤードアーキテクチャ**（008, 009, 019）: Controller（Request/Validation/Response）→ Service（ロジック/トランザクション/AI）→ Repository（DB アクセス）→ Model。
- **SaaS 前提の設計**（002, 003）: `salon_id` マルチテナント、`role` 予約、SoftDelete。MVP でも将来拡張を織り込む。
- **Documentation Driven + AI**（007, 014）: 設計書を資産とし、変更はドキュメント先行。AI は AGENTS.md・ADR を参照。

## 補足（決定履歴）

- **写真ストレージ**: ADR-019 の public ディスク保存は **MVP 段階の暫定措置**。最終的には ADR-005 のとおり **Cloudflare R2 へ移行する**（ディスク差し替えで移行できる形を維持）。詳細は ADR-019 の「Note: 写真ストレージ（2026-07-08 追記）」。
- **ADR 命名規則**: `ADR-<3桁連番>-<kebab-case-title>.md`。`docs/decisions/README.md` を実ファイル名に合わせて更新済み（2026-07-08）。
