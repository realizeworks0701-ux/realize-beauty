# Backend Standards

## Architecture

Controller

↓

Service

↓

Repository

↓

Model

---

## Rule

Controllerにはロジックを書かない。

RepositoryはDB操作のみ。

Serviceはビジネスロジックのみ。

ValidationはFormRequestで行う。

エラーメッセージは `backend/lang/ja/validation.php` で日本語化している（ADR-030）。
FormRequestごとに `messages()` は書かず、項目名は同ファイル末尾の `attributes` に集約する。
**FormRequestに項目を追加したら `attributes` にも日本語名を1行追加する。**

Resourceでレスポンスを返す。

Fat Controllerは禁止。

---

# Authorization

認証はLaravel Sanctumを利用する。

権限制御はRole Middlewareで実装する。

Role

- owner
- manager
- staff

MVPでは権限制御を簡略化し、
認証済みユーザーのみアクセス可能とする。