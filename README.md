# Realize Beauty

> **RealizeWorks**
>
> **「夢を目標に、目標を現実に。」**

美容サロン（美容室・理容室・ネイル・エステ・アイラッシュなど）向け業務支援Webアプリケーション。

---

# Philosophy

RealizeWorksは、「夢を目標に、目標を現実に」を理念とし、
現場で本当に役立つWebシステムを開発することを目的としています。

このプロジェクトは単なる学習用アプリではなく、
実際に美容業界で利用されることを前提に設計・開発を行います。

---

# Development Principles

RealizeWorksでは以下の5つを開発原則とします。

1. シンプルさを優先する
2. 動くものを素早く届ける
3. 保守性は妥協しない
4. AIを積極的に活用する
5. ユーザーの声を最優先に改善する

---

# Tech Stack

## Backend

- Laravel 12
- PostgreSQL
- Laravel Sanctum

## Frontend

- Vue 3
- TypeScript
- PrimeVue
- Axios
- Vite

## Infrastructure

- GitHub
- GitHub Actions
- Cloudflare R2
- OpenAI API
- Stripe
- Laravel Cloud（予定）

---

# Project Structure

```
realize-beauty/
├── backend/
├── frontend/
├── docs/
│   ├── requirements/
│   ├── db/
│   ├── api/
│   ├── ui/
│   ├── decisions/
│   └── standards/
├── README.md
├── .editorconfig
└── .gitignore
```

---

# Git Flow

```
main
│
develop
│
├── feature/auth
├── feature/customer
├── feature/dashboard
├── feature/record
└── feature/photo
```

## Rules

- `main` は常にリリース可能な状態を維持する
- 日常開発は `develop`
- 新機能は `feature/*`
- Pull Request を経由してマージする

---

# Commit Convention

| Prefix | Description |
|---------|-------------|
| feat | 新機能 |
| fix | バグ修正 |
| docs | ドキュメント更新 |
| refactor | リファクタリング |
| style | フォーマット変更 |
| test | テスト追加・修正 |
| chore | その他 |

### Example

```
feat: 顧客一覧APIを追加
fix: Sanctum認証を修正
docs: ER図を更新
refactor: CustomerServiceを整理
```

---

# Laravel Architecture

```
Controller
    ↓
Service
    ↓
Repository
    ↓
Model
```

## Rule

Controllerにはビジネスロジックを書かない。

---

# Vue Architecture

```
src/

├── components/
├── pages/
├── layouts/
├── composables/
├── router/
├── services/
├── stores/
├── types/
└── utils/
```

---

# REST API Convention

```
GET    /customers
GET    /customers/{id}
POST   /customers
PUT    /customers/{id}
DELETE /customers/{id}
```

RESTful APIを基本とする。

---

# Development Flow

新しい機能を追加する場合は必ず以下の順番で行う。

```
Requirements
      ↓
Database Design
      ↓
API Design
      ↓
UI Design
      ↓
Laravel Implementation
      ↓
Vue Implementation
      ↓
Testing
```

---

# Architecture Decision Record (ADR)

設計判断は docs/decisions に記録する。

例

```
0001-postgresql.md
0002-sanctum.md
0003-primevue.md
```

「なぜその技術を採用したのか」を残す。

---

# MVP

Version 1では以下の機能のみ実装する。

- ログイン
- ダッシュボード
- 顧客管理
- 電子カルテ
- 写真管理

MVP完成までは機能追加を行わない。

---

# Subscription

サブスクリプション課金（Stripe）でプランを管理し、プランごとに使える機能を切り替える。

| プラン | 月額（税込） | 使える機能 |
|---|---|---|
| Lite | 980円 | 顧客管理 / カルテ管理 / 写真管理 |
| Standard | 1,980円 | Lite + 予約管理 / Googleカレンダー連携 / LINE連携 |
| Pro | 3,980円 | Standard + AI要約 / 高度な分析 |

カード情報は Stripe がホストする Checkout 画面で入力され、アプリには一切保存しない。

## Stripe Mode

| 環境 | APP_ENV | Stripe |
|---|---|---|
| DEV | local | Test Mode（`sk_test_` / Test Mode の Price ID） |
| PRODUCTION | production | Live Mode（`sk_live_` / Live Mode の Price ID） |

DEV と本番でキー・Price ID・Webhook Secret をすべて分ける。
取り違えは決済 API を呼ぶ手前で例外として止まる。

## Local Setup

1. `backend/.env` に Stripe の **Test** キーと **Test Mode** の Price ID を設定する

```
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_PRICE_LITE=price_...
STRIPE_PRICE_STANDARD=price_...
STRIPE_PRICE_PRO=price_...
```

2. 設定と Live/Test の整合を確認する

```
php artisan stripe:check
```

3. Webhook を Stripe CLI でローカルへ転送し、表示された `whsec_...` を `STRIPE_WEBHOOK_SECRET` に設定する

```
stripe listen --forward-to localhost:8000/api/webhooks/stripe
```

## Documents

- 仕様: [docs/subscription.md](docs/subscription.md)
- Stripe の設定と運用: [docs/stripe.md](docs/stripe.md)
- 設計判断: [docs/decisions/ADR-029-subscription-billing.md](docs/decisions/ADR-029-subscription-billing.md)

---

# Target

対象業種

- 美容室
- 理容室
- ネイルサロン
- エステサロン
- アイラッシュサロン
- リラクゼーションサロン

---

# Goal

このプロジェクトはRealizeWorksの基盤となるプロダクトとして開発する。

- 小規模サロンでも導入しやすい
- シンプルで使いやすい
- AIによって業務効率を向上させる
- 将来的にはSaaS化を目指す

---

# License

Copyright © RealizeWorks