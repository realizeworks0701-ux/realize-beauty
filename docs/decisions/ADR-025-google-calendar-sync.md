# ADR-025: Googleカレンダー双方向同期（フェーズ3）

## Status

Accepted

---

## Date

2026-07-17

---

## Context

ADR-023 のフェーズ分割に従い、フェーズ1で予約コア（メニュー・営業時間・予約CRUD・
サロン側予約カレンダー）、フェーズ2で顧客向け Web 予約と LINE 連携（ADR-024）が完成した。
フェーズ3として Googleカレンダー同期を実装する。

現場の課題は**ダブルブッキング**である。スタッフは私用の予定（通院・子どもの行事・
副業・毎週のジム等）を自分の Google カレンダーで管理しており、それは RB には存在しない。
一方 RB の公開予約（フェーズ2）は 24時間いつでも顧客が枠を押さえられる。
結果として「その時間は空いていない」予定の上に予約が入る事故が起きる。

RB の予約を Google に書き出すだけでは、この課題は解決しない。
**Google 側の私用予定を RB が読み取り、空き枠を塞ぐ**ところまで到達して初めて価値が出る。
よって以下を決める必要がある。

- どの OAuth スコープを要求するか（および Google 審査との関係）
- 1接続がどのカレンダーを対象とし、読み書きをどう配置するか
- サロンごとの接続単位（スタッフ別 / サロン共有）の意味論
- 双方向同期のエコー（無限ループ）をどう防ぐか
- Google 側の変更をどう検知し、RB と競合したときにどちらを真実とするか
- 私用予定の内容をどこまで RB に保存するか（プライバシー）
- SPA（Cloudflare Pages）と API（Render）が別オリジンである構成で OAuth をどう成立させるか

---

## Decision

### 1. スコープは calendar.events + calendar.calendarlist.readonly（審査は実装のブロッカーではない）

要求するスコープは以下の2つとする。

- `https://www.googleapis.com/auth/calendar.events` — **機密スコープ**。
  対象カレンダー上のイベントの読み取りと書き込み。私用予定の読み取り（busy 化）と
  RB 予約イベントの作成・更新・削除の**両方**をこの1スコープで賄う
- `https://www.googleapis.com/auth/calendar.calendarlist.readonly` — カレンダー選択 UI 用。
  接続アカウントが持つカレンダーの一覧取得のみ

機密スコープのため、一般公開には Google の OAuth 審査が必要になる。
ただし**審査は実装のブロッカーではない**。未審査（In production・未確認アプリ）でも
**100ユーザーまで**動作するため、実装・検証・初期展開はそのまま進められる。

ここで OAuth 同意画面の **publishing status（公開ステータス）**は3状態あり、
**テストモード（Testing）と「In production かつ未確認」は別物**である。混同すると設計が壊れる。

| 状態 | ユーザー上限 | refresh_token の寿命 |
|---|---|---|
| **Testing**（テストモード） | 同意画面に列挙した**テストユーザー最大100人**（列挙した人のみ接続可・入れ替え可） | **7日で失効** |
| **In production・未確認** | **100ユーザー**（プロジェクト**生涯累計**・リセット不可） | 長寿命（失効・取消まで有効） |
| **In production・確認済み**（審査通過） | 無制限 | 長寿命（失効・取消まで有効） |

**前提条件（重要）: 開発・検証段階から publishing status を「In production（未確認）」にする。**
Google の OAuth2 公式ガイドは *"a Google Cloud Platform project with an OAuth consent screen
configured for an external user type and a publishing status of 'Testing' is issued a
refresh token expiring in 7 days"* と明記している。
例外は要求スコープが name / email / profile のサブセットのみの場合に限られ、
本設計の `calendar.events` は該当しないため、**Testing のままでは 7日ごとに全接続の
refresh_token が失効し、すべての接続が `needs_reconnect` に落ちて同期が毎週止まる**。
これは本設計のトークンモデル（長寿命 refresh_token を前提に、失効時のみ `needs_reconnect`）を
成立させない。「実装のバグ」と誤診しやすいため、前提条件としてここに明記する。

**「100ユーザー・生涯累計・リセット不可」は In production（未確認）の話**である
（ユーザーを削除しても枠は戻らない）。開発・検証で消費した枠は本番の枠を削るため、
検証用アカウントの数は管理する。

あわせて、**1 Google アカウント × 1 client_id あたり refresh_token は 100個が上限**であり、
超過すると**最古のトークンが無警告で失効する**。本設計は `prompt=consent` を毎回付けるため
再接続のたびに新しい refresh_token が発行される。サロン規模では現実的な影響は小さいが、
同一アカウントでの再接続を繰り返す検証時には留意する。

審査は**デプロイ時の手続き**として扱い、以下を前提・リスクとして記録する。

- 公開ドメイン上に到達可能なプライバシーポリシーを掲示すること
- Google Search Console で当該ドメインの所有権を認証すること
- OAuth 同意画面の実挙動を映したデモ動画を提出すること
- 各スコープの正当性（なぜ `calendar.events` が必要か）を説明すること
- 所要期間は**公式には最大10日**だが、**実務報告では 4〜6週間**かかる例がある。
  一般公開のスケジュールはこの前提で引く

審査申請そのものは本フェーズのスコープ外とする（デプロイ時の手続き）。

### 2. 1接続 = 1カレンダー（同一カレンダーに読み書き両方）

1つの接続は Google アカウントの**1カレンダー**を対象とする（既定 `primary`）。

**同一カレンダーに対して書き込みと読み取りの両方**を行う。

- 書き込み: RB の予約を当該カレンダーへイベントとして作成・更新・削除する
- 読み取り: 当該カレンダー上の **RB 以外の予定**を busy（多忙）として取り込む

接続後にカレンダーを選び直せる（`calendarList` から選択）。
ただし**専用カレンダー（RB 用に新規作成した空のカレンダー等）を選ぶと、
そこには私用予定が存在しないため busy 反映が働かない**。
これは「Google 側の予定が RB の空き枠を塞ぐ」という中核価値を失うトレードオフであり、
UI に明記する。既定かつ推奨は `primary`。

複数カレンダーの同時読み取りは行わない（1接続=1カレンダー）。

#### `primary` はエイリアス（バリデーションと表示）

`primary` は「そのアカウントのメインカレンダー」を指す**エイリアス**であり、実 id ではない。
`calendarList.list` が返すのは実 id（メインカレンダーの場合はアカウントのメールアドレス）である。
よって `calendar_id` のバリデーションは「**`primary`、または `calendarList` に存在し
`accessRole` が `writer` / `owner` の id**」とする
（列挙値を calendarList の実 id のみに限ると、既定値 `primary` が自らのバリデーションに違反する）。
読み取り専用（`reader` / `freeBusyReader`）のカレンダーを選ばせると `events.insert` が
403 で恒久失敗するため、選択肢からも除外する（`primary` は常に `owner` のため明示許可する）。

**カレンダーの表示名（`summary`）は保持しない**。`calendar_summary` に相当するカラム・
API フィールドは設けない。名称は Google 側でいつでも変更されうる複製データであり、
保持すれば陳腐化する。UI は `primary` を「メインカレンダー（{google_account_email}）」、
それ以外は `calendar_id` をそのまま表示する（実名称は「カレンダーを変更」ダイアログが
その場で引く `calendarList` の一覧で確認できる）。

#### `google_account_email` は calendarList の primary エントリから取得する

§1 の宣言スコープ（`calendar.events` + `calendar.calendarlist.readonly`）では
**アカウントのメールアドレスを取得できない**。`userinfo.email` / `openid` は追加しない
（表示用の1項目のために同意画面のスコープを増やし、審査対象を広げる価値がない）。
`calendarList` の **primary エントリの `id`**（＝アカウントのメールアドレス）を
表示用の `google_account_email` として保存する。

#### カレンダー変更と接続解除で Google 側イベントの扱いを分ける（意図的な非対称）

- **カレンダー変更（`PUT /connections/{id}`）→ 旧カレンダーの RB 由来イベントを削除する**。
  同一アカウント内での移し替えであり、新カレンダーへ初回送信同期で書き直す（§5）。
  旧カレンダーに孤児イベントを残すと、`reservations.google_event_id` が
  新カレンダーのイベントを指す一方で旧カレンダーにマーカー付きのイベントが残り、
  スタッフがそれを手で消したときに受信同期が**生きた予約を cancelled にする**事故経路になる
- **接続解除（`DELETE /connections/{id}`）→ Google 側のイベントは削除しない**。
  ただし対象範囲の予約の `google_event_id` は **null クリア**する（§8）

両者が非対称なのは意図である。カレンダー変更は「同じ予定を別の場所へ移す」操作であり、
移動元に実体が残ることをユーザーは期待しない。一方、接続解除は「RB と Google の連携をやめる」
操作であって「過去に入った予約の記録を消す」意思表示ではなく、
スタッフの手元カレンダーから施術予定が一斉に消えるのは破壊的である
（かつ解除は `needs_reconnect` 状態でも実行できる必要があり、その場合そもそも Google API を
呼べない。§8 のとおり Google 側の後始末は best-effort である）。
`google_event_id` を null クリアするのは、解除後に残った Google イベントを RB が
「自分が書いたもの」として参照し続けないためである（再接続時に古い ID で `events.update` を
撃たない）。

### 3. 2つのモード（per_staff / shared）の意味論

サロンごとに `salons.google_calendar_mode` で接続単位を選ぶ（null = 未設定）。

- **スタッフ別（`per_staff`）**: 各スタッフが自分の Google アカウントを接続する。
  RB は**そのスタッフ担当の予約のみ**当該カレンダーへ書く。
  カレンダー上の RB 以外の予定は**そのスタッフの**空き枠を塞ぐ
- **サロン共有（`shared`）**: オーナーが1アカウントだけ接続する。
  RB は**全スタッフの予約**をそのカレンダーへ書く（イベント題名に担当スタッフ名を含める）。
  カレンダー上の RB 以外の予定は**サロン全体（全スタッフ）の**空き枠を塞ぐ。
  店休・全体研修・イベント等を想定した意味論である

モード切替時は**既存の接続をすべて解除する**（意味論が変わるため、
既存接続を引き継ぐと busy の適用範囲が意図せず変わる）。UI の確認ダイアログに明記する。

### 4. エコー防止は extendedProperties.private のマーカー

双方向同期は「RB が書いた変更を Google から読み戻し、それをまた書く」ループに陥りうる。
これを RB 由来イベントの**自己識別**で防ぐ。

RB が作成する Google イベントには必ず以下を付与する。

- `extendedProperties.private.rb_reservation_id` = 予約ID
- `extendedProperties.private.rb_salon_id` = サロンID

RB 由来イベントの受信処理は、Google 側の時刻が RB の予約と一致していれば **no-op**
（書き戻さない）。これによりループが1周で収束する（no-op の厳密な条件と
staleness ガードは §6 で定める）。

`shared` ではなく `private` を使うのは、マーカーが**当該カレンダー上のイベントのコピーに
閉じる**ため（`shared` は他の出席者のコピーにも伝播する）。

#### マーカーは信頼できる入力ではない（RB 側の事実と突合する）

`extendedProperties.private` の `private` は「**当該カレンダー上のイベントのコピーに固有**」
という意味であり、「当該アプリからのみ見える」という意味では**ない**。
当該カレンダーへの書き込み権限を持つユーザー、および当該ユーザーが認可した任意の他アプリが、
`rb_reservation_id` / `rb_salon_id` を**自由に読み書きできる**。
マーカーだけを信頼して予約を更新・キャンセルすると、接続を1つ持つだけのスタッフが
自分のカレンダーに `rb_reservation_id = {他サロンの予約ID}` のイベントを作るだけで、
他サロンの予約を任意の時刻へ移動・キャンセルできてしまう
（`reservations.id` は連番のため列挙可能）。悪意がなくとも、Google カレンダー上で
イベントを**複製**すると `extendedProperties.private` は複製先にコピーされるため、
複製を動かすと本物の予約が動く。

よって**受信イベントを RB 由来と判定する条件を、マーカーではなくサーバ側事実との
突合として定義する**。生きているイベント（`status != 'cancelled'`）について:

1. `reservations` を
   `salon_id = {接続レコードの salon_id} AND google_event_id = {受信イベントの id}`
   で引き、**1件ヒットした場合のみ** RB 由来として扱う
2. `per_staff` モードではさらに `reservations.user_id = {接続レコードの user_id}` を要求する
3. 突合が成立しないイベントは、**マーカーの有無を問わず**（他人の／偽装／複製マーカーを含む）
   RB 由来として扱わず**外部予定（busy）として処理する**

突合の述語に `rb_reservation_id` を**含めない**のは、マーカーが改竄可能だからである。
これを必須条件にすると、スタッフが RB 由来イベントの `extendedProperties` を削除・改変した
だけで突合が外れ、`google_event_id` が一致する本物の予約イベントが外部予定として
busy 化され、自分自身の予約が二重に枠を塞ぐ。マーカーはログ・突合前の絞り込み・
整合性チェックのヒントに留める。

`rb_salon_id` はペイロード由来のため**権威にしない**（マーカー整合性チェックの用途に留める）。
テナント境界の判定は必ず**接続レコードの `salon_id`（per_staff では加えて `user_id`）**で行う。
すなわち**マーカーは改竄可能な入力であり、権威は RB 側の `reservations` 行にある**。
マーカーの役割はエコー防止のヒント（自己識別の候補提示）であって、認可ではない。

#### 受信同期の分岐は「削除か否か」を先に見る（2段階）

`syncToken` 増分同期が返す**削除済みイベントは `status: "cancelled"` の tombstone** であり、
Google 公式仕様は *"Deleted events are only guaranteed to have the `id` field populated"*
と明記している（繰り返しの取消インスタンスは `id` / `recurringEventId` /
`originalStartTime` のみ保証）。すなわち **`extendedProperties` も `start` / `end` も返らない**。
したがって tombstone にマーカー判定を適用すると必ず「マーカーなし」に落ち、
「Google 側で削除された → 予約を cancelled にする」が**原理的に発火しない**。
受信同期の分岐は次の2段階とする。

1. **`status == 'cancelled'`（tombstone）の場合**は本文を見ず、`event.id` をキーに引く
   - `reservations` を `(salon_id = {接続の salon_id}, google_event_id = event.id)` で引き、
     ヒットすれば予約を `status=cancelled` にする（`per_staff` では `user_id` 条件も加える）。
     **ただし対象予約の status が既に `cancelled` / `no_show` なら no-op**（§6 の status ガード）
   - ヒットしなければ `google_busy_blocks` を
     `(google_calendar_connection_id, google_event_id = event.id)` で削除する
   - どちらも無ければ no-op
2. **それ以外（生きているイベント）**のみ、上記のマーカー + サーバ側突合で
   RB 由来 / 外部予定を分岐する

`google_event_id` による照合はマーカーと違い**改竄できない**（イベントIDは Google が採番し、
RB 側の行は送信同期が自ら書いたもの）。削除時の判定はこれに依る。

### 5. push 通知（watch チャネル）+ syncToken 増分同期

Google 側の変更検知はポーリングではなく **push 通知**とする。

- 対象カレンダーに watch チャネルを張る。作成時に `id`（ランダム）・
  `token`（webhook 検証用の秘密値）・`address`（`{API_URL}/api/google/calendar/webhook`）を指定し、
  Google が返す `resourceId` / `expiration` を保存する
- push 通知は「変更があった」ことしか伝えない（差分の中身は来ない）。
  受信したら**増分同期ジョブを投入**する
- 増分同期は `events.list` に保存済み `syncToken` を渡して差分のみ取得し、
  応答の `nextSyncToken` を保存する
- `singleEvents=true` で繰り返し予定を実体に展開する（毎週のジム等を取りこぼさないため）

#### 同期窓は日付境界で定める（現在 〜 salon_timezone の本日+61日 00:00）

同期窓は salon_timezone 基準で「**現在 〜 本日+61日 00:00**」（＝**本日+60日の終日終端**）とし、
全同期の `timeMax` にこの終端を用いる。booking.md の予約可能範囲（「本日+60日後の**終日まで**」）と
一致させるためである。

「現在+60日」という**壁時計オフセットは採用しない**。7/17 15:00 JST に同期すると
`timeMax` = 9/15 15:00 となり、予約可能な最終日 9/15 の 15:00〜24:00 の外部予定が
同期範囲外＝busy 化されず、**その枠だけが公開予約で空きとして売られる**。
同期窓は予約可能範囲と同じ日付境界で定義しなければ意味を成さない。

#### 全同期の契機は4つ（syncToken は同期窓を運べない）

`syncToken` は `timeMin` / `timeMax` / `updatedMin` / `q` / `orderBy` /
`privateExtendedProperty` / `sharedExtendedProperty` / `iCalUID` と**併用できない**
（併用すると 400）。すなわち **syncToken には初回全同期時の窓が固定的に紐づき、
増分同期では窓を動かす手段が存在しない**。放置すると接続時点の `timeMax`（= 接続日+60日）より
先の予定は永久に届かず、**接続から60日で busy 取り込みが無言で停止する**
（エラーは出ず、ダブルブッキング防止だけが静かに失われる）。

よって全同期（`syncToken` 無し + `timeMin` / `timeMax` + `singleEvents=true`）の契機を
次の4つとする。

1. **初回接続時**
2. **対象カレンダー変更時**
3. **syncToken 失効（HTTP 410 Gone）** — 保存済み syncToken を捨てて全同期し直す
4. **日次の同期窓前進** — 定期コマンド `google-calendar:refresh-sync` が syncToken を破棄して
   全同期し直し、窓を当日基準へ進める

なお 1・2（初回接続時・カレンダー変更時）では**受信と送信の両方**の初回同期を投入する。
受信（全同期による busy 取り込み）だけでは、**接続前に登録済みの未来の予約が Google に
一切現れない**。送信は同期窓内の `status=reserved` な対象予約を書き出すもので、
既に `google_event_id` を持つ予約は対象カレンダーに存在すれば更新、無ければ作成し直して
ID を差し替える。

増分同期リクエストには `syncToken` のみを渡し、一切の絞り込みを付けない（`singleEvents` は可）。

#### 全同期は照合削除（reconcile）を伴う

**全同期の応答には削除済みイベントが含まれない**（`showDeleted` の既定は false であり、
削除エントリを運ぶのは増分同期の差分ストリームのみ）。このため全同期だけでは、
syncToken が失効していた間に Google 側で削除された予定の busy ブロックを誰も消さず、
**幽霊 busy が空き枠を恒久的に塞ぎ続ける**。

全同期では、当該接続の**同期窓内の busy ブロックのうち今回の応答に現れなかった**
`google_event_id` の行を削除する（差集合の刈り取り）。同期窓の外へ出た busy ブロックも削除する。
刈り取りと再構築は**1トランザクション**で行う（同期中に busy が空になる瞬間を作らないため）。

#### ページングと sync_token の保存順序

`nextSyncToken` は**最終ページにのみ**返る（中間ページは `nextPageToken` のみ）。
`nextPageToken` が返る間は同一パラメータ + `pageToken` で全ページを辿る。
保存の順序は「**全ページ適用 → コミット → `sync_token` 更新**」とする。
先にトークンを保存すると、未適用の差分を飛び越えたトークンは 410 にもならないため、
**取りこぼしが恒久化する**。適用途中で失敗した場合は `sync_token` を更新せず、
次回は同じトークンから再実行する（再適用は busy の upsert と RB 由来イベントの
no-op により冪等である）。

#### 増分同期ジョブは `ShouldBeUniqueUntilProcessing`（`ShouldBeUnique` は採用しない）

増分同期ジョブは接続単位で **`ShouldBeUniqueUntilProcessing`**
（`uniqueId` = 接続ID、`uniqueFor` = 10分）とする。

**`ShouldBeUnique` を採用しない**。同ロックは**ジョブの処理が完了するまで**保持されるため、
同期の実行中に届いた push 通知が投入しようとするジョブは**破棄される**。
push 通知は「次の変更が起きるまで」二度と来ないため、実行中に起きた変更は反映されないまま滞留し、
**外部予定が busy にならないまま公開予約が入る** — 本フェーズが防ごうとしている事故そのものである。
`ShouldBeUniqueUntilProcessing` は処理開始時にロックを解放するため、実行中に届いた通知は
次の1本としてキューイングされ、変更が取りこぼされない。
`uniqueFor` を明示するのは、未指定ではロックが無期限になり、ワーカーの異常終了時に
残留したロックが当該接続の同期を恒久的に停止させるためである。

#### watch チャネルの「更新」＝張り直し

**watch チャネルは有効期限付きで自動失効する**ため、**期限前にチャネルを更新する定期コマンド**
（`google-calendar:renew-channels`）を用意しスケジュール登録する。

ここで **Google にチャネルの更新 API は存在しない**。本設計で「チャネルを更新する」と呼ぶのは、
**新しい `channel_id` で `channels.watch` を張り直し、旧チャネルを `channels.stop` する**ことを指す
（用語として固定する）。張り直しを先、`stop` を後に行う（逆順では watch 失敗時に
無通知の窓が空く）。チャネルの TTL は Google 側が決める（要求値より短くされうる）ため、
独自の TTL を仮定せず応答の `expiration` をそのまま保存する。
解除時・接続削除時は `channels.stop` でチャネルを停止する。

#### watch 開設は best-effort（接続・カレンダー変更とも）

`channels.watch` の `address` は **HTTPS かつ Search Console でドメイン所有権を確認済み**
であることを Google が要求するため、未検証環境（ローカル開発・デプロイ直後の未検証ドメイン）
では必ず失敗する。接続時・カレンダー変更時の watch 開設が失敗しても**打ち切らず**、
警告ログのみで接続保存・初回同期投入を完遂する（打ち切ると接続レコードは保存済みなのに
エラー表示となり状態が食い違う。また push が使えないだけで、初回同期＋日次の
`refresh-sync` により機能自体は成立する）。
未開設（`channel_id` が null）の Active 接続は `renew-channels` が期限切れ間近のチャネルと
同様に拾って開設する（旧チャネルが無ければ `stop` は行わない）。
これによりドメイン検証の完了後は、翌日の定期実行から push が自動で有効になる。

#### webhook は3段検証

webhook は `POST /api/google/calendar/webhook`（認証なし・throttle なし・`v1` プレフィックス外）。
`X-Goog-Channel-ID` / `X-Goog-Channel-Token` / `X-Goog-Resource-ID` / `X-Goog-Resource-State`
を読み、次の3段で検証する。**いずれに該当しても即 200 で終了する**
（ログのみ。Google のリトライ暴走を防ぐ。ADR-024 の LINE webhook と同方針）。

1. **未知の `X-Goog-Channel-ID`**（該当する接続が無い）
2. **`X-Goog-Channel-Token` が `channel_token` と不一致**
3. **`X-Goog-Resource-ID` が `channel_resource_id` と不一致**

`channel_token` は CSPRNG 由来の32文字以上とし、比較は `hash_equals` で行う。
`X-Goog-Resource-State: sync`（チャネル開設直後の疎通通知）は何もせず 200。
検証を通ったら当該接続の増分同期ジョブを投入して 200 を返す。

### 6. 競合時は RB を真実とする

RB 由来イベント（§4 のサーバ側突合を通過したイベント）が Google 側で変更された場合の扱い。

- `start` と `end` の**両方**が RB の予約（`start_at` / `end_at`）と一致 → **no-op**
- `start` が変わった → 移動先が他の予約・営業時間・busy と競合しなければ**予約を更新**する。
  競合するなら **RB の値で Google 側を巻き戻す**
- **削除された（tombstone）** → **予約を status=cancelled にする**（判定は §4 の1段目）。
  **ただし対象予約の status が既に `cancelled` / `no_show` なら no-op**（下記）

#### 削除取り込みの status ガード（cancelled / no_show は no-op）

Google 側削除を予約のキャンセルとして取り込むのは、対象予約の status が
**`reserved` / `visited` の場合のみ**とする。既に `cancelled` / `no_show` なら **no-op** とする。

これは「RB 側で `cancelled` / `no_show` にした → 送信同期が Google イベントを削除した」という
**自らの削除のエコー**であり、そのまま適用すると `no_show`（無断キャンセル）が
`cancelled`（事前キャンセル）に潰される。両者は来店実績・顧客の信用管理を分ける区別であり、
上書きは業務データの破壊にあたる。
なお送信同期は削除成功時に `google_event_id` を null クリアするため、
通常このエコーは §4 の逆引きにヒットしない（status ガードは二重の防御）。

#### 反映するのは start のみ（end は常に再導出する）

`reservations.end_at` は **`start_at + menu.duration_minutes` からサーバが導出する不変条件**を
持ち、API 入力では受け取らない（ERD・ADR-023）。よって Google 側の `end` をそのまま
書き込むことは**できない**（invariant が壊れ、次にその予約の `start_at` / `menu_id` を
編集した時点で導出値に戻り、乖離が生まれる）。

- 受信同期が反映する対象は **`start` のみ**。`end_at` は常に `start + menu.duration_minutes` で
  再導出する
- Google 側の `end` が導出値と異なる場合は（**`start` が一致していても**）
  **RB の値で Google 側を巻き戻す**。スタッフがイベント下端をドラッグして長さだけ変えた
  ケースがこれに当たる。放置すると Google 側が RB より長い／短い状態で固定され、
  スタッフの手元カレンダーが空き状況について嘘をつく
- したがって no-op の条件は「**`start` と `end` の両方が RB の値と一致する場合のみ**」である
  （「時刻が一致」では粒度が曖昧なため、ここで確定させる）

#### staleness ガード（どちらの変更が新しいかを必ず判定する）

送信同期はキュージョブ経由（`afterCommit()`・tries=3・バックオフ）であり、
ジョブがキュー待ちの間に受信同期が走ると、**まだ古い値のままの Google イベント**を読む。
また 410 全同期・カレンダー変更時の再構築は RB 由来イベントを全件その時点の Google の値で
読むため、この窓は常に開いている。ガードが無いと次が起きる。

> 管理画面で予約を 10:00 → 11:00 に変更（送信同期ジョブはキュー待ち）。
> ほぼ同時に push 契機の増分同期が走り、まだ 10:00 のイベントを読む
> → 「Google 側で移動された」と誤認 → 10:00 が空いていれば**予約を 10:00 に戻す**。
> その後に送信同期ジョブが（実行時点の予約を読んで）10:00 を Google へ書くため、
> スタッフの編集は**サイレントに永久消失する**。

よって RB 由来の**生きているイベント**の受信処理には **staleness ガード**を入れる
（tombstone は §4 の1段目でキャンセルとして確定させる。削除は時刻の新旧を問わない意思表示であり、
ここでは対象外）。

- イベントの `updated`（read-only・RFC3339・ミリ秒）と当該予約の `updated_at` を
  **UTC の instant として比較**し、`event.updated <= reservation.updated_at`（+ 数秒の許容幅）
  なら「**RB の方が新しい**」として **no-op** とする
  （RB 側の送信同期ジョブが後で正しい値を書くため、受信側は何もしなくてよい）
- 許容幅を置くのは Google と RB の DB のクロックスキューを吸収するため
  （厳密比較にしない）
- 比較は必ず `Carbon` の instant 比較で行う。**文字列比較・ローカル時刻比較は禁止**する
  （Google は UTC の `Z` 付き、RB は timestamptz。`->utc()` を通すこと）
- `etag` は不透明トークンで**順序比較できない**ため、新旧判定には使わない（`updated` を使う）
- あわせて、**送信同期ジョブはジョブ実行時点の予約を再読み込みして書く**
  （dispatch 時のペイロードを固定しない）。これは staleness ガードとセットで成立する
  （ガード無しに再読み込みだけを行うと、上記のとおり編集が永久消失する）

このガードが無いと、不一致分岐は「新しい RB の値を古い Google の値で上書きする」ことになり、
本節の「RB を真実とする」方針そのものと矛盾する。

RB を真実とするのは、予約が顧客との約束であり、
カルテ・売上・LINE 通知と結びつく一次データだからである。
Google 側での不用意なドラッグ移動が RB の整合性（ダブルブッキング防止・営業時間）を
壊すことは許さない。一方、**Google 側の削除は明確な意思表示**とみなし、
キャンセルとして取り込む（巻き戻すとスタッフの操作が無視され不信につながる）。

Google 側で**新規作成**された予定を RB の予約に昇格させることはしない（外部予定は busy 止まり）。
予約には顧客・メニュー・料金が必要であり、カレンダーのイベントからは導出できない。

### 7. busy ブロックは時刻のみ保存（タイトル非保存）

外部予定（マーカーなし）は `google_busy_blocks` に **開始・終了時刻のみ**を保存する。
**タイトル・説明・場所・参加者等の内容は一切保存しない**。

スタッフの私用カレンダーには通院・家庭の事情等の機微情報が含まれる。
RB が必要とするのは「その時間が塞がっている」という事実だけであり、
内容を保存しない設計はプライバシー配慮であると同時に、
DB 漏洩時の被害範囲を最小化する。RB のカレンダー表示上は「外部予定」と表示する。

#### busy として扱わないイベントは3種（該当したら既存 busy を削除する）

次のイベントは busy ブロックに取り込まない。

1. **`transparency=transparent`**（＝予定ありにしない）— Google 側で「予定なし」と
   表明されている以上、RB の空き枠も塞がない
2. **`eventType` が `workingLocation`（勤務場所）/ `birthday`（連絡先の誕生日）** —
   `primary` カレンダーに流れてくる特殊イベントで、`singleEvents=true` により
   終日イベントとして実体展開される。取り込むと**丸1日が塞がり「予約を受けられる日が消える」**
3. **接続アカウント本人の `responseStatus` が `declined`**（辞退した会議）—
   辞退済みでも `transparency` は `opaque` のまま残るため、除外しないと
   「行かないと表明した会議」が空き枠を塞ぐ

**除外条件に該当するようになった場合は、既存の busy ブロックを削除する**。
`transparency` を後から `transparent` に変えた、会議を後から辞退した、といった変更は
増分同期では「更新イベント」として届くため、取り込みをスキップするだけでは
**幽霊 busy が残り続ける**（除外は「取り込まない」ではなく「取り込まない + 消す」である）。

#### 終日予定は複数日にまたがっても1ブロック

終日予定は `start.date` / `end.date` で表現され、**`end.date` は排他**である
（7/20 のみの終日予定は `start.date=2026-07-20`, `end.date=2026-07-21`）。
**`start.date` の salon_timezone 00:00 から `end.date` の salon_timezone 00:00 までを
1本の busy ブロック**として取り込む。

連休・旅行・全体研修のように**複数日にまたがる終日予定も1レコード**で表現する。
`google_busy_blocks` の unique 制約 `(google_calendar_connection_id, google_event_id)` が
日ごとの分割を禁じるためである（`singleEvents=true` は「繰り返し」の実体展開であって、
複数日スパンを日単位に割ることはしない）。

空き枠への反映は、`AvailabilityService` の空き枠計算と `PublicBookingService` の
枠検証（サーバ側再検証）の両方で行い、対象スタッフ（shared モードならサロン全体）の
busy ブロックと重なる枠を**予約不可**とする。
**管理側（`/api/v1`）の予約登録は busy でも登録可能**とする
（ADR-023 の「営業時間は手動予約を制限しない」と同じ思想。サロンの裁量を優先）。
公開側は不可。advisory lock の取得順序は既存を維持し（phone → スタッフ）、
busy 判定はロック内の重複チェックと同じ箇所で行う。

### 8. OAuth は API 側 redirect_uri でサーバ交換し SPA へ 302（FRONTEND_URL を新設）

SPA（Cloudflare Pages）と API（Render）が**別オリジン**である構成を前提とする
（フェーズ2レビューで顕在化した論点）。

- `redirect_uri` は **API 側**の `{API_URL}/api/v1/google-calendar/callback` を
  Cloud Console に登録する。`client_secret` を安全に扱うため、
  認可コードとトークンの交換は**サーバで行う**（SPA に secret を置かない）
- コールバックは Google からのブラウザリダイレクトであり **Bearer トークンが無い**。
  よって開始時に `state`（推測不能なランダム値）を発行し、
  **キャッシュに `state → {salon_id, user_id, mode}` を TTL 10分で保存**して文脈を引き継ぐ。
  state 不一致・期限切れはエラーとする（CSRF 対策も兼ねる）
- 交換完了後、API は SPA へ 302 リダイレクトする:
  `{FRONTEND_URL}/settings/google-calendar?connected=1`（失敗時は `?error=...`）
- **`FRONTEND_URL` 相当の設定値を新設する**（config + `.env.example`）。
  フェーズ2では未整備であり、サーバ側リダイレクトには必須

`access_type=offline` + `prompt=consent` を付けて refresh_token を確実に取得する。
`access_token` / `refresh_token` は **encrypted cast** で暗号化保存する
（ADR-024 の `line_settings` と同方針。APP_KEY ローテーション時の注意も同様に適用される）。
access_token 期限切れ時は refresh_token で更新し、
refresh_token が失効・取消（ユーザーが Google 側でアクセス解除）された場合は
接続を `needs_reconnect` 状態にして UI で再接続を促す（同期ジョブはリトライせず打ち切る）。

#### 解除の副作用は5手順（Google 側の後始末は best-effort）

接続解除（`DELETE /connections/{id}`）は次を順に行う。

1. **`channels.stop`** で watch チャネルを停止する
2. **refresh_token を revoke する**（`https://oauth2.googleapis.com/revoke`）。
   RB の DB から消すだけでは発行済み refresh_token が Google 側で有効なまま残り、
   バックアップ・ログに残った値が後から悪用され得る。revoke により
   ユーザーの Google アカウントの「サードパーティ アクセス」からも RB が消える
3. 当該接続の **busy ブロックを削除**する
4. 対象範囲の予約の **`google_event_id` を null クリア**する
   （**Google 側のイベントは削除しない**。理由は §2 の非対称の項）
5. 接続レコードを**物理削除**する（SoftDeletes は用いない）

**1・2 の失敗は best-effort（ログのみで続行）** とし、RB 側の 3〜5 は必ず完遂する。
`status=needs_reconnect` の接続では 1・2 は必ず失敗するが、UI は当該状態でこそ
「接続を解除」を提供するため、解除は成功しなければならない
（失敗で打ち切ると「Google 側でアクセスを取り消した接続は RB からも解除できない」
デッドロックになる）。

`PUT /google-calendar/mode` によるモード変更時の一括解除、および再接続時の旧接続の破棄にも
**同じ副作用セット**を適用する。

### 9. Google API は Laravel HTTP クライアントで実装（公式 SDK 不使用）

必要な API は token 交換 / `events.list` / `events.insert`・`update`・`delete` /
`calendarList.list` / `channels.watch`・`stop` に限られるため、
ADR-021（OpenAI 連携）・ADR-024（LINE 連携）と同方針で `Http` クライアントで実装する。
ベース URL は config から取得し、テストで `Http::fake()` 可能にする。

イベント更新は `events.update`（PUT・全置換）ではなく **`events.patch`（PATCH・部分更新）** を使う。
PUT は `sequence` の一致を要求するため、会議室応答や他アプリの編集で `sequence` が進んだ
イベントへの全置換が 400 で恒久失敗する。PATCH は部分更新で `sequence` を要求しないため回避できる
（対象が存在しない 404 / 410 は insert フォールバックで作り直す）。
`calendarList.list` は `maxResults=250` + `nextPageToken` で全ページを辿る
（100 件超のアカウントで 1 ページ目だけ取ると選択肢が欠落する）。

Google は **資格情報が env・トークンが DB** という混在型で、既存の前例
（`openai` は資格情報も env、`line` は `base_url` のみ config で資格情報は DB）と形が違うため、
`config/services.php` の `google` キーの構造をここで確定させる。
OAuth 系（`accounts.google.com`）と API 系（`www.googleapis.com`）は**別ホスト**である点に注意。

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'auth_base_url' => env('GOOGLE_AUTH_BASE_URL', 'https://accounts.google.com'),
    'token_url' => env('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
    'api_base_url' => env('GOOGLE_API_BASE_URL', 'https://www.googleapis.com'),
    'timeout' => env('GOOGLE_TIMEOUT', 10),
],
```

`.env.example` には `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `FRONTEND_URL` を追加する
（ベース URL 3種と `timeout` は既定値を持つため任意。テストでの差し替え用に env 化しておく）。

**`{API_URL}` は既存の `APP_URL`**（`config('app.url')`）を指す。新設しない。
`redirect_uri`（`{APP_URL}/api/v1/google-calendar/callback`）と watch チャネルの
`address`（`{APP_URL}/api/google/calendar/webhook`）の**両方**がこの値に依存し、
値がずれると OAuth と push 通知の**両方が壊れる**（かつ push は静かに止まる）ため、
デプロイ時に `APP_URL` が API の公開 URL と一致していることを確認する。
一方 SPA へのリダイレクト先は別オリジンであるため `FRONTEND_URL` を新設する（§8）。

---

## Alternatives Considered

### calendar.app.created による一方向ミラー

`https://www.googleapis.com/auth/calendar.app.created` は**アプリが自ら作成した
カレンダーのみ**を操作でき、**機密スコープではないため Google 審査が不要**という
大きな利点がある。RB 専用カレンダーを自動生成し、そこへ予約をミラーする構成になる。

しかし、このスコープでは**スタッフの私用予定を読めない**。
本フェーズの中核価値である**ダブルブッキング防止が原理的に得られず**、
得られるのは「Google でも予約を閲覧できる」だけになる。
審査を回避するために課題そのものを解かない選択であり、採用しない。

### 専用カレンダーへの双方向同期

RB 専用カレンダーを作り、そこへ双方向同期する案。
`calendar.events` は必要（＝審査も必要）だが、書き込み先が RB 由来イベントのみになるため
エコー防止が単純になる。

しかし**専用カレンダーには私用予定が存在せず、busy 反映が働かない**。
審査コストとエコー処理・同期基盤の実装コストを払いながら、
得られるのは「Google 側での予約編集」だけであり、割に合わない。採用しない
（なお本設計では、ユーザーが対象カレンダーに専用カレンダーを**選ぶ**ことは可能で、
その場合はこの案と同じ挙動になる。UI で busy 不動作を明記する）。

### freebusy 系スコープのみ

`calendar.freebusy` 等で多忙時間帯だけを読む案。
読むのは時間帯のみでプライバシー面も良好に見え、busy 反映は成立する。

しかし**イベントの書き込みができない**ため、RB の予約が Google に現れない。
スタッフは RB を開かないと自分の予定の全体像が見えず、双方向同期にならない。
`calendar.events` を別途要求するなら freebusy を併用する意味が薄い。採用しない。

### 公式 google-api-php-client の導入

型付きクライアントと OAuth ヘルパが得られるが、必要 API が上記の限られた範囲のみで、
依存としては大きい。ADR-021・ADR-024 と同判断で `Http` クライアントを採用し、
依存を最小化する（`Http::fake()` でテスト可能）。

### ポーリングによる変更検知

watch チャネルの期限管理・webhook 検証・`channels.stop` が不要になり運用は単純だが、
接続数×間隔でクォータを消費し、検知が遅れる（間隔ぶんの窓でダブルブッキングが起きうる）。
push 通知 + syncToken 増分同期を採用する。

なお §5 の日次の同期窓前進（`google-calendar:refresh-sync`）は**ポーリングではない**。
変更の**検知**は依然として push 通知が担っており、日次の全同期は
「syncToken が窓を運べない」という API 制約に対する**窓の張り直し**である
（接続あたり1日1回・固定回数であり、接続数×間隔で増える性質を持たない）。

---

## Consequences

### Advantages

- **ダブルブッキングが原理的に防がれる**。スタッフの私用予定が公開予約の空き枠を塞ぐため、
  「その時間は空いていない」予定の上に顧客が予約を入れられなくなる
- スタッフは普段使いの Google カレンダー1本で私用も業務も見られる。
  RB を開かなくても予約が手元のカレンダーに現れる
- モード選択により、スタッフ別（各自の私用予定を反映）とサロン共有（店休・全体行事を反映）の
  どちらの運用にも対応できる
- busy ブロックが時刻のみのため、私用予定の内容が RB に一切残らない。
  DB 漏洩時も機微情報が露出しない
- push 通知 + syncToken により、変更が即座に反映されつつ API 消費が差分ぶんに抑えられる
- マーカーによる自己識別でエコーが1周で収束し、RB を真実とする方針で整合性が壊れない
- 追加パッケージなし・`Http::fake()` でテスト可能（ADR-021・ADR-024 と一貫）
- `FRONTEND_URL` の新設により、別オリジン構成でのサーバ側リダイレクトが成立する
  （フェーズ2の未整備が解消される）

### Disadvantages / Risks

- **審査未完了（In production・未確認）時は 100ユーザー上限**で頭打ちになる。しかも上限は
  **Cloud プロジェクトの生涯を通じた累計でリセット不可**のため、
  テスト用アカウントの浪費が本番の枠を削る。開発・検証時のアカウント管理に注意が必要
- **publishing status を Testing のままにできない**（§1）。Testing では refresh_token が
  **7日で失効**し本設計が成立しないため、開発・検証段階から In production（未確認）で運用する。
  この制約は「未審査でも動く」という利点と引き換えに、開発環境でも生涯累計の枠を
  消費することを意味する
- **機密スコープゆえの審査負荷**。公開ドメイン上のプライバシーポリシー・
  Search Console ドメイン認証・OAuth 同意画面のデモ動画・スコープ正当性説明が必要で、
  **公式は最大10日だが実務報告では 4〜6週間**かかる。一般公開の時期はこの前提で計画する。
  また審査で差し戻されるとリードタイムがさらに伸びる
- **API クォータ**を消費する。接続数の増加、410 による全同期の頻発、
  日次の同期窓前進（`google-calendar:refresh-sync`）、watch チャネルの張り直しが重なると
  クォータ超過（429）のリスクがある。とくに全同期は同期窓（60日ぶん）を
  `singleEvents=true` で実体展開して**複数ページ**取得するため、
  増分同期1回とはコストの桁が違う。バックオフとリトライ設計、超過時の監視が必要
- **双方向同期は自分の書き込みが自分宛の通知を生む**。送信同期の
  `events.insert` / `update` / `delete` は対象カレンダーの変更として push 通知を誘発し、
  その通知が増分同期ジョブ（`events.list`）を1回起こす。すなわち**予約1件の作成で
  events 書き込み1回 + events.list 1回**を消費する。§6 の巻き戻し
  （RB の値で Google 側を書き戻す）はさらに「書き戻し → 通知 → 増分同期 → 一致で no-op」と
  1周ぶん余計に回る。エコーは1周で収束するが、**クォータ消費は素朴な見積りの 2〜3倍**になる。
  差分同期・ポーリングしない・`ShouldBeUniqueUntilProcessing` による多重実行の抑制・
  同期窓の限定（現在〜本日+60日の終日終端）といった対策はいずれも**受信側に閉じており**、
  この増幅を抑えない
- 上記に伴い、**対象プロジェクトの実クォータ値**（Google Cloud Console の Calendar API の
  クォータページで確認できる 1日あたりのクエリ数・ユーザーあたり毎分の上限）を
  デプロイ前に確認して記録する。**429 受信時は `Retry-After` に従う**
  （送信同期ジョブの tries=3・バックオフとは別扱い。固定バックオフで押し返さない）
- **定期コマンドが2本とも「止まっても無言」である**。watch チャネルは有効期限付きで
  自動失効するため `google-calendar:renew-channels` が止まると**変更検知そのものが静かに止まる**。
  また syncToken は同期窓を運べないため `google-calendar:refresh-sync` が止まると
  **接続から60日で新しい期間の busy 取り込みが静かに止まる**（§5）。
  いずれもエラーを出さずにダブルブッキング防止だけが失われるため、
  両コマンドの実行監視と `last_synced_at` の可視化が必要
- **専用カレンダーを選ぶと busy が動作しない**。UI に明記するが、
  ユーザーが「RB 用に分けたい」という直感で専用カレンダーを選び、
  中核価値を失ったまま気づかない可能性が残る
- **Google 側でイベントを削除すると予約がキャンセルされる**。
  スタッフがカレンダーの整理のつもりで RB 由来イベントを消すと顧客の予約が消える。
  UI・設定手順ガイドでの周知が必須であり、周知しても事故は起こりうる
- Google 側での時刻変更が競合した場合、**サイレントに巻き戻る**（RB を真実とするため）。
  スタッフから見ると「動かしたのに戻った」と映るため、
  巻き戻しの発生をログに残し、必要なら通知の追加を検討する
- 同様に、Google 側で RB 由来イベントの**長さだけを変えても必ず巻き戻る**（§6）。
  `end_at` はメニューの `duration_minutes` から導出される不変条件であり、
  Google 側の `end` を反映する経路が存在しないためである。
  施術が延びた場合は RB 側でメニューを変更する運用になる
- **マーカー（`extendedProperties.private`）は改竄可能な入力であり、権威は RB 側の
  `reservations` 行にある**。`private` はアプリ固有ではなく当該カレンダー上のコピーに固有という
  意味に過ぎず、カレンダーへの書き込み権限を持つユーザー・当該ユーザーが認可した他アプリが
  自由に書ける。§4 のサーバ側突合（接続の `salon_id` / `user_id` と `google_event_id` の一致）が
  受信同期における唯一のテナント境界であり、**これを省くと他サロンの予約を操作できる**。
  実装・レビュー時に最優先で確認する
- 同期対象が同期窓（現在 〜 salon_timezone の本日+61日 00:00）に限られるため、
  範囲外の予定は busy にならない（RB の予約可能範囲に合わせた割り切り）
- `APP_KEY` ローテーション時に暗号化カラム（access_token / refresh_token）への配慮が
  運用上必要になる（ADR-024 と同様。`APP_PREVIOUS_KEYS` 設定または再暗号化）

---

## References

- [docs/requirements/google-calendar.md](../requirements/google-calendar.md)
- [docs/ui/settings-google-calendar.md](../ui/settings-google-calendar.md)
- [docs/requirements/booking.md](../requirements/booking.md)
- docs/requirements/reservation.md
- docs/db/ERD.md
- docs/api/endpoints.md
- docs/roadmap/ROADMAP.md
- [docs/decisions/ADR-021-openai-integration.md](ADR-021-openai-integration.md)
- [docs/decisions/ADR-022-deployment.md](ADR-022-deployment.md)
- [docs/decisions/ADR-023-reservation-core.md](ADR-023-reservation-core.md)
- [docs/decisions/ADR-024-line-integration.md](ADR-024-line-integration.md)
