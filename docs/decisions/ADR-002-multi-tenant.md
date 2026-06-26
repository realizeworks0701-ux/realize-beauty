# ADR-002: Multi Tenant Architecture

## Status

Accepted

---

## Date

2026-06-26

---

## Context

Realize Beautyは個人サロンだけでなく、複数店舗を持つ美容室・エステ・ネイルサロンへの展開を想定する。

---

## Decision

店舗単位を表す `salon_id` を基本テーブルへ保持する。

対象テーブル

- users
- customers
- records
- menus
- reservations

---

## Alternatives Considered

### Single Tenant

店舗情報を持たない。

→ 将来SaaS化が困難になる。

---

## Consequences

### Advantages

- SaaS化しやすい
- 複数店舗へ対応できる
- データ分離が容易

### Disadvantages

- クエリにsalon_id条件が増える

---

## References

- docs/db/ERD.md