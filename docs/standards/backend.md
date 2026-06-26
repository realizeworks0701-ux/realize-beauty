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