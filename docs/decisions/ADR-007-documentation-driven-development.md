# ADR-007: Documentation Driven Development

## Status

Accepted

---

## Date

2026-06-26

---

## Context

Realize BeautyではAIを活用した開発を前提とする。

AIはコードだけでは設計意図を理解できないため、設計書を先に整備する必要がある。

また、将来的な保守や新規参加者への引き継ぎも考慮する。

---

## Decision

Documentation Driven Development（DDDoc）を採用する。

実装前に以下のドキュメントを作成・更新する。

1. PROJECT
2. MVP
3. ERD
4. API Endpoints
5. OpenAPI
6. Wireframe

設計書を唯一の正しい情報源（Single Source of Truth）とし、仕様変更時はコードより先にドキュメントを更新する。

---

## Alternatives Considered

### Code First

コードを先に実装する。

→ 設計と実装が乖離しやすく、AIの生成品質も低下する。

---

## Consequences

### Advantages

- AIとの相性が良い
- 保守しやすい
- ドキュメントが資産になる
- 新規参加者が理解しやすい

### Disadvantages

- 初期設計に時間がかかる

---

## References

- docs/PROJECT.md
- docs/requirements/MVP.md
- docs/db/ERD.md
- docs/api/endpoints.md
- docs/ui/wireframe.md