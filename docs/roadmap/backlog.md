# Product Backlog

## In Progress (v0.3 Web予約・LINE連携・フェーズ2)

> フェーズ1（予約コア）は完了。backlog の「Web予約・LINE予約（予約フェーズ2）」「LINE連携」を v0.3 進行中へ移動（設計: docs/requirements/booking.md）

- Web予約ページ（公開予約・キャンセル）
- LINE連携（サロン別チャネル接続・連携コードによる顧客紐付け）
- LINE通知（予約確定 push・前日リマインダー）

---

## High Priority

- OpenAPI作成
- Laravel Migration
- 認証実装
- 顧客CRUD
- カルテCRUD

---

## Medium Priority

- AIカルテ要約
- 写真アップロード
- ダッシュボード

---

## Low Priority

- Googleカレンダー同期（予約フェーズ3）
- 指名なし予約の自動割当の公平化（現状は id 最小固定）
- リマインダー時刻のサロン別設定（現状は 18:00 JST 固定）
- 予約ページURLのQRコード生成
- booking_slug の再生成（ローテーション）機能（当面はサポートによる手動更新で対応）
- 公開予約の電話番号所有確認（SMS 認証等。なりすまし・虚偽予約対策 — booking.md / ADR-024 の残存リスク参照）
- LIFF / LINEミニアプリの再検討（ADR-024 で不採用）
- HotPepper連携調査
- 売上分析
