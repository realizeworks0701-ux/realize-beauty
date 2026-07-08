# Record Form

## 概要

カルテの登録・編集画面。

---

## Route

* /customers/{id}/records/create
* /records/{id}/edit

---

## Components

* 来店日
* ステータス
* カルテブロック
* 写真アップロード
* AI要約

---

## API

POST /api/v1/customers/{id}/records

PUT /api/v1/records/{id}

POST /api/v1/records/{id}/photos

DELETE /api/v1/photos/{photoId}

---

## Actions

* 保存
* 下書き保存
* AI要約
