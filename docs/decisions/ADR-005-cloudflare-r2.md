# ADR-005: Cloudflare R2 Storage

## Status

Accepted

---

## Date

2026-06-26

---

## Context

カルテ写真を保存するためのオブジェクトストレージが必要となる。

---

## Decision

Cloudflare R2を採用する。

---

## Alternatives Considered

### Amazon S3

高機能だが転送料金が発生する。

---

## Consequences

### Advantages

- 転送料金が不要
- LaravelからS3互換で利用できる
- コストを抑えられる

### Disadvantages

- AWSサービスとの統合はS3ほど強くない

---

## References

- docs/PROJECT.md