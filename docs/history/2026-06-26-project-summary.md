# Project Summary (2026-06-26)

## Purpose

本ドキュメントは、本日の設計・意思決定・進捗をまとめたものである。

会話の内容を後から振り返ることを目的とする。

---

# Project Vision

Realize Beautyは美容サロン向け業務支援システムである。

対象業種

- 美容室
- エステ
- ネイル
- アイラッシュ
- リラクゼーション

小規模店舗から複数店舗まで利用できるSaaSを目指す。

---

# Tech Stack

Backend

- Laravel 12

Frontend

- Vue 3
- TypeScript
- PrimeVue

Database

- PostgreSQL

Authentication

- Laravel Sanctum

Storage

- Cloudflare R2

AI

- OpenAI API

CI/CD

- GitHub Actions

Source Control

- GitHub

---

# Architecture

採用したアーキテクチャ

- API First
- RESTful API
- SPA
- Repository Pattern
- Service Layer
- Multi Tenant
- Role Based Access Control

---

# Documentation

作成済み

- PROJECT
- MVP
- ERD
- API Endpoints
- Wireframe
- CONTRIBUTING
- AGENTS

---

# ADR

作成済み

- ADR-001 API First
- ADR-002 Multi Tenant
- ADR-003 RBAC
- ADR-004 PostgreSQL
- ADR-005 Cloudflare R2
- ADR-006 OpenAPI
- ADR-007 Documentation Driven Development
- ADR-008 Repository Pattern
- ADR-009 Service Layer
- ADR-010 Git Workflow
- ADR-011 Frontend Architecture
- ADR-012 Testing Strategy
- ADR-013 CI/CD
- ADR-014 AI Development Guidelines
- ADR-015 Coding Standards

---

# Database

ERD Version

v2.0

主な設計

- salon_idによるマルチテナント
- SoftDelete
- role
- AI Summary
- Photo
- 将来Reservation対応

---

# API

設計完了

- Authentication
- Dashboard
- Customers
- Records
- Photos

OpenAPI作成前の状態まで完了。

---

# Git

SSH設定完了。

GitHubアカウント切替完了。

Repository接続完了。

---

# Development Policy

Documentation Driven Development

設計

↓

レビュー

↓

修正

↓

Git Commit

↓

実装

---

# Current Status

Phase 1

Project Design

Completed ✅

次に着手する内容

Phase 2

OpenAPI Specification

---

# Notes

今回の設計では「AIが理解しやすい設計」を最優先とした。

コードより設計書を先に作成する文化を採用する。

OpenAPIをSingle Source of Truthとする。

すべての設計変更はADRおよび関連ドキュメントへ反映する。