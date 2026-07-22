# Product Backlog

## In Progress (v0.3 Googleカレンダー双方向同期・フェーズ3)

> フェーズ2（Web予約・LINE連携）は完了。backlog の「Googleカレンダー同期（予約フェーズ3）」を v0.3 進行中へ移動（設計: docs/requirements/google-calendar.md / ADR-025）

- Google OAuth 接続・解除（スタッフ別 / サロン共有の両モード）
- 送信同期（予約 作成・変更・キャンセル → Google イベント。接続時の既存予約の書き出しを含む）
- 受信同期（push 通知 + syncToken 増分同期。410 Gone と日次の同期窓前進で全同期）
- 外部予定の busy ブロック化と空き枠・公開予約への反映
- 定期コマンド（watch チャネルの張り直し・同期窓の日次前進）
- トークン更新・失効時の再接続導線

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

- Google OAuth 審査の申請（公開ドメイン上のプライバシーポリシー・Search Console ドメイン認証・OAuth同意画面のデモ動画・スコープ正当性説明。未審査でも100ユーザー〈プロジェクト生涯・リセット不可〉まで動作するため実装のブロッカーではない — google-calendar.md / ADR-025 参照）
- 複数カレンダーの同時読み取り（現状は 1接続 = 1カレンダー）
- Google 側で新規作成された外部予定の予約への昇格（現状は busy 止まり）
- Outlook / iCloud 等の他カレンダー対応
- 指名なし予約の自動割当の公平化（現状は id 最小固定）
- リマインダー時刻のサロン別設定（現状は 18:00 JST 固定）
- 予約ページURLのQRコード生成
- booking_slug の再生成（ローテーション）機能（当面はサポートによる手動更新で対応）
- 公開予約の電話番号所有確認（SMS 認証等。なりすまし・虚偽予約対策 — booking.md / ADR-024 の残存リスク参照）
- LIFF / LINEミニアプリの再検討（ADR-024 で不採用）
- HotPepper連携調査
- 売上分析
