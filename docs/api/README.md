# API ドキュメント

このディレクトリでは、Realize Beauty の API に関する仕様を管理します。

---

## ディレクトリ構成

```
api/
├── README.md
├── endpoints.md      # API概要
├── openapi.yaml      # OpenAPI本体
├── redocly.yaml      # Redocly設定
├── schemas/          # スキーマ定義
└── examples/         # リクエスト・レスポンス例
```

---

## 基本方針

Realize Beauty は **OpenAPI First** で開発します。

OpenAPI を API仕様の唯一の正（Single Source of Truth）とし、実装は OpenAPI に従って行います。

APIを追加・変更する場合は、必ず OpenAPI を更新してから実装します。

---

## 各ファイルの役割

### endpoints.md

人が読みやすい API 一覧・概要を記載します。

### openapi.yaml

OpenAPI 3.1 に準拠した API仕様書です。

### schemas/

APIで利用する共通スキーマを管理します。

### examples/

リクエスト・レスポンスのサンプルを管理します。

### redocly.yaml

Redocly の設定ファイルです。

---

## 開発フロー

要件定義

↓

ERD

↓

OpenAPI

↓

Laravel 実装

↓

Vue 実装

↓

テスト

↓

リリース

API仕様を起点として開発を進めることで、ドキュメントと実装の整合性を維持します。
