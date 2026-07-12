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
- Vitest

---

## 実装（2026-07-13 追記）

`.github/workflows/ci.yml` に GitHub Actions を構築。push(main) と全 PR で実行する。

- **backend ジョブ**: PHP 8.3 + PostgreSQL サービス。`composer install` →
  Pint フォーマットチェック（`pint --test`）→ `php artisan test`
- **frontend ジョブ**: Node 22。`npm ci` → `type-check` → `lint` →
  `test:unit`（Vitest）→ `build`

テストランナーは PHPUnit を採用（当初案の Pest は未導入）。既存テストは
すべて PHPUnit クラス形式。

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