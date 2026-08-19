# Record List (Salon)

## 概要

サロン全体のカルテを横断して閲覧・検索する。サイドバーの「カルテ」から遷移する。

顧客ごとのカルテ一覧は [list.md](list.md)。

---

## Route

`/records`

---

## Components

### 絞り込み

* ステータス（Select: 下書き / 完了。未選択で全件）
* キーワード（InputText: 顧客の氏名・フリガナに部分一致）

未選択・空文字のパラメータは送らない。絞り込みを変更したらページを1に戻す。

### Record Table

| 列 | 内容 |
|------|------|
| 来店日 | visited_at |
| 顧客名 | customer.name（customer.kana を副次表示） |
| 担当 | user.name |
| ステータス | StatusTag（下書き / 完了） |

* 来店日の降順で表示する（並び替えの操作は持たない）
* 行クリックで Record Detail（/records/{id}）へ遷移する
* 論理削除済みの顧客のカルテは表示しない

### Pagination

* サーバのページネーションに従う（1ページ20件）

---

## API

GET /api/v1/records

---

## Actions

* 詳細

カルテの新規作成・削除の導線は持たない。いずれも顧客コンテキストが必須のため、[list.md](list.md) と [detail.md](detail.md) に置く。
