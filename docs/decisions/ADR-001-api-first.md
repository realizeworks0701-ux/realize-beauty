# ADR-001: API First Development

## Status

Accepted

---

## Date

2026-06-26

---

## Context

Realize BeautyはVue.jsとLaravelを分離したSPA構成を採用する。

また、ChatGPT・Cline・GitHub CopilotなどのAIを活用しながら開発を進める。

フロントエンドとバックエンドを並行開発できる構成が望ましい。

---

## Decision

API First Developmentを採用する。

実装前にAPI仕様を設計し、OpenAPIを唯一のAPI仕様書として管理する。

実装はAPI仕様に従って行う。

---

## Alternatives Considered

### Backend First

Laravel実装後にVueを実装する。

→ フロントエンドとの認識ズレが発生しやすい。

### Frontend First

画面から作成する。

→ API仕様が曖昧になり保守性が低下する。

---

## Consequences

### Advantages

- フロントエンド・バックエンドを並行開発できる
- AIによるコード生成との相性が良い
- API仕様がドキュメントとして残る
- 保守しやすい

### Disadvantages

- 初期設計に時間がかかる

---

## References

- docs/PROJECT.md
- docs/api/endpoints.md
- docs/api/openapi.yaml