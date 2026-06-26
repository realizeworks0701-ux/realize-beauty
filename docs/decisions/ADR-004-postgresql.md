# ADR-004: PostgreSQL Adoption

## Status

Accepted

---

## Date

2026-06-26

---

## Context

本プロジェクトは長期運用を前提とする。

---

## Decision

データベースはPostgreSQLを採用する。

---

## Alternatives Considered

### MySQL

Laravel標準で利用されることが多い。

→ PostgreSQLの方が拡張性・SQL機能に優れるため採用しなかった。

---

## Consequences

### Advantages

- 高い信頼性
- 豊富なSQL機能
- 将来的な分析にも対応しやすい

### Disadvantages

- MySQLより学習コストがやや高い

---

## References

- docs/db/ERD.md