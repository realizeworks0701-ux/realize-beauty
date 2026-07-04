# ADR-019: Customer・Record・Photo API実装

## Status

Accepted

## Context

Realize Beauty のバックエンドにおいて、顧客管理・カルテ管理・写真管理のAPIを実装する必要があった。

また、OpenAPIによるAPI設計を採用し、API仕様と実装の整合性を維持することを目的とした。

## Decision

以下の方針で実装を行う。

* Controller / Service / Repository の3層構成を採用
* OpenAPIをSingle Source of Truthとして管理
* APIレスポンスはResourceクラスへ集約
* バリデーションはFormRequestで管理
* RepositoryがDBアクセスを担当
* Serviceが業務ロジックを担当
* カルテブロック更新はRepository内で同期処理を実装
* 写真はLaravel Storageのpublicディスクへ保存
* PhotoはSoftDeleteを採用
* Laravel Sanctumによる認証を利用

## Consequences

### メリット

* Controllerが薄く保守しやすい
* DBアクセスをRepositoryへ集約できる
* OpenAPIとの整合性を保ちやすい
* API仕様変更時の影響範囲が明確
* 今後AI要約機能やフロントエンド実装へ拡張しやすい構成となった

### デメリット

* レイヤー数が増えるため、小規模機能では実装量が増える
* Repository・Serviceの責務を継続的に意識する必要がある
