# ADR-003: Role Based Access Control

## Status

Accepted

---

## Date

2026-06-26

---

## Context

複数スタッフで利用することを想定し、将来的な権限制御が必要となる。

---

## Decision

ユーザーへroleカラムを追加する。

利用するロール

- owner
- manager
- staff

MVPでは全員同一権限とし、将来Role Middlewareを導入する。

---

## Alternatives Considered

権限制御を実装時に追加する。

→ Migration変更が発生する。

---

## Consequences

### Advantages

- 将来の拡張が容易
- 権限制御が実装しやすい

### Disadvantages

- MVPでは未使用カラムになる

---

## References

- docs/db/ERD.md
- docs/api/endpoints.md