# Database Standards

## Naming

テーブル

複数形

例

customers

records

photos

---

## Primary Key

id

---

## Foreign Key

customer_id

record_id

---

## Timestamp

created_at

updated_at

deleted_at

---

## Rule

論理削除を基本とする。

Migrationですべて管理する。

SQLを直接変更しない。