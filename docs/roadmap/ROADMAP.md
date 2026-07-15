# Roadmap

> Realize Beauty Development Roadmap

---

# Version 0.1 - MVP

## Authentication

- [x] ログイン
- [x] ログアウト
- [x] Sanctum認証

---

## Dashboard

- [x] ダッシュボード
- [ ] 今日の売上
- [x] 最近の顧客

---

## Customer

- [x] 顧客一覧
- [x] 顧客登録
- [x] 顧客編集
- [x] 顧客削除

---

## Medical Record

- [x] カルテ一覧
- [x] カルテ登録
- [x] カルテ編集
- [x] AI要約

---

## Photo

- [x] 写真アップロード
- [x] 写真削除

---

# Version 0.2

## AI

- [x] AIカルテ要約
- [ ] 来店履歴要約
- [ ] AIによる次回来店提案

---

## Analytics

- [ ] 売上グラフ
- [ ] 人気メニュー
- [ ] 来店頻度分析

---

# Version 0.3

## Reservation Core（フェーズ1・完了）

> 設計: [docs/requirements/reservation.md](../requirements/reservation.md) / [ADR-023](../decisions/ADR-023-reservation-core.md)

- [x] メニュー管理
- [x] 営業時間設定
- [x] 予約CRUD（カレンダー・変更・キャンセル）
- [x] ダッシュボード「今日の予約」

---

## Reservation（フェーズ2・進行中）

> 設計: [docs/requirements/booking.md](../requirements/booking.md) / [ADR-024](../decisions/ADR-024-line-integration.md)
> LINE予約（ミニアプリ）は採用せず backlog（LIFF/ミニアプリの再検討）へ移動

- [ ] Web予約ページ
- [ ] LINE連携（サロン別チャネル接続）
- [ ] 連携コードによる顧客紐付け
- [ ] LINE通知（予約確定 push・前日リマインダー）

---

## Reservation（フェーズ3）

- [ ] Googleカレンダー同期

---

## Notification

> LINE通知（予約確定 push・前日リマインダー）はフェーズ2の項目へ統合済み

- [ ] メール通知

---

# Version 1.0

## SaaS

- [ ] マルチテナント
- [ ] サブスクリプション
- [ ] 管理画面
- [ ] テナント管理
