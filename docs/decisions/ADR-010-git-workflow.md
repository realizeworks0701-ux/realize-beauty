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

**2026-09-07 更新**: デプロイ先が2環境になったため、`develop` を常設ブランチとして追加し、
`feature/* → develop → main` に改めた（[ADR-031](ADR-031-two-environment-deployment.md)）。
理由と経緯は下の「Note: ブランチ運用の変更」を参照。

---

## Branch Strategy

main

常にデプロイ可能な状態を維持する。**本番環境**へデプロイされる。

develop

日常の統合先。**develop 環境**（デモ兼先行検証）へデプロイされる。

feature/*

新機能開発。`develop` から切り、`develop` へ戻す。

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

- mainへ直接Pushしない（`main` への直接コミットは本番への直接デプロイを意味する）
- feature/* は `develop` へPRを出し、本番へは `develop → main` のPRで出す
- 設計変更時はドキュメントも更新する
- コードレビューを行う
- ADR更新が必要か確認する

---

## Note: ブランチ運用の変更（2026-09-07 追記）

本 ADR は当初「GitHub Flow、`develop` なし」を採用したが、`README.md` と `docs/standards/git.md` は
`main → develop → feature/*` と書いており、リポジトリ内で記述が矛盾していた。
デプロイ先が本番の1つしかないあいだは実害が無かったが、
[ADR-031](ADR-031-two-environment-deployment.md) で2環境に分けたことで、
**どのブランチがどこへ出るか**が運用の根幹になった。

`feature/* → develop → main` に統一し、`CONTRIBUTING.md` / `README.md` /
`docs/standards/git.md` を同じ形に揃えた。ブランチ名の規約（`feature/` `fix/` `docs/`
`refactor/` `chore/`）とコミットの接頭辞は変更していない。

---

## Consequences

### Advantages

- 履歴が分かりやすい
- AIが理解しやすい
- 品質を維持できる

### Disadvantages

- 小規模開発でも運用ルールが増える
- ブランチが1本増え、本番へ出すまでに PR が2回必要になる

---

## References

- CONTRIBUTING.md
- docs/standards/git.md
- [ADR-031](ADR-031-two-environment-deployment.md)（develop 環境と本番環境の分離）