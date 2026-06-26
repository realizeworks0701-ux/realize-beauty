# Contributing Guide

Realize Beautyへの参加ありがとうございます。

このドキュメントでは、開発ルール・Git運用・AI活用方針について説明します。

---

# Development Philosophy

Realize Beautyは以下の思想で開発します。

- Documentation First
- API First
- MVP First
- Laravel Way
- AI Assisted Development

コードを書く前に設計書を作成し、
設計を元に実装を進めます。

---

# Required Documents

実装前に必ず以下のドキュメントを確認してください。

- docs/PROJECT.md
- docs/requirements/MVP.md
- docs/db/ERD.md
- docs/api/endpoints.md
- docs/ui/wireframe.md
- docs/standards/

仕様が不明な場合は、まずドキュメントを更新してから実装してください。

---

# Git Workflow

基本ブランチ

main

機能開発

feature/xxxx

修正

fix/xxxx

ドキュメント

docs/xxxx

リファクタリング

refactor/xxxx

---

# Commit Message

例

feat: 顧客登録APIを追加

fix: カルテ保存時のバリデーション修正

docs: ERD更新

refactor: CustomerService整理

chore: パッケージ更新

---

# Pull Request

PRでは以下を記載してください。

- 目的
- 変更内容
- 動作確認
- 影響範囲

---

# Coding Rules

Laravel標準に従います。

- Fat Controller禁止
- Serviceへビジネスロジックを書く
- RepositoryでDBアクセス
- FormRequestでValidation
- API Resourceでレスポンスを返す

---

# Frontend Rules

Vue3 Composition API

TypeScript

PrimeVue

状態管理はPiniaを利用します。

---

# Database Rules

- 外部キーを設定する
- SoftDeletesを利用する
- salon_idを必ず持つ（共有マスタを除く）
- PostgreSQLを前提とする

---

# API Rules

REST APIを採用します。

例

GET /customers

POST /customers

PUT /customers/{id}

DELETE /customers/{id}

レスポンス形式は統一します。

成功

{
  "data": {}
}

一覧

{
  "data": [],
  "meta": {}
}

---

# AI Development

本プロジェクトではAIを積極的に活用します。

利用ツール

- ChatGPT
- Cline
- GitHub Copilot

AIへの指示は必ず設計書を参照してください。

---

# Documentation

仕様変更時は必ず以下も更新してください。

- ERD
- API
- Wireframe
- Roadmap

コードだけを変更しないこと。

---

# Branch Protection

mainブランチへ直接コミットしません。

featureブランチで開発し、
レビュー後にマージします。

---

# Goal

Realize Beautyは

「小規模美容サロンが本当に使いやすい業務システム」

を目指します。

機能を増やすことよりも、
使いやすさ・保守性・品質を優先してください。

---

# Issue Workflow

Issueは以下の種類で管理します。

- feat: 新機能
- fix: 不具合
- docs: ドキュメント
- refactor: リファクタリング
- chore: その他

例

feat: 顧客管理API

feat: AIカルテ要約

fix: 写真アップロードエラー

docs: API仕様更新