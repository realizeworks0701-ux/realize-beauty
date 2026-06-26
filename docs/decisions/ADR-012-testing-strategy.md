# ADR-012: Testing Strategy

## Status

Accepted

---

## Date

2026-06-26

---

## Context

品質を維持するため、継続的なテストが必要となる。

---

## Decision

Backend

- Pest
- PHPUnit

Frontend

- Vitest

E2E

- Playwright（将来導入）

---

## Test Policy

優先順位

1. Service
2. Repository
3. API
4. UI

---

## Consequences

### Advantages

- 品質向上
- リファクタリングしやすい

### Disadvantages

- テスト作成コストが増える

---

## References

- docs/standards/backend.md