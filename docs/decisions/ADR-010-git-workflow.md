# ADR-010: Git Workflow

## Status

Accepted

---

## Date

2026-06-26

---

## Context

複数人開発やAIとの共同開発では、統一されたGit運用が必要となる。

---

## Decision

GitHub Flowをベースに運用する。

---

## Branch Strategy

main

常にデプロイ可能な状態を維持する。

feature/*

新機能開発

fix/*

バグ修正

docs/*

ドキュメント修正

refactor/*

リファクタリング

chore/*

その他

---

## Commit Message

feat:

新機能

fix:

不具合修正

docs:

ドキュメント

refactor:

リファクタリング

test:

テスト

chore:

その他

---

## Pull Request

PRには以下を記載する。

- 目的
- 変更内容
- 動作確認
- 影響範囲

---

## Rules

- mainへ直接Pushしない
- 設計変更時はドキュメントも更新する
- コードレビューを行う
- ADR更新が必要か確認する

---

## Consequences

### Advantages

- 履歴が分かりやすい
- AIが理解しやすい
- 品質を維持できる

### Disadvantages

- 小規模開発でも運用ルールが増える

---

## References

- CONTRIBUTING.md
- docs/standards/git.md