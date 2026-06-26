# ADR-008: Repository Pattern

## Status

Accepted

---

## Date

2026-06-26

---

## Context

データアクセスをControllerやServiceへ直接記述すると責務が混在し、保守性が低下する。

---

## Decision

Repository Patternを採用する。

Repositoryはデータアクセスのみを担当する。

ServiceはRepositoryを利用し、ビジネスロジックを実装する。

---

## Responsibilities

### Repository

- Database Access
- Query
- CRUD

### Service

- Business Logic
- Transaction
- Domain Rules

### Controller

- Request
- Response

---

## Alternatives Considered

EloquentをControllerから直接呼び出す。

→ 保守性が低くなるため採用しない。

---

## Consequences

### Advantages

- 責務が明確になる
- テストしやすい
- AIが理解しやすい

### Disadvantages

- クラス数が増える

---

## References

- docs/standards/backend.md