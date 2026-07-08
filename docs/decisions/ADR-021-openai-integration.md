# ADR-021: OpenAI 連携（AI要約）

## Status

Accepted

---

## Date

2026-07-08

---

## Context

MVP のコア機能として、カルテ内容の AI 要約を提供する（[docs/PROJECT.md](../PROJECT.md)）。
将来的には次回来店提案・ホームケア提案・キーワード抽出など、AI 機能の拡張を見込む。

AI 連携の責務が Controller や Service に散らばると、プロバイダ変更や機能追加時の
影響範囲が広がるため、責務を集約する構成が必要となる。

---

## Decision

OpenAI 連携の責務を **`OpenAIService`** に集約する。

- 呼び出しは `RecordController::summarize` → `RecordService::summarize` → `OpenAIService::summarizeRecord`
- `OpenAIService` は OpenAI の詳細（プロンプト・エンドポイント・モデル）を隠蔽し、
  他レイヤーは「テキストを渡すと要約が返る」以上を知らない
- 将来の AI 機能（提案生成・画像解析など）も本サービスへメソッドを追加する
- HTTP 通信は Laravel の `Http` クライアントを使用し、追加パッケージは導入しない
  （テストは `Http::fake()` でモックでき、外部依存を最小化できる）

### 要約仕様（MVP）

- 対象: カルテの**内容のあるテキストブロックのみ**（写真・日付・ステータス・空ブロックは対象外）
- タイミング: 「AI要約」ボタン押下時のみ生成・保存（自動生成しない）
- 保存先: `records.ai_summary`（毎回生成し直さずDBに保持。将来の検索にも利用可能）
- モデル: `gpt-4o-mini`（`OPENAI_MODEL` で変更可能）

### API

```
POST /api/v1/records/{id}/summarize
→ { "data": { "summary": "..." } }
```

- レスポンスキーは `summary`（[OpenAPI](../api/components/schemas/record.yaml) の `AiSummaryResponse` 準拠）
- DB カラムは `ai_summary`（[ERD](../db/ERD.md)）。両者は別レイヤーの命名として併存する
- テキストが無い場合は 422

---

## Alternatives Considered

### openai-php/laravel などのSDK導入

型付きクライアントが得られるが、MVP の単一エンドポイントには過剰。
`Http` クライアントで十分かつテスト容易なため採用しない。

### AIロジックを RecordService に直接記述

Service に OpenAI の詳細が漏れ、AI 機能追加のたびに肥大化するため採用しない。

---

## Consequences

### Advantages

- AI 連携の変更・拡張が `OpenAIService` に閉じる
- `Http::fake()` により外部APIなしでテスト可能
- 追加依存なし

### Disadvantages

- OpenAI API キー・課金・レート制限の運用管理が必要
- 外部API障害時は要約が失敗する（フロントはトーストで通知）

---

## References

- docs/PROJECT.md
- docs/api/endpoints.md
- docs/api/components/schemas/record.yaml
- docs/decisions/ADR-009-service-layer.md
- backend/app/Services/OpenAIService.php
