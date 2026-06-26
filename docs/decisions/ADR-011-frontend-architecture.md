# ADR-011: Frontend Architecture

## Status

Accepted

---

## Date

2026-06-26

---

## Context

Realize BeautyはSPA（Single Page Application）として開発する。

保守性・再利用性・AIによるコード生成を考慮し、統一されたフロントエンド構成が必要となる。

---

## Decision

Vue 3 + TypeScriptを採用する。

画面・コンポーネント・状態管理・API通信を責務ごとに分離する。

ディレクトリ構成はFeature Firstを基本とする。

---

## Tech Stack

- Vue 3
- TypeScript
- PrimeVue
- Vue Router
- Pinia

---

## Consequences

### Advantages

- 保守性が高い
- AIが理解しやすい
- コンポーネント再利用が容易

### Disadvantages

- 初期構成がやや複雑になる

---

## References

- docs/standards/frontend.md