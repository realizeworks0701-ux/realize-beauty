# ADR-024: サロンごとの LINE 公式アカウント連携（フェーズ2）

## Status

Accepted

---

## Date

2026-07-15

---

## Context

フェーズ2（ADR-023 のフェーズ分割）で、顧客向けの Web 予約と LINE 通知
（予約確定・前日リマインダー）を提供する。

小規模サロンは各自が LINE 公式アカウントを運用しており、通知はサロン自身の
アカウント名義で届く必要がある。マルチテナント SaaS として、
「サロンごとの Messaging API チャネルをどう扱うか」
「LINE ユーザーと customers をどう紐付けるか」
「顧客向け予約 UI をどの形態で提供するか」を決める必要がある。

また、Messaging API のチャネル認証情報（channel_secret / チャネルアクセストークン）は
なりすまし送信に直結する秘密情報であり、保存方法を明文化しておく必要がある。

---

## Decision

### 1. データ駆動マルチテナント方式（認証情報を DB に暗号化保存）

サロンごとの Messaging API チャネル認証情報を `line_settings` テーブルに保存する。
サロン追加はデータ登録のみで完結し、サロンごとのコード追加・デプロイは一切行わない。

- `channel_secret` / `channel_access_token` は Laravel の **encrypted cast** で暗号化保存する
- 暗号鍵は `APP_KEY` のため、**APP_KEY をローテーションする場合は
  `APP_PREVIOUS_KEYS` に旧キーを設定する（Laravel のグレースフルローテーション）か、
  全レコードの再暗号化が必要**。運用手順にこの注意を残す
- API レスポンスでは secret / token を末尾4桁のみのマスク表示とし、平文を返さない
- secret / token を変更する `PUT /api/v1/line-settings` は `is_active` を false に戻し、
  再度の接続確認を要求する（未検証の認証情報のまま「接続済み」表示で
  稼働させない）
- 連携解除（`DELETE /api/v1/line-settings`）は設定レコードを物理削除し、
  あわせて当該サロンの顧客の `line_user_id` / `line_linked_at` /
  `line_link_code` / `line_link_code_expires_at` を**一括クリアする**。
  LINE の userId はチャネルの**プロバイダー単位のスコープ**を持ち、
  解除後に別チャネル（別プロバイダー）で再接続すると保存済みの userId が
  すべて無効になるため（残置すると確定 push・リマインダーが全件失敗する）。
  UI の解除確認ダイアログにもこの影響（全顧客の再連携が必要）を明記する

### 2. Webhook は全サロン共通の1エンドポイント（destination で振り分け）

`POST /api/line/webhook` の1本のみを LINE 側に登録する。

- リクエストボディの `destination`（bot user ID）で `line_settings.bot_user_id` を照合し、
  サロンを特定する
- 特定したサロンの `channel_secret` で署名検証する
  （HMAC-SHA256、`x-line-signature` ヘッダ、raw body に対して計算）
- 未知の destination や署名検証失敗でもレスポンスは 200 を返す
  （LINE 側のリトライ暴走を防ぐ。失敗はログに残す）
- `bot_user_id` / `bot_basic_id` / `bot_display_name` は接続確認時に
  `GET /v2/bot/info` から取得して保存する（bot_basic_id は友だち追加URLの
  生成に使用）
- 接続確認（bot info）で検証できるのは `channel_access_token` のみで、
  **`channel_secret` の正しさは実際の webhook 受信（署名検証成功）でしか
  確認できない**。このため署名検証成功時のみ `line_settings.last_webhook_at` を
  更新し、設定画面に「最終Webhook受信」として表示する
  （設定手順ガイドでテストメッセージ送信による確認を案内する）

### 3. 顧客紐付けはワンタイム連携コード

Web 予約完了画面に「友だち追加ボタン + 6文字の連携コード」を表示し、
顧客がトークでコードを送信 → webhook で照合して `customers.line_user_id` に保存する。

顧客はアカウント登録もログインも不要で、コードを1回送るだけで紐付けが完了する。

コードは以下の制約でワンタイム性を担保する:

- 有効期限 **72時間**・**単回使用**（照合成立時に `line_link_code` /
  `line_link_code_expires_at` をクリアする）
- 照合は destination で特定した**サロン内の未連携顧客
  （`line_user_id IS NULL`）に限定**する。既に連携済みの顧客のコードは
  照合不成立とし、上書き（＝乗っ取り）を許さない
- 送信者の LINE ユーザーが同一サロン内で既に別顧客と連携済みの場合は保存せず、
  「既に連携済み」の旨を reply する
- 連携完了の reply には予約の日時等を含めない（reply の情報最小化）

### 4. 予約 UI は公開 Web ページ（認証なし）

顧客向け予約はサロンごとの公開 URL `/booking/{booking_slug}` で提供する。
リッチメニュー・Instagram・Google マップ等、どの導線からでも同一 URL を貼れる。
認証なしのため公開 API には throttle を必須とする。

### 5. LINE API は Laravel HTTP クライアントで実装（公式 SDK 不使用）

必要な API は reply / push / bot info の3つのみのため、
ADR-021（OpenAI 連携）と同方針で `Http` クライアントで実装する。
追加依存なしで `Http::fake()` によるテストが可能。

---

## Alternatives Considered

### Module channel（マーケットプレイス連携）

サロンが OAuth のワンクリックで自アカウントを連携でき、
コピペ設定が不要になる理想形。ただし利用には**法人アカウント +
LINE マーケットプレイスでの有償公開が前提**であり、現状は利用できない。
将来、法人化とマーケットプレイス公開の条件が揃った時点で再検討する。

### LIFF / LINE ミニアプリ

LINE 内でネイティブに近い予約 UI を提供でき、userId も自動取得できるが、
**サロンごとに LINE Login チャネルの作成が必要**で初期設定の障壁が大きい。
また LIFF からミニアプリへの移行期にあたり仕様変動リスクがある。採用しない。

### トーク内対話予約（チャットボット形式）

追加 UI なしで完結するが、メニュー・スタッフ・空き枠の一覧性が悪く、
対話の途中状態の管理（離脱・やり直し）が複雑になる。採用しない。

### Messaging API の account link 機能

LINE 公式の紐付け機構だが、サービス側のログインアカウントと LINE アカウントを
接続する前提の仕組みであり、**顧客がログインアカウントを持たない**本サービスには
適合しない。採用しない。

### 公式 PHP SDK（line/line-bot-sdk）の導入

型付きクライアントが得られるが、必要 API が reply / push / bot info の3つのみで
過剰。`Http` クライアントで十分かつテスト容易なため採用しない（ADR-021 と同判断）。

---

## Consequences

### Advantages

- サロン追加・解除がデータ登録のみで完結し、コード変更・デプロイが不要
- 認証情報が暗号化保存され、DB ダンプ単体では平文が漏れない
- webhook が1本のため、LINE 側の設定案内と運用監視がシンプル
- 顧客にログインを求めず、連携コード送信のみで紐付けが完了する
- 追加パッケージなし・`Http::fake()` でテスト可能（ADR-021 と一貫）

### Disadvantages

- push の無料枠がコミュニケーションプランで **200通/月**。
  確定 push + リマインダー push で予約1件あたり最大2通のため、
  Web 予約が月100件規模になると上限に達する（超過時はサロン側で
  LINE のプランアップグレードが必要。要件にも明記する）
- サロンの初期設定でチャネル ID / secret / トークンのコピペ入力が必要
  （設定手順ガイドで緩和する）
- `APP_KEY` ローテーション時に暗号化カラムへの配慮
  （`APP_PREVIOUS_KEYS` 設定または再暗号化）が運用上必要になる
- LINE の userId は**プロバイダー単位のスコープ**を持つため、
  将来 LIFF（LINE Login チャネル）を追加する場合は、サロンの Messaging API
  チャネルと同一プロバイダー配下に作成しないと userId が一致しない
- 連携解除（設定の物理削除）を行うと当該サロンの**全顧客の LINE 連携が
  クリアされる**。再接続後は各顧客が連携コード送信による再連携を
  やり直す必要がある（同一チャネルへの再接続であっても、解除時点で
  クリア済みのため再連携が必要）
- **なりすまし脅威**: 顧客マッチングが phone 完全一致のため、他人の電話番号で
  Web 予約した第三者が、完了画面に表示される連携コードで当該顧客レコードに
  自分の LINE を紐付けられる余地がある。連携コードの制約
  （72時間 TTL・単回使用・連携済み顧客への上書き不可・reply に予約詳細を
  含めない）で緩和するが、**未連携顧客に限り残存リスクがある**。
  攻撃には実予約の作成が必要でサロンの予約一覧から可視のため追跡可能。
  SMS 認証等の本人確認強化は backlog とする

---

## References

- docs/requirements/reservation.md
- [docs/requirements/booking.md](../requirements/booking.md)
- [docs/ui/public-booking.md](../ui/public-booking.md)
- [docs/ui/settings-line.md](../ui/settings-line.md)
- docs/db/ERD.md
- docs/api/endpoints.md
- docs/roadmap/ROADMAP.md
- docs/decisions/ADR-021-openai-integration.md
- docs/decisions/ADR-023-reservation-core.md
