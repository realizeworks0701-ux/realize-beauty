# ADR-006: OpenAPI Specification

## Status

Accepted

---

## Date

2026-06-26

---

## Context

API仕様書と実装の乖離を防ぎ、AIによるコード生成を活用したい。

---

## Decision

OpenAPI 3.1を採用する。

API仕様は `docs/api/openapi.yaml` を唯一の仕様書とする。

---

## Alternatives Considered

### Markdownのみ

人には読みやすいが、自動生成に利用できない。

---

## Consequences

### Advantages

- Swagger UIで確認できる
- TypeScript型生成が可能
- APIクライアント生成が可能
- AIによる実装精度が向上する

### Disadvantages

- メンテナンスが必要

---

## References

- docs/api/endpoints.md
- docs/api/openapi.yaml