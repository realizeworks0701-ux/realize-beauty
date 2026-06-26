# ADR-015: Coding Standards

## Status

Accepted

---

## Date

2026-06-26

---

## Context

コード品質を統一するため、コーディング規約を定める。

---

## Decision

命名規則・フォーマット・責務分離を徹底する。

---

## Rules

### Naming

- Class：PascalCase
- Method：camelCase
- Variable：camelCase
- Table：snake_case
- Column：snake_case

---

### Principles

- Single Responsibility Principle
- DRY
- KISS
- YAGNI

---

### Formatting

Backend

Laravel Pint

Frontend

ESLint

Prettier

---

## Consequences

### Advantages

- 可読性向上
- 保守しやすい
- AIがコード生成しやすい

### Disadvantages

- 規約を守る必要がある

---

## References

- docs/standards/backend.md
- docs/standards/frontend.md