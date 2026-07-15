# Settings - LINE

## 概要

LINE公式アカウント（Messaging API）との連携を設定する（認証情報の登録・接続確認・連携解除・webhook URL の表示）。

設定画面（[settings.md](settings.md)）の「LINE連携」カードから遷移する。

Web予約ページURLの表示・コピーも本ページに同居させる（専用ページは作らない。予約導線の掲載とLINE連携は同じ運用文脈のため）。

---

## Route

`/settings/line`

---

## Components

### 接続状態カード

GET /api/v1/line-settings の結果に応じて3状態を表示する。

| 状態 | 表示 |
|------|------|
| 未設定 | 「LINE連携は未設定です」＋設定手順ガイドへの誘導 |
| 保存済み・未確認（is_active=false） | 「認証情報は保存済みです。接続確認を行ってください」（警告色 Tag） |
| 接続済み（is_active=true） | 「接続済み」（Primary Tag）＋ bot 名（bot_display_name）＋ bot basic ID（例: @123abcd）＋ connected_at |

* bot 名（bot_display_name）は接続確認時に GET /v2/bot/info の displayName から取得・保存され、GET /api/v1/line-settings のレスポンスに含まれるため常時表示する
* 接続済みのときは「最終Webhook受信」（last_webhook_at・署名検証成功時のみ更新）も表示する。null の場合は「未受信」と表示する。接続確認で検証できるのはアクセストークンのみで、Channel Secret の正しさは実際の webhook 受信でしか確認できないため、その確認の手がかりとなる

### 認証情報フォーム

| 項目 | UI | 備考 |
|------|----|------|
| Channel ID | InputText | 必須。保存値をプリフィル表示 |
| Channel Secret | Password | 必須。保存済みの場合は入力欄の下に「保存済み: ••••1234」（末尾4桁のみ）を表示 |
| チャネルアクセストークン（長期） | Password | 同上 |

* 保存済みの secret / token の全文は取得・表示できない（API がマスク値のみ返す）
* 再保存時は3項目すべてを入力して保存する（部分更新はしない）
* 保存ボタン → PUT /api/v1/line-settings。保存だけでは「接続済み」にはならず、secret / token を変更して保存すると接続状態は未確認（is_active=false）に戻る。**保存後に改めて「接続確認」が必要**な旨をフォーム下に注記する

### 接続確認

* 「接続確認」ボタン（認証情報が保存済みの場合のみ有効）→ POST /api/v1/line-settings/verify
* 成功: verify レスポンスの bot 名（bot_display_name）・bot basic ID を表示し、接続状態を「接続済み」に更新。成功 Toast
* 失敗（422）: 「接続に失敗しました。Channel ID・Secret・アクセストークンを確認してください」を Toast で通知。接続状態は変更しない

### Webhook URL

* 読み取り専用表示＋コピーButton（GET /api/v1/line-settings が返す `{APP_URL}/api/line/webhook`）
* 「LINE Developers の Messaging API 設定に登録してください」の説明を添える

### Web予約ページURL

* GET /api/v1/booking-page で取得した公開URL（`{APP_URL}/booking/{booking_slug}`）を読み取り専用表示＋コピーButton
* 「リッチメニュー・Instagram・Googleマップ等に掲載してください」の説明を添える
* このセクションは LINE 連携の設定状態に関わらず常に表示する（Web予約自体はLINEなしでも利用可能）

### 連携解除

* 「連携を解除」ボタン（Danger・接続済み or 保存済みの場合のみ表示）
* 確認ダイアログ: 「連携を解除すると保存済みの認証情報は削除され、LINEでの予約確定通知・前日リマインダーが停止します。また、すべてのお客様のLINE連携（連携済みアカウント・発行済み連携コード）も解除されます。よろしいですか？」
* 確定で DELETE /api/v1/line-settings → 未設定状態の表示に戻す
* サーバ側では当該サロンの顧客の line_user_id / line_linked_at / line_link_code / line_link_code_expires_at が一括クリアされる（LINE の userId はチャネルのプロバイダー単位のため、別チャネルで再接続しても旧連携は無効。再連携は各顧客のコード送信からやり直しになる）

### 設定手順ガイド

未設定・未確認のときにページ下部へ手順リストを表示する（接続済みのときは折りたたみ）。

1. LINE Official Account Manager でLINE公式アカウントを作成する（既存アカウントでも可）
2. LINE Official Account Manager の「設定 → Messaging API」から Messaging API を有効化する
3. LINE Developers コンソールで Channel ID・Channel Secret を確認し、チャネルアクセストークン（長期）を発行する
4. 上記3項目を本ページに貼り付けて「保存」→「接続確認」を行う
5. 本ページに表示される Webhook URL を LINE Developers の Messaging API 設定に登録し、Webhook の利用をONにする（あわせて応答メッセージ（自動応答）と、あいさつメッセージ（友だち追加時）もOFFを推奨。本システムが友だち追加時に連携コード案内を自動返信するため、ONのままだとメッセージが二重に届く）
6. 設定完了後、LINE公式アカウントのトークへテストメッセージを送信し、本ページの「最終Webhook受信」が更新されることを確認する（接続確認で検証できるのはアクセストークンのみで、Channel Secret の正しさは実際の webhook 受信でしか確認できないため）

---

## API

* GET /api/v1/line-settings（secret / token は末尾4桁のみのマスク値＋接続状態＋bot_display_name＋last_webhook_at＋webhook URL）
* PUT /api/v1/line-settings
* POST /api/v1/line-settings/verify
* DELETE /api/v1/line-settings
* GET /api/v1/booking-page

---

## Actions

* 認証情報の保存
* 接続確認
* Webhook URL のコピー
* Web予約ページURLのコピー
* 連携解除（確認ダイアログあり）

---

## エラー時挙動

* PUT の 422: フィールド単位でエラーメッセージを表示する
* 接続確認の失敗（422）: Toast で通知し、接続状態は変更しない
* 連携解除の失敗・その他のエラー: Toast で通知する
* 取得失敗時はエラーメッセージ＋再読み込みボタンを表示する
* 保存・接続確認・解除の成功は Toast で通知する

---

## UIイメージ

```text
LINE連携

+----------------------------------------------------------+
| 接続状態: [ 接続済み ]  サロン花 公式  @123abcd           |
| 接続日時: 2026/07/15  最終Webhook受信: 2026/07/15 12:34   |
+----------------------------------------------------------+

認証情報
  Channel ID          [ 1234567890            ]
  Channel Secret      [ ******************    ]  保存済み: ••••1234
  アクセストークン     [ ******************    ]  保存済み: ••••abcd

  ※保存後に「接続確認」を行うと連携が有効になります
                              [ 保存 ]  [ 接続確認 ]

----------------------------------------------------------

Webhook URL
  https://app.example.com/api/line/webhook       [コピー]
  LINE Developers の Messaging API 設定に登録してください

Web予約ページURL
  https://app.example.com/booking/a1b2c3d4e5f6g7h8  [コピー]
  リッチメニューやSNS・Googleマップに掲載してください

----------------------------------------------------------

                        [ 連携を解除 ]
```
