# ADR Summary

`docs/decisions/` に記録された設計判断（ADR）の要約。AI は本ファイルを設計判断の材料として参照する。ADR を追加・更新したら本ファイルも更新すること（詳細は各 ADR 原本を参照）。

最終更新: 2026-09-03 ／ 対象: ADR-001〜029（すべて Accepted。ADR-020 のみ ADR-027 により置換済み）

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
| 013 | CI/CD | GitHub Actions（`.github/workflows/ci.yml` 実装済み）。backend: Pint + `php artisan test`（PostgreSQLサービス）／frontend: type-check + lint + Vitest + build。テストランナーは PHPUnit（Pestは未導入）。CD は将来。 |
| 014 | AI Development Guidelines | AI は AGENTS.md と ADR を参照して実装。推測実装しない。設計変更はドキュメント先行。AI のコードもレビュー対象。TODO には理由を書く。 |
| 015 | Coding Standards | 命名: Class=PascalCase / Method・Variable=camelCase / Table・Column=snake_case。原則 SRP/DRY/KISS/YAGNI。整形は Pint（BE）/ESLint+Prettier（FE）。 |
| 016 | OpenAPI First | すべての REST API は実装より先に OpenAPI へ定義。Laravel 実装は OpenAPI に従う（006 を開発フローとして具体化）。 |
| 017 | API Documentation | OpenAPI 3.1 を仕様書に、Redocly を正式ドキュメント、Swagger UI を動作確認用に。編集は OpenAPI のみ、他は生成/参照。配置は `docs/api/`。 |
| 018 | Flexible Medical Record Structure | カルテは固定カラムでなくラベル付きブロックの集合。`records`（カルテ本体）+ `record_blocks`（入力内容）+ `record_block_templates`（店舗別項目、初期は空）。業種を問わず拡張可能。 |
| 019 | Customer・Record・Photo API 実装 | 顧客/カルテ/写真 API を Controller→Service→Repository の3層で実装。Resource でレスポンス、FormRequest で検証、カルテブロックは Repository 内で同期、Photo は SoftDelete、認証は Sanctum。 |
| 020 | Frontend Theme | 白×くすみピンク×ベージュ + Glassmorphism。PrimeVue v4 definePreset + CSS変数 `--rb-*`（main.css）に集約。共通コンポーネント（GlassCard/KpiCard等）で構成。写真はInstagram風グリッド。開発用モックは `npm run dev:mock`。 |
| 021 | OpenAI 連携（AI要約） | AI責務を `OpenAIService` に集約（Controller→RecordService→OpenAIService）。Http クライアント使用で追加パッケージなし、`Http::fake()` でテスト。要約対象は内容のあるテキストブロックのみ、ボタン押下時のみ生成し `records.ai_summary` に保存。APIレスポンスキーは `summary`（OpenAPI準拠）、DBカラムは `ai_summary`（ERD準拠）で併存。 |
| 022 | デプロイ構成 | フロント=Cloudflare Pages / API=Render(Docker)+Managed PostgreSQL / 写真=R2。フロントは `VITE_API_BASE_URL` でAPI接続先を注入、CORSは `CORS_ALLOWED_ORIGINS`（未設定なら全拒否のフェイルクローズ）。`render.yaml`・`backend/Dockerfile` 一式。手順は docs/deployment.md。API は MVP 構成（artisan serve）。 |
| 023 | 予約コア（フェーズ1） | 予約をフェーズ分割（1=予約コア／2=Web予約・LINE／3=Googleカレンダー）。`reservations.end_at` を永続化し `start_at + menu.duration_minutes` でサーバ導出。ダブルブッキング防止は `DB::transaction` + `lockForUpdate` で重複時 422（cancelled/no_show は除外）。営業時間は手動予約をブロックしない（時間外対応・事後入力を許可）。日付境界は Asia/Tokyo。カレンダーUIは自前実装（FullCalendar 不使用）、シフト管理は持たない。 |
| 024 | LINE 連携（サロンごとの公式アカウント） | チャネル認証情報を `line_settings` に encrypted cast で保存するデータ駆動マルチテナント（サロン追加はデータ登録のみ）。Webhook は全サロン共通の1本で `destination` から `bot_user_id` を照合し、未知の宛先・署名検証失敗でも **常に 200** を返す（LINE のリトライ暴走防止）。顧客紐付けはワンタイム連携コード（72時間・単回・未連携顧客限定）。予約UIは認証なしの公開ページ `/booking/{booking_slug}`（throttle 必須）。LINE API は Http クライアント（公式SDK不使用）。連携解除は全顧客の `line_user_id` をクリアする。 |
| 025 | Googleカレンダー双方向同期（フェーズ3） | スコープは `calendar.events` + `calendar.calendarlist.readonly`（未審査でも100ユーザーまで動作するため審査は実装のブロッカーではない）。1接続=1カレンダーで読み書き、モードは per_staff / shared。エコー防止は `extendedProperties.private` のマーカー。変更検知は watch チャネル + syncToken 増分同期、競合時は RB を真実とする。Google 側の私用予定は時刻のみ保存しタイトルは保存しない。OAuth は API 側 redirect_uri でサーバ交換し `FRONTEND_URL` へ 302。Google API も Http クライアント。 |
| 026 | ダッシュボード刷新（Analytics 前倒し） | KPI4枚（新規顧客・予約数・売上・リピート率）＋売上推移＋本日の来店予約＋人気メニュー＋顧客セグメントへ刷新し、旧KPIと「最近のカルテ/顧客」一覧は廃止。売上は `reservations.price`（予約時点の税込スナップショット）で記録し、会計・決済テーブルは作らない。集計の日付境界を Asia/Tokyo に統一（ADR-023 の UTC/JST 混在を解消）。顧客セグメントは来店履歴から自動分類（手動タグは作らない）。AppLayout を 1024px 未満で Drawer 化。グラフは chart.js（PrimeVue Chart 経由）。 |
| 027 | 管理画面パープルテーマ（ADR-020 を supersede） | 「白×くすみピンク×ベージュ + Glassmorphism」から「ラベンダー/パープル + 不透明の白カード + フル高サイドバー」へ転換。`--rb-primary`（`#7c5cbf`）系を正典とし、旧 `--rb-pink-*` / `--rb-beige-*`（既存128箇所参照）は改名せずエイリアスとして残す。`backdrop-filter` は全廃、`.glass-card` は定義のみ不透明白カードに差し替え（新クラスは `.rb-card`）。状態色をブランド色から分離（`--rb-danger` / `--rb-success` / `--rb-accent-*`）。フォントは Noto Sans JP に統一。公開Web予約ページは `rb-legacy-theme` クラス（判定キーは `meta.legacyTheme`）で旧デザインを維持。 |
| 028 | 本番ハードニング | ログインthrottle（IP+メール）、`trustProxies`、Sanctumトークン期限、R2をprivate化し写真は署名付きURL、`.dockerignore`＋`php.ini-production`＋非root＋digest固定、`LOG_CHANNEL=stderr`とQueryExceptionのバインド値除去、`db:seed`の本番ガードと`salon:create-owner`。手動手順は docs/runbook-hardening.md。 |
| 029 | サブスクリプション課金・プランによる機能制御 | Stripe を決済・請求の Source of Truth とし、アプリは「プラン→機能」の対応表だけを持つ。プラン（Lite 980 / Standard 1,980 / Pro 3,980 円）と機能一覧の正典は `config/billing.php`（`plans` テーブルは作らない）、判定は `EntitlementService::can()/ensure()` に集約しプラン名で分岐しない。契約は `subscriptions` / `stripe_webhook_events` / `subscription_events` の新規3テーブルに持ち、既存テーブルは変更しない。**契約が無い・失効したサロンは全機能不可（fail closed）**、`past_due` では止めず `unpaid` で停止。遮断は route middleware `feature:<key>` ＋ Service/Job/Console の guard clause ＋ 外部webhookの200無視の3層で、公開予約の slug 経路は 404、機能不足は **403**（401 は禁止）。カード入力は Stripe Checkout のリダイレクト方式でアプリに到達させず、日割り・解約（`cancel_at_period_end`）は Stripe に委ねる。契約同期は署名検証付き webhook（検証失敗は 400）のみ、冪等性は `stripe_event_id` の unique 制約。Stripe API は Http クライアント（公式SDK・cashier 不使用）、Test/Live の取り違えは `php artisan stripe:check` と `assertModeMatchesEnvironment()` で検出。 |

## 横断テーマ

- **API First / OpenAPI First**（001, 006, 016, 017）: OpenAPI が唯一の正。実装前に仕様を書く。
- **レイヤードアーキテクチャ**（008, 009, 019）: Controller（Request/Validation/Response）→ Service（ロジック/トランザクション/AI）→ Repository（DB アクセス）→ Model。
- **SaaS 前提の設計**（002, 003）: `salon_id` マルチテナント、`role` 予約、SoftDelete。MVP でも将来拡張を織り込む。
- **Documentation Driven + AI**（007, 014）: 設計書を資産とし、変更はドキュメント先行。AI は AGENTS.md・ADR を参照。
- **外部API連携は Http クライアント**（021, 024, 025, 029）: OpenAI・LINE・Google・Stripe とも公式SDKを入れず `Http` で実装し、`Http::fake()` でテストする。

## 補足（決定履歴）

- **写真ストレージ**: ADR-019 の public ディスク保存は **MVP 段階の暫定措置**。最終的には ADR-005 のとおり **Cloudflare R2 へ移行する**（ディスク差し替えで移行できる形を維持）。詳細は ADR-019 の「Note: 写真ストレージ（2026-07-08 追記）」。
- **ADR 命名規則**: `ADR-<3桁連番>-<kebab-case-title>.md`。`docs/decisions/README.md` を実ファイル名に合わせて更新済み（2026-07-08）。
