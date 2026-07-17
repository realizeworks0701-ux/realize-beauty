# Web予約・LINE連携（Booking）要件書

## Project

Realize Beauty — 予約管理 フェーズ2（ROADMAP v0.3）

---

## Purpose

顧客が電話をかけずに、サロンごとの公開Web予約ページから
スマートフォンで予約を完結できるようにする。

あわせてサロンのLINE公式アカウントと連携し、
予約確定通知・前日リマインダーを自動化することで無断キャンセルを減らす。

---

## Background

- フェーズ1（予約コア）でメニュー管理・営業時間・予約CRUD・予約カレンダーが完成した
- 顧客からの予約受付は電話のみで、営業時間外の予約機会を逃している
- 小規模サロンの顧客接点は実質LINEに集約されており、リマインダーはLINE配信が最も届く
- 顧客予約導線は「公開Web予約ページ + LINE連携」とする。LIFF/LINEミニアプリ・トーク内対話予約・Module channel は採用しない（[ADR-024](../decisions/ADR-024-line-integration.md)）
- サロンごとの公開予約URL（`/booking/{booking_slug}`）をLINEリッチメニュー・Instagram・Googleマップ等に貼って運用する

---

## フェーズ分割

| フェーズ | 内容 | 本書の対象 |
|---------|------|-----------|
| フェーズ1 | 予約コア＝メニュー管理・営業時間・予約CRUD・サロン側予約カレンダー・ダッシュボード「今日の予約」（完了） | 対象外 |
| フェーズ2 | 公開Web予約ページ・LINE連携（サロン別チャネル接続）・連携コードによる顧客紐付け・前日リマインダー | ✓ |
| フェーズ3 | Googleカレンダー双方向同期＝OAuth接続（スタッフ別 / サロン共有）・送信同期・受信同期・外部予定の busy 反映（詳細は [google-calendar.md](google-calendar.md) / [ADR-025](../decisions/ADR-025-google-calendar-sync.md)） | 対象外 |

---

## Scope

### In Scope（フェーズ2）

1. サロン設定: LINE連携（認証情報の登録・接続確認・解除）
2. サロン設定: Web予約ページのURL表示（コピー）
3. 公開Web予約ページ（認証なし・モバイルファースト）: メニュー選択 → スタッフ選択（指名なし可）→ 日時選択（空き枠）→ 顧客情報入力 → 確認 → 完了（LINE連携案内）
4. 予約キャンセルページ（booking_token 付きURL）
5. LINE webhook（follow / message.text=連携コード / unfollow）
6. 前日リマインダー push（毎日 18:00 JST、翌日の reserved 予約、連携済み顧客のみ）
7. Web予約確定時、連携済み顧客には確定 push を1通

### Out of Scope（実装しない）

- LIFF / LINEミニアプリ・トーク内対話予約・Module channel（[ADR-024](../decisions/ADR-024-line-integration.md)）
- Googleカレンダー同期（フェーズ3）
- メール通知
- 予約の顧客側変更（キャンセル→再予約で代替する）
- QRコード生成
- リマインダー時刻のサロン別設定（18:00 JST 固定）
- 顧客アカウント / 顧客ログイン
- サロンへのキャンセル通知

---

# User Stories

- 顧客として、サロンから案内されたURLを開き、スマホでメニュー・日時を選んで予約したい
- 顧客として、担当を決めずに「指名なし」で空いている枠に予約したい
- 顧客として、都合が悪くなったら予約完了時のURLから自分でキャンセルしたい
- 顧客として、前日にLINEで予約のリマインドを受け取りたい
- オーナーとして、自サロンのLINE公式アカウント（Messaging API）を画面から接続したい
- オーナーとして、Web予約ページのURLをコピーしてリッチメニューやInstagramに貼りたい
- サロンスタッフとして、Web経由の予約もこれまでどおり予約カレンダーで確認したい

---

# 画面フロー概要

## 公開Web予約（顧客・認証なし）

```
/booking/{booking_slug}
  メニュー選択
    → スタッフ選択（「指名なし」可）
    → 日時選択（30分刻みの空き枠から選択）
    → 顧客情報入力（氏名・かな・電話番号）
    → 確認
    → 完了（LINE友だち追加ボタン + 連携コードを案内）
```

## 予約キャンセル（顧客・認証なし）

```
/booking/cancel/{booking_token}
  予約概要の表示 → キャンセル実行 → キャンセル完了
```

## LINE連携（顧客）

```
友だち追加（完了画面のボタン）
  → トークで連携コードを送信
  → 本人紐付け完了の reply
  → 以後、予約確定 push / 前日リマインダー push を受信
```

## サロン設定（管理側・Sanctum 認証）

```
設定 > LINE連携
  チャネル認証情報の入力・保存 → 接続確認（成功で有効化）→ 状態表示 / 連携解除
設定 > Web予約
  予約ページURLの表示・コピー（LINE連携ページ内への同居可。画面設計で決定する）
```

画面設計は `docs/ui/` 配下の該当設計書を正とする。

---

# Functional Requirements

## LINE 連携設定（サロン管理側）

- Messaging API のチャネル認証情報（channel_id / channel_secret / channel_access_token）を画面から登録する
- 認証情報はサロンごとにDBへ**暗号化保存**する（データ駆動方式。サロンごとのコード追加・デプロイは行わない）
- 「接続確認」で LINE の bot 情報取得APIを呼び、成功時に bot_user_id / bot_basic_id / bot_display_name を保存して連携を有効化する。失敗時は 422。bot_display_name は設定取得（GET）・接続確認レスポンスに含め、設定画面で常時表示する
- **接続確認で検証できるのは channel_access_token のみ**であり、channel_secret の正しさは実際の webhook 受信（署名検証成功）でしか確認できない。このため署名検証成功時のみ `line_settings.last_webhook_at` を更新し、設定画面に「最終Webhook受信」として表示する。設定手順ガイドには「設定後にトークへテストメッセージを送り、最終Webhook受信が更新されることを確認する」を含める
- 既存設定の channel_secret / channel_access_token を変更する保存（PUT）では is_active を false に戻し、再度「接続確認」を要求する（未検証の認証情報のまま「接続済み」表示で稼働することを防ぐ）
- 登録済みの channel_secret / channel_access_token は**マスク表示**（末尾4桁のみ）とする
- webhook URL（`{APP_URL}/api/line/webhook`。全サロン共通）を画面に表示し、LINE Developers への設定を案内する
- 連携解除で設定を物理削除する。あわせて当該サロンの顧客の line_user_id / line_linked_at / line_link_code / line_link_code_expires_at を**一括クリア**する（LINE の userId はチャネルのプロバイダー単位スコープのため、別チャネルで再接続しても旧 userId は無効。残置すると宛先不明の push が失敗し続ける）。UI の確認ダイアログにこの影響を明記する

## Web 予約ページ URL

- サロンごとに16文字英数小文字ランダムの `booking_slug` を持ち、公開URL `/booking/{booking_slug}` を管理画面で表示・コピーできる
- 既存サロンにもマイグレーションで booking_slug を生成する。新規サロンは Salon モデルの creating フックで自動生成する（unique 衝突時はリトライ）
- booking_slug の再生成（ローテーション）はスコープ外（backlog）。公開URLが spam ボット等に収集・悪用された場合は、当面サポートによる手動更新で対応する

## 公開 Web 予約

- 認証なしでサロン名・営業時間・有効メニュー一覧・有効スタッフ一覧（id, name のみ）を取得できる。営業時間は行が存在しない曜日をデフォルト「09:00〜19:00 営業」で補完した7曜日分を返す（フェーズ1の BusinessHourService と同一ルール）
- `salons.is_active=false` のサロンは公開APIすべてで 404 とする（booking_slug の検索条件に is_active=true を含める）
- 日付・メニュー（・スタッフ）を指定して30分刻みの空き枠を取得できる。スタッフ省略時は「指名なし」＝誰か1人でも空いていれば予約可とする
- 予約を確定するとフェーズ1と同じ予約レコード（status=reserved）が作成され、`reservations.source='web'` で記録される。サロン側の予約カレンダーにそのまま表示される
- 予約確定時にキャンセルURL用の `booking_token`（`Str::random(32)`＝英数大小32文字・unique）を発行し、完了画面に表示する
- 未連携顧客の予約完了画面には「友だち追加ボタン + 連携コード」を表示する（連携済み顧客には表示しない）

### バリデーション（決定事項）

| Field | Rule |
|--------|------|
| menu_id | 必須・対象サロンの is_active なメニューに存在すること |
| user_id | 任意（null=指名なし）・指定時は対象サロンの is_active なスタッフに存在すること |
| start_at | 必須・ISO 8601 日時（オフセット付き。フェーズ1と同じ date_format 検証）。加えて下記の枠検証を行う |
| name | 必須・string・最大100文字 |
| kana | 必須・string・最大100文字 |
| phone | 必須・string・最大20文字（顧客マッチングに使用） |

### サーバ側の枠検証（決定事項・違反はすべて 422）

UI を介さない直接のAPI呼び出しでも不正な枠の予約が通らないよう、POST 時に空き枠計算と**同一ロジック**で再検証する。

1. start_at が該当曜日の営業時間内（欠損曜日はデフォルト 09:00〜19:00 の補完込み）かつ open_time 起点の30分グリッド上にあること
2. `start_at + menu.duration_minutes <= close_time` を満たすこと
3. 現在時刻+30分 以降、かつ salon_timezone の日付で本日+60日後の終日までであること（availability と POST で同一判定）
4. 対象スタッフに重複予約がないこと（advisory lock 経由。指名なしは Business Rules 3 を参照）
5. 同一サロン内で同一 phone（正規化後）の未来の status=reserved 予約が既に3件ある場合は新規作成不可（虚偽予約による枠占拠の緩和）

同一 phone の同時リクエストは salon_id+正規化 phone の advisory lock（`booking-phone:{salonId}:{phone}`）で直列化する（上限バイパス・重複顧客作成の防止。取得順は phone → スタッフ）。

422 のエラーキー割当（決定事項）: 顧客情報のエラーは name / kana / phone キー（同一 phone の未来予約上限超過も phone キー）、時間帯系（枠埋まり・営業時間外・グリッド外・範囲外）は start_at キーでサーバメッセージを返す。UI は start_at 系エラーでサーバメッセージを表示し、空き枠を再取得して日時選択ステップへ戻す。

なお管理側（`/api/v1`）の予約APIは従来どおり営業時間外の登録も許容する（[ADR-023](../decisions/ADR-023-reservation-core.md) の決定を維持。変更しない）。

## 予約キャンセル（顧客側）

- booking_token 付きURLから予約概要（日時・メニュー・担当）を確認し、キャンセルできる
- キャンセルは `now < start_at`（等号は不可）の場合のみ可能。status=reserved を WHERE 条件に含む**条件付き UPDATE** で実装し、更新0件（キャンセル済み・来店済み・過去）はすべて 409 エラーとする（サロン側 PATCH との同時実行でも一貫性を保つ）
- 顧客側の予約「変更」は提供しない（キャンセル→再予約で代替）

## LINE Webhook（顧客紐付け）

- 全サロン共通の1エンドポイント。リクエストボディの `destination`（bot user ID）でサロンを特定し、そのサロンの channel_secret で署名検証する
- 未知の destination は**署名計算前に即 200** を返す（DB照会1回のみ・ログ記録）。署名検証はキュー投入前に**同期**で実施し、検証済みイベントのみキューへ投入する
- 本人紐付けは**ワンタイム連携コード**方式とする（Messaging API の account link 機能は使わない。顧客はログインアカウントを持たないため）

### 連携コードのライフサイクル（決定事項）

- **発行**: Web予約完了時、対象顧客の line_user_id が null の場合のみ発行する。**毎回新規生成して上書き**し、旧コードは即失効する
- **形式**: 6文字・曖昧文字除外（A-Z, 2-9 から I / O を除く）。生成時に unique 衝突した場合はリトライする
- **有効期限**: 72時間（`customers.line_link_code_expires_at` に期限を保持する）
- **照合**: destination で特定した**サロン内の顧客に限定**し（`WHERE salon_id = ?`）、line_user_id IS NULL かつ期限内のコードのみ照合対象とする。既に line_user_id を持つ顧客のコードは照合不成立とする（上書きによる紐付けの乗っ取り不可）。照合時は前後の空白を除去し大文字化して比較する
- **成立時**: line_user_id / line_linked_at を保存し、line_link_code / line_link_code_expires_at を null にクリアする（**単回使用**）

### イベント処理

- イベント処理はキュー経由で行う
  - **follow**: 挨拶 reply（連携コードの案内文。コード未発行の場合は「次回のWeb予約時に連携コードが発行される」旨を含む）
  - **message（text）**: 上記ライフサイクルに従い連携コードを照合する
    - 成立したら `customers.line_user_id` を保存して確認 reply を返す。**確認 reply には予約の日時等を含めない**（「連携が完了しました。予約前日にリマインダーをお送りします」程度に留める）
    - 送信者の LINE ユーザーが同一サロン内で既に別顧客と連携済みの場合は保存せず「このLINEアカウントは既に連携済みです。変更はサロンへお問い合わせください」と reply する（事前チェックにより部分 unique index (salon_id, line_user_id) の制約違反を回避する）
    - 不一致（期限切れ・連携済み顧客のコードを含む）の場合は reply しない（誤爆防止）
  - **unfollow**: destination で特定した**サロン内**の該当顧客（line_user_id 一致）の line_user_id / line_linked_at を null に戻す
- **reply 送信ジョブは tries=1** とする（replyToken は短命・単回使用のためリトライは無意味。失敗はログ記録のみとし、DB更新〈line_user_id 保存〉は reply 送信とは独立に確定させる）

## 通知（確定 push・前日リマインダー）

- Web予約確定時、サロンの LINE 連携が有効（line_settings が存在し is_active=true）かつ連携済みの顧客には確定 push を1通送信する
- 前日リマインダーは artisan コマンド（`reservations:send-reminders`）を毎日 18:00 JST にスケジュールして送信する
- リマインダー対象: サロンの LINE 連携が有効（line_settings が存在し is_active=true）で、翌日（JST）の status=reserved かつ customer.line_user_id あり かつ reminder_sent_at が null の予約
- 送信成功で `reservations.reminder_sent_at` を記録する（再送防止）
- リマインダージョブは予約単位で一意（`ShouldBeUnique`）とし、ジョブ完了前のコマンド再実行（手動再実行・障害復旧）による二重投入・二重送信を防ぐ
- リマインダージョブは送信直前に status=reserved・customer.line_user_id あり・reminder_sent_at が null であることを再確認し、失効していればスキップする（18:00 以降にキャンセルされた予約へは送信しない）
- push 送信はキュージョブ経由。**tries=3・バックオフ付き**とする。ただし LINE API の 429（レート制限・月間上限到達の双方）は恒久エラーとしてリトライせず即打ち切り・ログ記録する（月間上限は月内に回復しないため）

---

# Business Rules

1. **空き枠計算**: salon_timezone（`config('app.salon_timezone')`）基準で、business_hours の open〜close を **open_time を起点に**30分刻みで走査する（09:15 開店なら 09:15, 09:45, …）。`start + menu.duration_minutes <= close_time` かつ対象スタッフに重複予約がない枠を空きとする（`cancelled` / `no_show` は重複扱いしない — フェーズ1の overlap ロジックを再利用）。business_hours の行が存在しない曜日はフェーズ1と同じ「09:00〜19:00 営業」のデフォルト補完を適用し（既存 BusinessHourService 相当の補完済みデータを空き枠計算・公開サロン情報の双方で使用する）、is_closed=true の曜日のみ休業扱いとする
2. **予約可能範囲**: 現在時刻+30分 以降 〜 salon_timezone の日付で本日+60日後の**終日まで**（日付境界で判定し、availability と予約作成 POST で同一判定とする）
3. **指名なし予約の自動割当**: トランザクション内で該当枠が空いている有効スタッフを **id 昇順に走査**し、候補ごとに advisory lock（`reservation:{salonId}:{userId}`）を取得 → 重複再チェック → 空いていれば割当を確定する。全候補が埋まっていた場合のみ 422 とする（id 昇順の順序付き取得のためデッドロックは発生しない。取得済みロックはトランザクション終了で解放される）。割当の公平化は backlog とする
4. **二重予約防止**: フェーズ1の advisory lock（`reservation:{salonId}:{userId}`）を公開予約でも**同じ Service 経由で**通す。サロン側手動予約とWeb予約の同時登録でもダブルブッキングは発生しない
5. **顧客マッチング**: phone は入力・比較の双方で**正規化**（ハイフン・空白の除去、全角→半角）したうえで、同一サロン内で完全一致（未削除）の顧客がいれば既存顧客に紐付ける（name / kana は上書きしない）。複数一致した場合は id 最小の顧客に紐付ける（決定的）。一致しなければ新規顧客を作成する。同姓同名・電話番号変更等による重複リスクは許容する
   - **なりすまし脅威の注記**: 電話番号の所有確認を行わないため、他人の電話番号で予約するとその顧客の連携コードが完了画面に表示され、第三者が自分の LINE を紐付けるなりすましが理論上可能。連携コードの TTL・単回使用・上書き不可・reply 内容の最小化（LINE Webhook 節）で緩和する。残存リスク（未連携顧客に限られ、実予約の作成が必要でサロンのカレンダーから可視）の許容判断は [ADR-024](../decisions/ADR-024-line-integration.md) に記録し、将来対策（SMS 認証等）は backlog とする。あわせて、予約作成レスポンスの `line` フィールドが null か否かで当該電話番号の顧客の連携有無が判別できる点も既知の残存リスクとして注記する
6. **キャンセルポリシー**: 顧客キャンセルは `now < start_at`（等号は不可）の場合のみ可能（キャンセル料・期限の概念はフェーズ2では持たない）。status=reserved を WHERE 条件に含む条件付き UPDATE で実装し、更新0件（キャンセル済み・来店済み・過去）はすべて 409 とする
7. **リマインダー仕様**: 毎日 18:00 JST に翌日分をまとめて送信する。時刻のサロン別設定はスコープ外（backlog）。未連携顧客には送信しない（メール等の代替手段は持たない）
8. **push 無料枠の注意**: LINE公式アカウントの無料プラン（コミュニケーションプラン）は月200通まで。確定 push + リマインダー push で予約1件あたり最大2通を消費するため、Web予約が月100件を超える規模のサロンは有料プランを検討する必要がある。要件として明記し、サロンへの案内事項とする（[ADR-024](../decisions/ADR-024-line-integration.md)）

---

# Non-Functional Requirements

1. **公開APIのレート制限（throttle）**: 認証なしの公開APIには throttle を必須とする。サロン情報・空き枠取得は 60/min/IP、予約作成は 10/min/IP に加えてサロン（booking_slug）単位 30/min の上限を設ける。LINE webhook は throttle なし（代わりに署名検証必須。未知の destination は署名計算前に即 200 を返し、リクエストあたりの処理を DB 照会1回のみに抑える）
2. **認証情報の暗号化保存**: channel_secret / channel_access_token は Laravel の `encrypted` cast でDBに暗号化保存する。APIレスポンス・画面では末尾4桁のみのマスク表示とし、平文は返さない
3. **webhook 署名検証**: `x-line-signature` ヘッダを raw body に対する HMAC-SHA256 で検証する。署名検証はキュー投入前に同期で実施し、検証済みイベントのみキューへ投入する。未知の destination や検証失敗でもレスポンスは 200 を返す（LINE のリトライ暴走防止。ログには残す）
4. **LINE API クライアント**: 公式 SDK は使わず Laravel HTTP クライアントで実装する（必要APIは reply / push / bot info の3つのみ。依存最小化。[ADR-024](../decisions/ADR-024-line-integration.md)）
5. **虚偽予約への緩和と残存リスク**: 電話番号の実在検証（SMS 認証等）はスコープ外のため、throttle（IP・サロン単位）と「同一 phone の未来の reserved 予約は最大3件」の上限（バリデーション節）で緩和する。分散 IP 等による虚偽予約・枠占拠の残存リスクは許容し、サロン側は予約カレンダーからの手動キャンセルで対処する（SMS 認証は backlog）

---

# API Requirements

エンドポイントの詳細は [docs/api/endpoints.md](../api/endpoints.md) / [docs/api/openapi.yaml](../api/openapi.yaml) を正とする。

## 管理側（`/api/v1`・auth:sanctum）

- GET / PUT / DELETE `/line-settings`（設定取得〈マスク表示〉・保存・連携解除）
- POST `/line-settings/verify`（接続確認。成功で有効化、失敗は 422）
- GET `/booking-page`（booking_slug と公開URL）

## 公開側（`/api/public/v1`・認証なし・throttle 必須）

- GET `/salons/{booking_slug}`（サロン名・営業時間・有効メニュー・有効スタッフ）
- GET `/salons/{booking_slug}/availability?date=&menu_id=[&user_id=]`（30分刻みの空き枠）
- POST `/salons/{booking_slug}/reservations`（予約作成 → booking_token 等を返す）
- GET `/bookings/{booking_token}` / POST `/bookings/{booking_token}/cancel`（キャンセルページ用）

## LINE webhook

- POST `/api/line/webhook`（認証なし・throttle なし・署名検証必須）

---

# UI Requirements

画面設計は以下を正とする。

- [docs/ui/public-booking.md](../ui/public-booking.md) — 公開Web予約ページ（/booking/:slug）・予約キャンセルページ（/booking/cancel/:token）。認証ガードなし・PublicLayout（サイドバーなし・モバイルファースト）・ステップ式フォーム
- [docs/ui/settings-line.md](../ui/settings-line.md) — LINE連携設定（/settings/line）。認証情報入力・接続確認・状態表示（bot名・最終Webhook受信）・webhook URL 表示・予約ページURLコピー
- 予約カレンダーへの source バッジ（Web予約）表示は任意の小改修

---

# Out of Scope（再掲・フェーズ3以降の予告）

フェーズ2では実装しない。

- LIFF / LINEミニアプリ・トーク内対話予約・Module channel（採用しない。[ADR-024](../decisions/ADR-024-line-integration.md)）
- Googleカレンダー同期（フェーズ3）
- メール通知
- 予約の顧客側変更
- QRコード生成
- リマインダー時刻のサロン別設定
- 顧客アカウント / 顧客ログイン
- サロンへのキャンセル通知

---

# References

- [docs/requirements/reservation.md](reservation.md)（フェーズ1要件）
- [docs/db/ERD.md](../db/ERD.md)
- [docs/api/endpoints.md](../api/endpoints.md)
- [docs/decisions/ADR-024-line-integration.md](../decisions/ADR-024-line-integration.md)
- [docs/roadmap/ROADMAP.md](../roadmap/ROADMAP.md)
