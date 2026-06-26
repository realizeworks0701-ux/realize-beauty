# ADR-009: Service Layer

## Status

Accepted

---

## Date

2026-06-26

---

## Context

ビジネスロジックをControllerへ記述すると肥大化し、保守性が低下する。

---

## Decision

ビジネスロジックはService Layerへ実装する。

ControllerはServiceを呼び出すのみとする。

---

## Responsibilities

Controller

- Request
- Validation
- Response

Service

- Business Logic
- Transaction
- AI Integration

Repository

- Database Access

---

## Alternatives Considered

Fat Controller

→ 可読性・保守性が低下するため採用しない。

---

## Consequences

### Advantages

- Controllerがシンプルになる
- AIとの連携が容易
- 再利用しやすい

### Disadvantages

- クラス数が増える

---

## References

- docs/standards/backend.md