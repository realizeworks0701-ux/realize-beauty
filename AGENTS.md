# Realize Beauty AI Development Guide

このプロジェクトでは以下のドキュメントを必ず参照してください。

- docs/requirements/MVP.md
- docs/db/ERD.md
- docs/api/endpoints.md
- docs/ui/wireframe.md
- docs/roadmap/ROADMAP.md
- docs/standards/README.md

実装前に設計を確認し、仕様が不明な場合は質問してください。

Controllerにはビジネスロジックを書かず、
Service・Repositoryパターンを採用してください。

Laravel標準に従い、
RESTful APIで実装してください。

MVP外の機能は実装しないでください。

# Development Rules

- 必ず docs 配下の設計書を優先する
- 実装前に requirements → ERD → API → UI の順で確認する
- 不明点があれば推測で実装せず質問する
- Laravelのベストプラクティスに従う
- Vue3 Composition APIを使用する
- TypeScriptではanyを極力使用しない
- コードには必要以上のコメントを書かず、命名で意図を表現する
- 新しい機能を追加した場合は、必要に応じて docs を更新する

# Priority

優先順位は以下の通り。

1. docs/requirements
2. docs/db
3. docs/api
4. docs/ui
5. docs/roadmap
6. docs/standards

設計書と実装が矛盾する場合は、設計書を優先する。
仕様が曖昧な場合は質問する。

# ADR (Architecture Decision Record)

設計判断は必ず docs/decisions に ADR として記録してください。

- 新しい技術・方針を採用した場合は ADR を追加する
- ADR を追加・更新した場合は /memories/repo/adr-summary.md も更新する
- AI は adr-summary.md を設計判断の材料として参照する
- ADR のテンプレートは docs/decisions/TEMPLATE.md を使用する

---

Roleカラムは将来の権限制御のために予約済みです。

MVPで使用しなくても削除・変更しないでください。
