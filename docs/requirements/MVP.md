# MVP (Minimum Viable Product)

## Project

Realize Beauty

---

## Purpose

美容サロンの業務効率を向上させるシンプルなWebアプリケーションを提供する。

---

## Vision

美容業界における日々の事務作業をAIで効率化し、
施術に集中できる環境を提供する。

---

## Target

- 美容室
- 理容室
- ネイルサロン
- エステサロン
- アイラッシュサロン
- リラクゼーションサロン

---

# MVP Features

## Authentication

- ログイン
- ログアウト
- パスワード変更

---

## Dashboard

- 当月KPI（新規顧客数・予約数・売上・リピート率）（ADR-026）
- 売上推移（ADR-026）
- 本日の来店予約（ADR-026）
- 人気メニュー（ADR-026）
- 顧客セグメント（ADR-026）
- AIからのお知らせ（将来）

---

## Customer

- 顧客一覧
- 顧客登録
- 顧客編集
- 顧客削除

---

## Medical Record

- カルテ一覧
- カルテ登録
- カルテ編集
- 写真添付
- AI要約

---

## Photo

- 写真アップロード
- 写真一覧
- 写真削除

---

## AI

- カルテ要約
- 来店履歴要約
- 次回来店提案（将来）

---

# Out of Scope

MVPでは実装しない

- LINE連携
- Web予約
- スタッフ管理
- 在庫管理
- POS
- 決済

この一覧は MVP の範囲を記録したものであり、現在の機能一覧ではない。後続フェーズで実装した項目も削除せず残す。

- LINE連携・Web予約は v0.3 の予約フェーズ2で実装済み（[booking.md](booking.md) / [ADR-024](../decisions/ADR-024-line-integration.md)）
- 決済は v1.0 でサブスクリプション課金として実装済み（[ADR-029](../decisions/ADR-029-subscription-billing.md)）。対象はサロンが Realize Beauty を利用するための月額利用料（Stripe Checkout / カスタマーポータル）であり、**施術代金の決済（POS・キャッシュレス決済）は引き続きスコープ外**とする