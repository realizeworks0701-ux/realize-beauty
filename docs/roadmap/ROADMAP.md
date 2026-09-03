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
- [x] 今日の売上（ADR-026 で当月売上KPIに置換）
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

- [x] 売上グラフ（v0.1系 ダッシュボード刷新で前倒し。ADR-026）
- [x] 人気メニュー（v0.1系 ダッシュボード刷新で前倒し。ADR-026）
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

## Reservation（フェーズ2・完了）

> 設計: [docs/requirements/booking.md](../requirements/booking.md) / [ADR-024](../decisions/ADR-024-line-integration.md)
> LINE予約（ミニアプリ）は採用せず backlog（LIFF/ミニアプリの再検討）へ移動

- [x] Web予約ページ
- [x] LINE連携（サロン別チャネル接続）
- [x] 連携コードによる顧客紐付け
- [x] LINE通知（予約確定 push・前日リマインダー）

---

## Reservation（フェーズ3・進行中）

> 設計: [docs/requirements/google-calendar.md](../requirements/google-calendar.md) / [ADR-025](../decisions/ADR-025-google-calendar-sync.md)
> Googleカレンダー双方向同期。接続単位は「スタッフ別 / サロン共有1本」をサロンごとに選択
> Google 審査の申請作業はデプロイ時の手続き（未審査でも100ユーザーまで動作するため実装のブロッカーではない）

- [ ] Google OAuth 接続・解除（スタッフ別 / サロン共有）
- [ ] 対象カレンダーの選択（既定 primary）
- [ ] 送信同期（予約 作成・変更・キャンセル → Google イベント。接続時の既存予約の書き出しを含む）
- [ ] 受信同期（push 通知 + syncToken 増分同期。410 Gone と日次の同期窓前進で全同期）
- [ ] 外部予定の busy ブロック化と空き枠・公開予約への反映
- [ ] RB 由来イベントの移動・削除の取り込み（競合時は RB を真実として巻き戻し）
- [ ] 定期コマンド（watch チャネルの張り直し・同期窓の日次前進）
- [ ] トークン更新・失効時の再接続導線
- [ ] 予約カレンダーへの「外部予定」表示

---

## Notification

> LINE通知（予約確定 push・前日リマインダー）はフェーズ2の項目へ統合済み

- [ ] メール通知

---

# Version 1.0

## SaaS

> サブスクリプション課金は完了: [ADR-029](../decisions/ADR-029-subscription-billing.md)
> Stripe Checkout / カスタマーポータルによる契約と、プラン（Lite / Standard / Pro）別の機能制御
> プラン内容の管理画面・トライアル・年額プランは backlog へ

- [ ] マルチテナント
- [x] サブスクリプション
- [ ] 管理画面
- [ ] テナント管理
