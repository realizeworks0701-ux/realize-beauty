# Architecture Decision Records (ADR)

このディレクトリでは、
重要な設計判断を記録する。

目的

- なぜその技術を採用したのか
- 将来の開発者へ理由を残す
- 設計変更の履歴を残す

---

命名規則

`ADR-<3桁連番>-<kebab-case-title>.md`

例

- ADR-001-api-first.md
- ADR-002-multi-tenant.md
- ADR-004-postgresql.md
- ADR-005-cloudflare-r2.md

連番はゼロ埋め3桁とし、既存の最大番号+1を採番する。

テンプレートは TEMPLATE.md を使用する。

---

一覧

全 ADR の要約は [memories/repo/adr-summary.md](../../memories/repo/adr-summary.md) にまとめてある。
ADR を追加・更新したら同ファイルも更新する（AGENTS.md の規定）。

最新は [ADR-031: develop 環境と本番環境の分離](ADR-031-two-environment-deployment.md)（2026-09-07）。
