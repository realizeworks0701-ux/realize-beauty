# ADR-013: CI/CD

## Status

Accepted

---

## Date

2026-06-26

---

## Context

品質を維持するため、自動化されたCI/CD環境を構築する。

---

## Decision

GitHub Actionsを利用する。

---

## CI

- Lint
- Format Check
- PHPUnit
- Pest
- Vitest

---

## CD

将来的にLaravel CloudまたはVPSへ自動デプロイする。

---

## Consequences

### Advantages

- 品質維持
- 自動テスト
- デプロイミス防止

### Disadvantages

- 初期設定が必要

---

## References

- docs/standards/git.md