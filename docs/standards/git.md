# Git Standards

正典は [ADR-010](../decisions/ADR-010-git-workflow.md)。
ブランチと環境の対応は [ADR-031](../decisions/ADR-031-two-environment-deployment.md)。

## Branch

```
feature/* → develop → main
```

main

本番環境へデプロイされる。常にリリース可能な状態を維持する。直接Pushしない。

develop

develop 環境（デモ兼先行検証）へデプロイされる。日常の統合先。

feature/*

新機能。`develop` から切り、`develop` へ戻す。

fix/*

バグ修正

docs/*

ドキュメント修正

refactor/*

リファクタリング

chore/*

その他

---

## Commit

feat:

fix:

docs:

refactor:

style:

test:

chore:

---

## Pull Request

developへマージ

本番へは develop → main のPR

レビュー必須

Squash Merge
