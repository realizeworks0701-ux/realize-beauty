# Googleカレンダー双方向同期（Google Calendar）要件書

## Project

Realize Beauty — 予約管理 フェーズ3（ROADMAP v0.3）

---

## Purpose

Realize Beauty（以下 RB）の予約とスタッフの Google カレンダーを双方向に同期し、
サロンスタッフが RB と Google カレンダーのどちらを見ても同じ予定が並ぶ状態にする。

あわせてスタッフの私用予定（RB 外の予定）を「外部予定」として取り込み、
RB の空き枠計算・公開Web予約に反映することで、
私用予定の時間帯に顧客からの予約が入ってしまうダブルブッキングを防ぐ。

---

## Background

- フェーズ1（予約コア）でサロン側の予約管理が、フェーズ2（Web予約・LINE連携）で顧客からの予約受付が完成した
- 予約が RB に集約された一方、スタッフの私用予定は各自の Google カレンダーにあり、RB からは見えない
- このため「Google カレンダー上は予定があるのに RB の公開予約ページでは空き枠として見える」状態が発生し、Web予約の受付枠と実際の稼働がずれる
- 逆に RB の予約は Google カレンダーに現れないため、スタッフは2つのカレンダーを見比べる運用を強いられている
- **フル双方向同期**を採用し、スタッフの私用予定が RB の空き枠を塞ぐところまでを本フェーズの対象とする（[ADR-025](../decisions/ADR-025-google-calendar-sync.md)）
- 接続単位はサロンごとに「スタッフ別」「サロン共有1本」から選択できる方式とする（[ADR-025](../decisions/ADR-025-google-calendar-sync.md)）

---

## フェーズ分割

| フェーズ | 内容 | 本書の対象 |
|---------|------|-----------|
| フェーズ1 | 予約コア＝メニュー管理・営業時間・予約CRUD・サロン側予約カレンダー・ダッシュボード「今日の予約」（完了） | 対象外 |
| フェーズ2 | 公開Web予約ページ・LINE連携（サロン別チャネル接続）・連携コードによる顧客紐付け・前日リマインダー（完了。詳細は [booking.md](booking.md) / [ADR-024](../decisions/ADR-024-line-integration.md)） | 対象外 |
| フェーズ3 | Googleカレンダー双方向同期＝OAuth接続（スタッフ別 / サロン共有）・送信同期・受信同期・外部予定の busy 反映 | ✓ |

---

## Scope

### In Scope（フェーズ3）

1. Google OAuth 接続・解除（スタッフ別 / サロン共有の両モード）
2. 対象カレンダーの選択（既定 `primary`）
3. 送信同期（予約の作成・変更・キャンセル → Google イベント）。接続時・対象カレンダー変更時の既存予約の書き出し（初回送信同期）を含む
4. 受信同期（push 通知 + syncToken 増分同期。410 Gone と日次の同期窓前進で全同期）
5. 外部予定の busy ブロック化と**空き枠・公開予約への反映**
6. RB 由来イベントの移動・削除の取り込み（競合時は RB を真実として巻き戻し）
7. watch チャネルの定期更新・同期窓の日次前進（定期コマンド）
8. トークン更新・失効時の再接続導線
9. 予約カレンダーへの「外部予定」表示（時刻のみ・グレー表示）

### Out of Scope（実装しない）

- 複数カレンダーの同時読み取り（1接続 = 1カレンダー）
- Google 側で**新規作成**された予定を RB の予約に昇格させること（外部予定は busy 止まり）
- 出席者・会議室・Google Meet 連携
- 参加者への招待メール送信（イベントは接続アカウントのカレンダーに作るのみ）
- カレンダー共有設定の操作
- Google 審査の申請作業そのもの（デプロイ時の手続き。前提とリスクは [ADR-025](../decisions/ADR-025-google-calendar-sync.md) に記録する）
- Outlook / iCloud 等の他カレンダー

---

# User Stories

- サロンスタッフとして、自分の Google カレンダーを接続し、RB の担当予約を自分のカレンダーで確認したい
- サロンスタッフとして、Google カレンダーに入れた私用予定の時間帯には顧客からWeb予約が入らないようにしたい
- サロンスタッフとして、Google カレンダー上で予約を別の時間へ動かしたら RB 側にも反映されてほしい
- サロンスタッフとして、RB の予約カレンダー上で「この時間は外部予定で埋まっている」ことを（内容は伏せたまま）把握したい
- オーナーとして、スタッフ別接続か、サロン共有カレンダー1本かを自サロンの運用に合わせて選びたい
- オーナーとして、サロン共有カレンダーに店休・全体研修を入れて、全スタッフの空き枠をまとめて塞ぎたい
- オーナーとして、接続の状態（アカウント・対象カレンダー・最終同期）を画面で確認したい
- サロンスタッフとして、Google 側でアクセスを解除してしまった際に、再接続が必要であることに気づきたい

---

# 画面フロー概要

## Googleカレンダー連携設定（管理側・Sanctum 認証）

```
設定 > Googleカレンダー連携
  モード選択（スタッフ別 / サロン共有）
    → Google と接続（OAuth 同意画面へ遷移）
    → コールバック後、設定画面へ戻る（connected=1 / error=...）
    → 対象カレンダーの選択（既定 primary）
    → 状態表示（アカウント・対象カレンダー・状態・最終同期）/ 連携解除
```

## OAuth 接続（別オリジン構成）

```
SPA「Google と接続」
  → POST /api/v1/google-calendar/auth-url（state 発行・キャッシュ保存）
  → Google 同意画面
  → GET {API_URL}/api/v1/google-calendar/callback?code=&state=（認証なし）
  → トークン交換 → 接続保存 → watch 開始
  → 初回同期投入（受信 = 全同期で busy 取り込み / 送信 = 同期窓内の既存予約の書き出し）
  → 302 {FRONTEND_URL}/settings/google-calendar?connected=1（失敗時は ?error=...）
```

## 同期（バックグラウンド・画面操作なし）

```
RB の予約 作成/変更/キャンセル
  → 送信同期ジョブ → Google イベント 作成/更新/削除

Google カレンダーの変更
  → push 通知（POST /api/google/calendar/webhook）
  → 増分同期ジョブ（syncToken）
  → RB由来イベント: 反映 or 巻き戻し / 外部予定: busy ブロック upsert
```

画面設計は `docs/ui/` 配下の該当設計書を正とする。

---

# Functional Requirements

## 接続（OAuth）

- Google OAuth は認可コードフローとする。要求スコープは `https://www.googleapis.com/auth/calendar.events`（機密スコープ）と、カレンダー一覧取得用の `https://www.googleapis.com/auth/calendar.calendarlist.readonly` の2つのみとする
- `access_type=offline` + `prompt=consent` を付与し、refresh_token を確実に取得する
- `google_account_email` は **`calendarList` の `primary` エントリの `id`**（= アカウントのメールアドレス）から取得する。宣言スコープ（`calendar.events` + `calendar.calendarlist.readonly`）ではユーザー情報を直接取得できないが、そのためだけに `userinfo.email` / `openid` スコープを**追加しない**（要求スコープを増やすと Google 審査の正当性説明が重くなるうえ、表示用の値のために機微情報の要求範囲を広げることになる）
- SPA（Cloudflare Pages）と API（Render）は**別オリジン**であることを前提とする。`redirect_uri` は client_secret をサーバ側で安全に扱うため **API 側**の `{API_URL}/api/v1/google-calendar/callback` を Google Cloud Console に登録する
- コールバックは Google からのブラウザリダイレクトであり Bearer トークンを持たない。このため接続開始時に推測不能なランダム値の `state` を発行し、**キャッシュに `state → {salon_id, user_id, mode}` を TTL 10分で保存**して文脈を引き継ぐ。state 不一致・期限切れはエラーとする
- 交換完了後、API は SPA へ 302 リダイレクトする（成功 `{FRONTEND_URL}/settings/google-calendar?connected=1` / 失敗 `?error=...`）。このため **`FRONTEND_URL` 相当の設定値を新設する**（config + `.env.example`。フェーズ2では未整備）
- 接続完了時に、対象カレンダーへの watch チャネル開設と初回同期ジョブの投入をあわせて行う。初回同期は**受信（全同期による busy 取り込み）と送信（同期窓内の既存予約の書き出し）の両方**を投入する（送信側を投入しないと、接続前に登録済みの未来の予約が Google に一切現れない）
- 連携解除では次の**5手順**を順に行う（本節を解除の副作用の正典とする）
  1. `channels.stop` で watch チャネルを停止する
  2. Google の revoke エンドポイント（`https://oauth2.googleapis.com/revoke`）へ refresh_token を送出し、Google 側の grant を失効させる（RB の DB から消すだけでは発行済み refresh_token が Google 側で有効なまま残り、バックアップ・ログに残った値が後から悪用され得る。ユーザーの Google アカウントの「サードパーティ アクセス」からも RB が消える）
  3. 当該接続の busy ブロックを削除する
  4. 当該接続が書き込み対象としていた範囲の予約の **`reservations.google_event_id` を null クリア**する（`per_staff` は当該スタッフ担当分、`shared` は全スタッフ分）
  5. 接続レコードを**物理削除**する（SoftDeletes は用いない）
- **接続解除では Google 側のイベントを削除しない**（サロンの記録として残す）。対象カレンダー変更（`PUT /google-calendar/connections/{id}`）が旧カレンダーの RB 由来イベントを**削除する**のとは**意図的に非対称**である。カレンダー変更は同一アカウント内の移し替えであり、解除後と違って RB は同じアカウントを読み続けるため、旧カレンダーに残った孤児イベントをスタッフが手で消すと、受信同期がそれを「RB 由来イベントの削除」と解釈して**生きている予約を cancelled にする**事故経路になる。解除では手順 4 で予約との紐付け（`google_event_id`）自体が切れるため、イベントを残しても RB に影響しない
- 手順 4 を省くと、解除後も予約が「もう読み書きしないカレンダーのイベントID」を指したまま残る。同一アカウントへ再接続した際に、初回送信同期がその ID へ `events.update` を試みて 404 を踏むほか、上記の「孤児イベントの手動削除 → 生きた予約が cancelled」の経路が解除後にも開いたままになる
- `channels.stop` と revoke の失敗は**ログのみで続行**し、RB 側の後始末（3・4・5）は必ず完遂する（Google 側の後始末は best-effort）。とくに `status=needs_reconnect`（refresh_token 失効・ユーザーが Google 側でアクセス解除）の接続では 1・2 は必ず失敗するが、UI は当該状態でも「接続を解除」を提供するため、解除は成功しなければならない（失敗で打ち切ると「Google 側でアクセスを取り消した接続は RB からも解除できない」デッドロックになる）
- モード変更に伴う一括解除（`PUT /google-calendar/mode`）、および再接続時の旧接続の破棄にも**同じ副作用セット（5手順すべて）**を適用する

## モード（スタッフ別 / サロン共有）

- サロンごとに `salons.google_calendar_mode` で「スタッフ別（`per_staff`）」「サロン共有（`shared`）」のいずれかを設定する。null = 未設定（接続不可）
- **スタッフ別（per_staff）**: 各スタッフが自分の Google アカウントを接続する。RB は**そのスタッフ担当の予約のみ**当該カレンダーへ書く。カレンダー上の RB 以外の予定は**そのスタッフの**空き枠を塞ぐ
- **サロン共有（shared）**: オーナーが1アカウントだけ接続する。RB は**全スタッフの予約**をそのカレンダーへ書き、イベント題名に担当スタッフ名を含める。カレンダー上の RB 以外の予定は**サロン全体（全スタッフ）の**空き枠を塞ぐ（店休・全体研修・イベント等を想定した意味論）
- モード切替時は既存の接続を**すべて解除する**。UI の確認ダイアログにこの影響を明記する

## 対象カレンダーの選択

- 1つの接続は Google アカウントの**1カレンダー**（既定 `primary`）を対象とする
- **同一カレンダーに対して書き込みと読み取りの両方**を行う。RB の予約をそのカレンダーへ書き、そのカレンダー上の**RB 以外の予定**を busy（多忙）として読む
- 接続後にカレンダーを選び直せる（`calendarList` から選択）。ただし RB 専用カレンダーを選ぶと私用予定を読めなくなり busy 反映が働かない旨を UI に明記する（既定かつ推奨は `primary`）
- `primary` は Google 側の**エイリアス**であり、`calendarList` が返す実 id はメールアドレス形式である。このため `calendar_id` の検証は「**`primary`、または `calendarList` に存在する id**」とする（`calendarList` に存在する id のみを許す検証にすると、既定値 `primary` 自身が検証に落ちる）
- **カレンダーの表示名（`summary`）は保持しない**。接続レコードにも API レスポンスにも表示名のフィールドを持たず、UI は `primary` を「メインカレンダー（{google_account_email}）」、それ以外は `calendar_id` をそのまま表示する（名称は「カレンダーを変更」ダイアログの `calendarList` 一覧で確認できる。保持すると Google 側の改名に追随できず古い名前が残り続ける）
- カレンダー変更時は syncToken を破棄し、watch チャネルを張り直し、busy ブロックを再構築する。あわせて**旧カレンダーの RB 由来イベントを削除し、新カレンダーへ初回送信同期で書き直す**（放置すると旧カレンダーにマーカー付きの孤児イベントが残り、`reservations.google_event_id` が存在しないイベントを指したままになる）

## 送信同期（RB → Google）

- 予約の作成・時刻/メニュー/担当の変更・キャンセルに応じて Google イベントを作成・更新・削除する
- 対応するイベントIDを `reservations.google_event_id` に保持する
- RB が作成する Google イベントには `extendedProperties.private.rb_reservation_id = {予約ID}` と `rb_salon_id = {サロンID}` を**必ず付与する**（エコー防止のマーカー）
- 送信同期はキュージョブ経由（`afterCommit()`）とし、**tries=3・バックオフ付き**とする
- 送信同期ジョブは**実行時点の予約を DB から再読み込み**して書く。dispatch 時に予約の内容（時刻・メニュー・担当）をペイロードとして固定**しない**。ジョブ引数は**予約ID**と、旧接続・旧カレンダーの特定に必要な**変更前の担当スタッフID / 変更前の `calendar_id`** のみとする
  - 固定すると、短時間に複数回変更された予約でジョブがキューに滞留した際、後続ジョブが**古いペイロード**を Google へ書き、最終状態と食い違ったまま収束する（リトライ時も同様に古い値を書く）。予約IDのみを渡し実行時に読み直せば、遅延したジョブも常に最新状態を書くため、順序が入れ替わっても最終的に正しい値へ収束する
- status が `cancelled` / `no_show` になったら Google イベントを削除する
- 予約の削除（Soft Delete = 誤登録の取り消し。キャンセルとは区別する。[reservation.md](reservation.md)）でも Google イベントを削除する（残すとマーカー付きの孤児イベントになり、受信同期が対応する予約を解決できなくなる）
- イベント削除に成功したら `reservations.google_event_id` を **null クリア**する（受信同期の逆引き照合がヒットしなくなり、RB 発の削除のエコーが自然に落ちる）
- 対象接続が無い（未接続・非アクティブ）場合は何もしない

### 発火点

- **Observer（モデルイベント）は用いず、Service 層の各書き込み経路から明示的に dispatch する**。既存の `ReservationRepository::cancelByBookingToken()` はクエリビルダによる一括 UPDATE で実装されており Eloquent のモデルイベントが発火しないため、Observer 方式では「顧客が公開ページからキャンセルしたのに Google のイベントが消えない」経路が丸ごと抜け落ちる（フェーズ2の `SendBookingConfirmationJob::dispatch` と同じく Service 層からの明示 dispatch とする）
- 対象経路: `ReservationService::create` / `update` / `delete`、`PublicBookingService::create`、`PublicBookingService::cancelBooking`
- **受信同期起因の予約更新（Google 側の移動の取り込み・Google 側削除によるキャンセル）では送信同期を投入しない**。同じ値を Google へ書き戻す往復（events.update → push 通知 → 増分同期 → no-op）を1操作ごとに消費し、クォータを無駄にするため。競合時の巻き戻しのみ、受信同期が明示的に `events.update` を呼ぶ

### 書き込み先の接続・カレンダーが変わる場合

- `per_staff` モードで担当スタッフが変更されると、書き込み先が**別アカウント・別カレンダー**になる。イベントIDはアカウントをまたいで `events.update` できないため、**旧接続でイベントを削除 → 新接続で作成 → `google_event_id` を差し替える**
- このため送信同期ジョブは**変更前の担当スタッフID**（対象カレンダー変更時は**変更前の `calendar_id`**）を引数で受け取り、旧接続・旧カレンダーを特定する。特定できないと旧カレンダーにマーカー付きの孤児イベントが残り、RB 由来と判定されるため busy にもならず永久に残る。さらに旧担当がそれを手で消すと、受信同期が「RB 由来イベントの削除」として新担当の実予約を cancelled にしてしまう
- 対象カレンダー変更（`PUT /google-calendar/connections/{id}`）でも同様に、旧カレンダーの RB 由来イベントを削除したうえで、新カレンダーへ初回送信同期で書き直す

### 初回送信同期

- 接続完了時（`GET /google-calendar/callback`）および対象カレンダー変更時に、**同期窓内（現在〜本日+60日の終日終端）の status=reserved な対象予約**（`per_staff` は当該スタッフ担当、`shared` は全スタッフ）を Google へ書き出すジョブを投入する
- 既に `google_event_id` を持つ予約は、当該イベントが対象カレンダーに存在すれば更新、存在しなければ作成し直して ID を差し替える

### Google API エラーの扱い

| ステータス | 扱い |
|-----------|------|
| 404 / 410（対象イベントが存在しない） | `delete` は**成功扱い**（冪等）とし `google_event_id` を null クリアして終了。`update` は `insert` へフォールバックし `google_event_id` を差し替える。いずれもリトライしない |
| 401（access_token 失効） | refresh_token で更新して1回だけ再試行する。更新に失敗したら接続を `needs_reconnect` にして打ち切る |
| 429 / 5xx | バックオフしてリトライする（tries=3） |
| その他の 4xx | リトライせずログのみ |

- 404 / 410 は例外ではなく設計上の常道: 接続解除→再接続（別カレンダー選択）後は `reservations.google_event_id` が旧カレンダーのイベントを指したまま残るため、`update` は必ず 404 になる

## 受信同期（Google → RB）

- 対象カレンダーに **push 通知（watch チャネル）** を張る。通知は「変更があった」ことしか伝えないため、受信したら増分同期ジョブを投入する
- `singleEvents=true` を指定し、繰り返し予定を実体に展開する（毎週のジム等の取りこぼし防止）

### 同期窓

- **同期窓**は salon_timezone 基準で「現在 〜 **本日+60日の終日終端**（= 本日+61日 00:00 JST）」とする。RB の予約可能範囲（「本日+60日後の**終日まで**」。[booking.md](booking.md) Business Rules 2）と揃えるため、日付境界で定義する
- 「現在+60日」という壁時計オフセットでは**ずれる**。7/17 15:00 JST 時点で `timeMax` = 9/15 15:00 となり、予約可能な最終日 9/15 の 15:00〜24:00 の外部予定が同期範囲外＝busy 化されず、その枠が公開Web予約で空きとして売られる

### 全同期と増分同期

- **全同期**（syncToken を渡さない取得）は次の4契機で行い、`timeMin` = 現在、`timeMax` = 同期窓の終端を指定する
  1. 接続完了時（初回）
  2. 対象カレンダー変更時
  3. syncToken 失効（HTTP 410 Gone）時 — 保存済み syncToken を捨てて全同期し直す
  4. **同期窓の日次前進**（定期コマンド `google-calendar:refresh-sync`。下記）
- **増分同期**は `events.list` に保存済み `syncToken` **のみ**を渡す。`syncToken` は `timeMin` / `timeMax` / `q` / `orderBy` / `updatedMin` 等の絞り込みと**併用できない**（併用すると 400）ため、増分同期リクエストには一切の絞り込みを付けない（`singleEvents` は付けてよい）
- **同期窓の前進が必要な理由**: syncToken には初回全同期時に指定した `timeMin` / `timeMax` が固定的に紐づく。増分同期では窓を動かす手段が無く、放置すると接続時点の `timeMax`（= 接続日+60日）より先の予定は永久に届かない。接続から60日経てば busy 取り込みが事実上停止し、ダブルブッキング防止がエラーも出さずに失われる。これを避けるため日次の全同期で syncToken を張り直し、窓を当日基準へ進める

### ページングと syncToken の保存

- `events.list` は `maxResults=250`（既定値。本設計もこの既定を用いる）でページングされ、**`nextSyncToken` は最終ページにのみ**含まれる（中間ページは `nextPageToken` のみ）。`nextPageToken` が返る間は**同一パラメータ + `pageToken`** で取得を続け、`nextSyncToken` が返るページまで全ページを辿る
- ページングを実装しないと、1ページ目だけ取り込んだ状態で `nextSyncToken` が得られず sync_token が null のまま残り、push 通知のたびに全同期が走り続ける。かつ2ページ目以降の外部予定が busy 化されないまま `last_synced_at` だけが更新され、静かに劣化する（`singleEvents=true` で繰り返し予定を実体展開するため、`primary` カレンダーの60日分は 250 件を超えうる）
- **保存タイミング**: 取得した**全ページの適用が DB にコミットされた後に初めて** `sync_token` を更新する。適用途中で失敗した場合は `sync_token` を更新せず、次回は同じトークンから再実行する（先に保存すると、未適用の差分を飛び越えたトークンは 410 にもならないため、取りこぼしが恒久化する）
- 再実行で同じイベントが再適用されるが、busy は `(google_calendar_connection_id, google_event_id)` の upsert、RB 由来イベントは「`start` と `end` の両方が RB の予約と一致していれば no-op」により**冪等**である

### 全同期時の照合削除（reconcile）

- syncToken を渡さない全同期の応答には**削除済みイベントが含まれない**（`showDeleted` の既定は false。削除エントリを運ぶのは増分同期の差分ストリームのみ）。このため全同期だけでは、syncToken が失効していた間に Google 側で削除された予定に対応する busy ブロックが誰にも消されず、幽霊 busy が空き枠を恒久的に塞ぐ
- 全同期では、当該接続の**同期窓内の busy ブロックのうち今回の応答に現れなかった** `google_event_id` の行を削除する（差集合の刈り取り）。あわせて同期窓の外へ出た busy ブロックも削除する
- 刈り取りと再構築は**1トランザクション**で行う（同期中に busy が空になる瞬間を作らないため）

### 取得したイベントの扱い

- **削除イベント（`status=cancelled`）**: Google は削除イベントについて **`id` 以外のフィールドを返す保証が無く**、`extendedProperties`（マーカー）も含まれない。このためマーカーではなく **`google_event_id` の逆引き**で分岐する
  1. `reservations` の **`(salon_id, google_event_id)` 突合**に一致（`per_staff` では当該接続の担当 `user_id` の一致も条件に含める）→ RB 由来イベントの削除として扱う（Business Rules 5）。削除イベントはマーカーを持たないため、この突合が**唯一の判定手段**でもある
  2. `google_busy_blocks.google_event_id` に一致（当該接続内）→ busy ブロックを削除する
  3. いずれにも一致しない → 無視する
- **生存イベント（`status=confirmed` / `tentative`）**: **RB 由来かどうかの確定は `reservations` の `(salon_id, google_event_id)` 突合で行う**（`per_staff` モードでは当該接続の担当 `user_id` の一致も条件に含める）。`extendedProperties.private.rb_reservation_id` マーカーは**イベントを編集できる者なら誰でも書ける改竄可能な入力**であり、自己識別のヒント（ログ・デバッグ・突合前の絞り込み）に過ぎない。マーカーを権威として扱うと、他サロンの ID を書いたイベントを自分のカレンダーに作るだけで他テナントの予約を移動・キャンセルできる（テナント境界の破壊）
  - **突合成立（RB 由来）**
    - **`start` と `end` の両方**が RB の予約の値（`start_at` / `end_at`）と一致 → **no-op**（書き戻さない）
    - いずれかが不一致 → 下記「RB 由来イベントの反映」に従う
  - **突合不成立** → **外部予定（busy）として処理する**。マーカーの有無を問わない。「マーカーがあるので無視する」としてはならない（無視すると、他人が書いたマーカー付きイベントや、解除・カレンダー変更後に残った孤児イベントが busy にも予約にもならず、実際には塞がっている時間が公開予約で空きとして売られる）
  - **外部予定（busy）の処理** → busy ブロックとして upsert する。同期窓の外へ出た場合は busy ブロックを削除する
    - busy として扱わないイベント（Business Rules 10）に該当する場合は取り込まない。既存の busy ブロックがあれば削除する（`transparency` を後から `transparent` に変えた場合に幽霊 busy を残さないため）
    - 終日予定は `start.date` 〜 `end.date`（**排他**）を 1 ブロックとして取り込む（Business Rules 11）

### RB 由来イベントの反映

突合成立かつ `start` / `end` のいずれかが RB の値と不一致の場合、次の順に判定する。

1. **staleness ガード**: `event.updated`（Google 側の最終更新時刻）と `reservation.updated_at` を**UTC の instant として**比較し、`event.updated <= reservation.updated_at + 許容幅` なら **RB の方が新しい**とみなして **no-op**（Google 側の値は RB の変更がまだ Google に届いていないだけ、と解釈する）
   - このガードが無いと、管理画面での予約変更が送信同期ジョブのキュー待ちにある間に増分同期が走った場合、Google 上の**古い**時刻を「Google 側で移動された」と誤認して予約を巻き戻し、直後に送信同期がその巻き戻し後の値を Google へ書いて確定させる。管理画面の変更がエラーも警告も出ないままサイレントに消失する
   - 許容幅は、RB 側の更新から送信同期完了までの時差と両者の時計ずれを吸収するためのもの（設定値とする）
   - **Carbon で比較する際は `->utc()` を必須**とする（フェーズ1・2で実バグ化した再発性の罠。`event.updated` は RFC3339 で返る）
2. staleness ガードを通過した（= Google 側が新しい）場合、**反映するのは `start` のみ**とする。`end_at` は Google の `end` をそのまま採らず、**常に `start + menu.duration_minutes` で再導出**する（施術時間はメニューが決めるものであり、Google 上で端をドラッグして伸ばした結果を施術時間として採用すると、RB の枠計算・売上・メニュー定義と実データが乖離する）
3. **`end` の不一致は `start` が一致していても巻き戻し対象**とする（`start` 一致 + `end` 不一致 = Google 上で長さだけを変えられた状態。RB は施術時間を変えないため、RB の値で Google 側を `events.update` して戻す）
4. 移動先（再導出した `start` 〜 `end`）が他の予約・営業時間・busy と競合する場合は、予約を更新せず **RB の値で Google 側を巻き戻す**（Business Rules 4）

## watch チャネル

- チャネル作成時に `id`（ランダム）・`token`（webhook 検証用の秘密値）・`address`（`{API_URL}/api/google/calendar/webhook`）を指定する
- `address` は **HTTPS 必須**で、CA 署名された有効な証明書が必要（自己署名・信頼されない発行元・失効済み・ホスト名不一致の証明書はいずれも不可）。このため **`http://localhost` には Google が通知を送れず、ローカル開発では push 通知を受け取れない**。ローカルでの受信同期の検証は次のいずれかで行う
  - (a) HTTPS トンネル（ngrok 等）の URL を `address` に指定して watch を張る
  - (b) push を使わず、増分同期ジョブを artisan コマンドから直接投入して検証する
- Google が返す `resourceId` / `expiration` を保存する。チャネルの TTL は Google 側が決める（要求値より短くされうる）ため、**独自の TTL を仮定せず応答の `expiration` をそのまま保存する**
- Google にチャネルの**更新 API は存在しない**。更新は「新しい `id` で `channels.watch` を張り直し、旧チャネルを `channels.stop` する」で行う（要件・ADR で「チャネルを更新する」と呼ぶのはこの張り直しを指す）
- 定期コマンド `google-calendar:renew-channels` で期限前に張り直す（下記「定期コマンド」）
- 解除時・接続削除時は `channels.stop` でチャネルを停止する

## 定期コマンド

`routes/console.php` に登録する（フェーズ2の `reservations:send-reminders` と同じ方式。`config('app.salon_timezone')` 基準でスケジュールする）。

| コマンド | 実行時刻（JST） | 内容 |
|---------|---------------|------|
| `google-calendar:renew-channels` | 毎日 03:00 | 期限が迫った watch チャネルを張り直す |
| `google-calendar:refresh-sync` | 毎日 04:00 | syncToken を破棄した全同期で同期窓を当日基準へ進める |

### `google-calendar:renew-channels`

- 対象: `status=active` かつ `channel_expires_at < 現在 + 24時間` の接続
- 手順（**この順序を守る**）
  1. 旧 `channel_id` / `channel_resource_id` を退避する（接続レコードは1組しか保持しないため、先に上書きすると `channels.stop`（`id` + `resourceId` 必須）を呼べなくなる）
  2. 新しい `channel_id` / `channel_token` で `channels.watch` する
  3. 成功したら応答の `resourceId` / `expiration` で接続レコードを更新する
  4. 退避した旧チャネルを `channels.stop` する
- 先に stop すると、watch が失敗した場合に無通知の窓が空く。この順序なら新旧チャネルが一時的に併存するだけで通知は途切れない（増分同期は冪等のため二重通知でも問題ない。旧チャネルからの通知はレコード更新後「未知の channel_id」として 200 で捨てられる）
- 旧チャネルの `channels.stop` 失敗は**ログのみで無視**する（期限切れで自然消滅するため）
- `channels.watch` に失敗した場合は `status` を変えず、レコードも更新しない。次回実行でリトライする（再実行は新チャネルを張るだけなので安全）
- このコマンドが止まると push 通知が途絶え、同期がエラーも出さずに停止する。失敗はログに残し、監視対象とする

### `google-calendar:refresh-sync`

- 対象: `status=active` の全接続
- 保存済み `sync_token` を破棄して全同期（照合削除を伴う）を行い、応答の `nextSyncToken` を保存し直す
- 目的は同期窓の前進。syncToken には初回全同期時の `timeMin` / `timeMax` が固定的に紐づき増分同期では窓を動かせないため、日次でトークンを張り直す（[Business Rules 12](#business-rules)）

## webhook

- `POST /api/google/calendar/webhook`（認証なし・throttle なし。`v1` プレフィックス外）
- ヘッダ `X-Goog-Channel-ID` / `X-Goog-Channel-Token` / `X-Goog-Resource-ID` / `X-Goog-Resource-State` を読む
- 検証は次の**3段**とし、いずれかに該当したら**即 200 で終了**する（ログのみ。Google のリトライ暴走防止。フェーズ2の LINE webhook と同じ方針）
  1. `X-Goog-Channel-ID` が既知の接続の `channel_id` に一致しない（未知のチャネル）
  2. `X-Goog-Channel-Token` が当該接続の `channel_token` に一致しない
  3. `X-Goog-Resource-ID` が当該接続の `channel_resource_id` に一致しない
- 3 を欠くと、`channel_id` と `channel_token` を知る者が**任意のリソース**の通知を騙って同期を起動できる。この2値は RB が発行するため、チャネル張り直しの過渡期に旧値が残るなど漏出経路が存在する。`resourceId` の照合により「そのチャネルが実際に監視しているカレンダー」からの通知であることまで確認する
- `channel_token` は **CSPRNG 由来の32文字以上**とし、比較は**タイミング攻撃耐性のある `hash_equals`** で行う（`===` による短絡比較は先頭一致長を実行時間として漏らす）
- `X-Goog-Resource-State: sync`（チャネル開設直後の疎通通知）は何もせず 200 を返す
- 検証を通ったら当該接続の増分同期ジョブを投入して 200 を返す。ジョブは接続単位で **`ShouldBeUniqueUntilProcessing`**（`uniqueId` = 接続ID、`uniqueFor` = 10分）とする（`ShouldBeUnique` ではない理由は Non-Functional Requirements 4）

## 空き枠への反映（busy）

- `AvailabilityService` の空き枠計算と `PublicBookingService` の枠検証（サーバ側再検証）で、対象スタッフ（shared モードならサロン全体）の busy ブロックと重なる枠を**予約不可**とする
- 管理側（`/api/v1`）の予約登録は busy でも**登録可能**とする（[ADR-023](../decisions/ADR-023-reservation-core.md) の「管理側は営業時間外も許容」と同じ思想。サロンの裁量を優先）。公開側は不可とする
- 二重予約防止の advisory lock の取得順序は既存を維持する（phone → スタッフ）。busy 判定はロック内の重複チェックと**同じ箇所**で行う
- busy ブロックは**タイトル等の内容を保存しない**（開始・終了時刻のみ）。RB の予約カレンダー上は「外部予定」として時刻のみ・グレー表示する

## 再接続導線（トークン失効）

- `access_token` の期限切れ時は refresh_token で更新する
- refresh_token が失効・失効取消（ユーザーが Google 側でアクセス解除）された場合、接続を `needs_reconnect` 状態にし、UI に再接続を促す
- `needs_reconnect` の接続に対する同期ジョブは**リトライせず打ち切る**

---

# Business Rules

1. **1接続 = 1カレンダー**: 1つの接続は Google アカウントの1カレンダー（既定 `primary`）を対象とし、そのカレンダーに対して書き込みと読み取りの両方を行う。複数カレンダーの同時読み取りは行わない
2. **モードの意味論**: `per_staff` では「そのスタッフ担当の予約を書き、そのカレンダーの外部予定はそのスタッフのみを塞ぐ」。`shared` では「全スタッフの予約を1本のカレンダーに書き（題名に担当スタッフ名を含む）、そのカレンダーの外部予定はサロン全体を塞ぐ」。モード切替は既存接続の全解除を伴う
3. **エコー防止と RB 由来判定の権威**: RB が作成した Google イベントには `extendedProperties.private.rb_reservation_id` / `rb_salon_id` を必ず付与する。ただしマーカーは**改竄可能な自己識別ヒント**に過ぎず、判定の権威ではない。**RB 由来かどうかの確定は `reservations` の `(salon_id, google_event_id)` 突合**（`per_staff` では担当 `user_id` の一致も条件に含める）で行う。突合が成立し、Google 側の **`start` と `end` の両方**が RB の予約の値と一致していれば **no-op** とし、書き戻さない。これによりループが収束する
   - **突合が成立しないマーカー付きイベントは「無視」ではなく「外部予定（busy）として処理」する**。マーカーを権威にすると、他サロンの ID を書いたイベントを作るだけで他テナントの予約を操作でき（テナント境界の破壊）、無視すると孤児イベント・他人のマーカー付きイベントが busy にならず、塞がっている時間が公開予約で空きとして売られる
   - **削除イベント（`status=cancelled`）はマーカーで判定できない**。Google は削除イベントについて `id` 以外のフィールドを返す保証が無く、`extendedProperties` は含まれないため、`google_event_id` の逆引き（`reservations` → `google_busy_blocks` の順）で判定する。この点でも突合が唯一の判定手段となる（受信同期の節を参照）
4. **競合時は RB が真実／反映は start のみ**: Google 側で RB 由来イベントが移動された場合、移動先が他の予約・営業時間・busy と競合しなければ予約を更新する。競合する場合は RB の値で Google 側を巻き戻す
   - **反映するのは `start` のみ**とし、`end_at` は Google の `end` を採らず**常に `start + menu.duration_minutes` で再導出**する（施術時間はメニューが決める）。したがって **`end` の不一致は `start` が一致していても巻き戻す**（Google 上で長さだけを変えられた場合）
   - **staleness ガード**: 反映の前に `event.updated` と `reservation.updated_at` を **UTC の instant** として比較し、`event.updated <= reservation.updated_at + 許容幅` なら RB の方が新しいとみなして no-op とする。これが無いと、送信同期ジョブのキュー待ち中に走った増分同期が Google 上の古い値で予約を巻き戻し、管理画面の変更がサイレントに消失する（Carbon は `->utc()` 必須）
5. **Google 側削除 → 予約キャンセル**: `google_event_id` が `reservations` に一致する削除イベントを受信した場合、対応する予約を status=cancelled にする（レコードは残す。フェーズ1のキャンセル定義に従う）
   - ただし**対象予約の status が既に `cancelled` / `no_show` の場合は no-op** とする。これらは「RB 側で cancelled / no_show にした → 送信同期が Google イベントを削除した」エコーであり、そのまま適用すると `no_show`（無断キャンセル＝来店実績・顧客の信用管理に使う区別）が `cancelled` に上書きされて業務データが壊れる。status が `reserved` / `visited` の場合のみ `cancelled` にする
   - 併せて、送信同期は削除成功時に `google_event_id` を null クリアするため、通常はこのエコーが逆引きでヒットしない（二重の防御）
6. **外部予定は busy 止まり**: `reservations` との突合が成立しないイベント（マーカーの有無を問わない）は busy ブロックとして取り込むのみで、RB の予約には昇格させない
7. **busy は時刻のみ保存**: busy ブロックはタイトル・説明・出席者等の内容を一切保存せず、開始・終了時刻のみを保持する（プライバシー配慮）。RB のカレンダー表示上は「外部予定」と表示する
8. **管理側は busy を上書き可・公開側は不可**: 管理側（`/api/v1`）の予約登録は busy と重なっていても登録可能とする。公開Web予約（`/api/public/v1`）は busy と重なる枠を予約不可とし、空き枠計算からも除外する
9. **繰り返し予定の展開**: 受信同期は `singleEvents=true` で繰り返し予定を実体に展開して取得する（毎週の定例予定を取りこぼさないため）
10. **busy として扱わないイベント**: 次のイベントは busy ブロックに取り込まない
    - `transparency=transparent`（予定ありにしない）— Google 側で「予定なし」と表明されている以上、RB の空き枠も塞がない
    - `eventType` が `workingLocation`（勤務場所）/ `birthday`（連絡先の誕生日）— `primary` カレンダーに流れてくる特殊イベントで、`singleEvents=true` により終日イベントとして実体展開される。取り込むと丸1日が塞がり「予約を受けられる日が消える」
    - 接続アカウント本人の `attendees[].responseStatus` が `declined`（辞退した会議）— 辞退済みでも `opaque` のまま残るため
11. **終日予定の扱い**: 終日予定は `start.date` / `end.date` で表現され、**`end.date` は排他**（7/20 のみの終日予定は `start.date=2026-07-20`, `end.date=2026-07-21`）。`start.date` の salon_timezone 00:00 から `end.date` の salon_timezone 00:00 までを **1本の busy ブロック**として取り込む。連休・旅行・全体研修のように**複数日にまたがる終日予定も1レコード**で表現する（`(google_calendar_connection_id, google_event_id)` の unique により日ごとの分割はできない。また `singleEvents=true` は「繰り返し」の展開であって複数日スパンは分割しない）
12. **同期窓**: 同期窓は salon_timezone 基準で「現在 〜 **本日+60日の終日終端**（= 本日+61日 00:00 JST）」とし、全同期の `timeMax` にこの終端を用いる（RB の予約可能範囲 =「本日+60日後の終日まで」と揃える。[booking.md](booking.md) Business Rules 2）。「現在+60日」という壁時計オフセットでは最終日の一部が範囲外となり、その枠だけ busy 化されない
13. **同期窓は日次で前進させる**: syncToken には初回全同期時の `timeMin` / `timeMax` が固定的に紐づき、増分同期では窓を動かせない（syncToken と `timeMin` / `timeMax` は併用不可）。放置すると接続時点の `timeMax` より先の予定が永久に届かないため、日次の定期コマンド `google-calendar:refresh-sync` で syncToken を破棄した全同期をやり直し、窓を当日基準へ進める
14. **全同期は照合削除を伴う**: syncToken を渡さない全同期の応答には削除済みイベントが含まれない（`showDeleted` の既定は false）。このため全同期では、同期窓内の busy ブロックのうち応答に現れなかったものを削除する（差集合の刈り取り）。刈り取りと再構築は1トランザクションで行う
15. **書き込み先が変わる変更はイベントを作り直す**: `per_staff` で担当スタッフが変更された場合、または対象カレンダーが変更された場合は、旧接続・旧カレンダーのイベントを削除し、新接続・新カレンダーへ作成し直して `google_event_id` を差し替える（イベントIDはアカウント・カレンダーをまたいで `events.update` できないため）

---

# Non-Functional Requirements

1. **トークンの暗号化保存**: `access_token` / `refresh_token` は Laravel の `encrypted` cast でDBに暗号化保存する（フェーズ2の line_settings と同じ方針）。APIレスポンス・画面ではトークンを一切返さない
2. **webhook 検証**: webhook は認証なし・throttle なしとし、**3段の検証**で守る — (1) `X-Goog-Channel-ID` が既知の `channel_id` に一致すること (2) `X-Goog-Channel-Token` が当該接続の `channel_token` に一致すること (3) `X-Goog-Resource-ID` が当該接続の `channel_resource_id` に一致すること。`channel_token` は watch チャネル作成時に **CSPRNG 由来の32文字以上**で発行し、比較は **`hash_equals`**（タイミング攻撃耐性）で行う。いずれの検証に失敗してもレスポンスは 200 を返す（Google のリトライ暴走防止。ログには残す）
3. **Google 審査と 100 ユーザー上限の前提**: 要求スコープ `calendar.events` は機密スコープであり、一般公開には Google の審査が必要。**審査は実装のブロッカーではない**（未審査でも 100 ユーザーまで動作するため実装・検証は可能）。ただし 100 は**プロジェクト生涯の累計でリセット不可**であり、本番運用の前提として審査（公開ドメイン上のプライバシーポリシー・Search Console でのドメイン認証・OAuth 同意画面を映したデモ動画・スコープ正当性の説明。公式は最大10日、実務報告は4〜6週間）を通す必要がある。審査申請作業そのものはデプロイ時の手続きとして扱い、リスク・前提は [ADR-025](../decisions/ADR-025-google-calendar-sync.md) に記録する
4. **API クォータへの配慮**: 変更契機の同期は syncToken による増分同期を基本とし、push 通知を採用してポーリングを行わない。全同期は「初回接続時・対象カレンダー変更時・410 Gone・日次の同期窓前進」の4契機に限り、取得範囲は同期窓（現在〜本日+60日の終日終端）に限定する（接続あたり日次1回の全同期は窓の陳腐化を防ぐための最小コストであり、ポーリングではない）。受信同期起因の予約更新では送信同期を投入せず、無駄な書き戻しの往復を発生させない
   - 増分同期ジョブは接続単位で **`ShouldBeUniqueUntilProcessing`**（`uniqueId` = 接続ID、`uniqueFor` = 10分）とし、短時間に複数の push 通知を受けても同期が多重実行されないようにする
   - `ShouldBeUnique` を用いない理由: 同ロックは**ジョブの処理完了まで**保持されるため、同期実行中に届いた push 通知が破棄される。次の変更が起きるまで通知は来ないため最後の変更が反映されないまま滞留し、外部予定が busy にならないまま公開予約が入る（本フェーズが防ごうとしている事故そのもの）。`ShouldBeUniqueUntilProcessing` は処理開始時にロックを解放するため、実行中の通知は次の1本としてキューイングされる
   - `uniqueFor` を明示する理由: 未指定だとロックが無期限になり、ワーカーが異常終了した際にロックが残留して当該接続の同期が恒久的に停止する
5. **Google API クライアント**: 公式 SDK は使わず Laravel HTTP クライアントで実装する（必要なのは token 交換 / `events.list` / `events.insert`・`update`・`delete` / `calendarList.list` / `channels.watch`・`stop` のみ。依存最小化。フェーズ2の LINE と同じ判断。[ADR-025](../decisions/ADR-025-google-calendar-sync.md)）。ベースURLは config から取得し、テストで `Http::fake` 可能にする
6. **同期ジョブの信頼性**: 送信同期はキュージョブ経由（`afterCommit()`）・tries=3・バックオフ付きとする。ただし接続が `needs_reconnect` の場合、および 404 / 410・その他の 4xx はリトライせず打ち切る（回復にユーザー操作が必要、または再試行しても結果が変わらないため。ステータス別の扱いは「送信同期」節の表を正とする）
7. **受信同期の冪等性**: 増分同期は「全ページ適用 → コミット → sync_token 更新」の順序とし、途中失敗時は同じトークンから再実行する。再実行での再適用は busy の upsert（`(google_calendar_connection_id, google_event_id)`）と RB 由来イベントの「`start` / `end` 両方一致 → no-op」および staleness ガード（`event.updated <= reservation.updated_at + 許容幅` → no-op）により冪等であることを要件として保証する

---

# API Requirements

エンドポイントの詳細は [docs/api/endpoints.md](../api/endpoints.md) / [docs/api/openapi.yaml](../api/openapi.yaml) を正とする。

## 管理側（`/api/v1`・auth:sanctum）

- GET `/google-calendar`（モード + 接続一覧〈メール・対象カレンダー・状態・最終同期〉。トークンは返さない）
- PUT `/google-calendar/mode`（モード設定。既存接続がある状態での変更は全解除を伴う）
- POST `/google-calendar/auth-url`（OAuth 開始。state を発行しキャッシュ保存、認可URLを返す）
- GET `/google-calendar/callback`（**認証なし**。Google からのリダイレクト。code + state を検証 → トークン交換 → 接続保存 → watch 開始 → 初回同期投入〈受信 = 全同期 / 送信 = 同期窓内の既存予約の書き出し〉→ SPA へ 302）
- GET `/google-calendar/connections/{id}/calendars`（接続アカウントのカレンダー一覧。選択用）
- PUT `/google-calendar/connections/{id}`（対象カレンダー変更。syncToken 破棄・watch 張り直し・busy 再構築に加え、**旧カレンダーの RB 由来イベント削除と新カレンダーへの初回送信同期**を伴う）
- DELETE `/google-calendar/connections/{id}`（解除。`channels.stop` → refresh_token の revoke → busy ブロック削除 → **対象範囲の予約の `google_event_id` を null クリア** → 物理削除の5手順。`channels.stop` / revoke の失敗はログのみで続行し、RB 側の後始末は必ず完遂する。Google 側のイベントは削除しない）
- GET `/google-calendar/busy-blocks?from=&to=`（予約カレンダーへの「外部予定」表示用。期間内の busy ブロックを返す。**レスポンスは `id` / `start_at` / `end_at` / `user_id` のみ**で、タイトル等の**内容は一切返さない**〈busy は時刻のみ保存の原則。Business Rules 7。`id` はリソース識別子であり内容ではない〉。`user_id` が null は shared モード = サロン全体を塞ぐ外部予定を表す）
  - GET `/reservations` への同梱ではなく独立エンドポイントとする（busy は予約ではなく、期間指定で引く別リソースであるため）

## Google webhook

- POST `/api/google/calendar/webhook`（認証なし・throttle なし・channel_token 検証必須）

---

# UI Requirements

画面設計は以下を正とする。

- [docs/ui/settings-google-calendar.md](../ui/settings-google-calendar.md) — Googleカレンダー連携設定（/settings/google-calendar）。モード選択・接続/解除・対象カレンダー選択・状態表示（アカウント・対象カレンダー・最終同期）・再接続導線
- 予約カレンダー（/reservations）への「外部予定」表示（時刻のみ・グレー表示）

---

# Out of Scope（再掲）

フェーズ3では実装しない。

- 複数カレンダーの同時読み取り（1接続 = 1カレンダー）
- Google 側で新規作成された予定の RB 予約への昇格（外部予定は busy 止まり）
- 出席者・会議室・Google Meet 連携
- 参加者への招待メール送信
- カレンダー共有設定の操作
- Google 審査の申請作業そのもの（デプロイ時の手続き。[ADR-025](../decisions/ADR-025-google-calendar-sync.md) に前提として記録）
- Outlook / iCloud 等の他カレンダー

---

# References

- [docs/requirements/reservation.md](reservation.md)（フェーズ1要件）
- [docs/requirements/booking.md](booking.md)（フェーズ2要件）
- [docs/db/ERD.md](../db/ERD.md)
- [docs/api/endpoints.md](../api/endpoints.md)
- [docs/decisions/ADR-025-google-calendar-sync.md](../decisions/ADR-025-google-calendar-sync.md)
- [docs/decisions/ADR-023-reservation-core.md](../decisions/ADR-023-reservation-core.md)
- [docs/roadmap/ROADMAP.md](../roadmap/ROADMAP.md)
