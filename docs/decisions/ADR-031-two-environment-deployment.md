# ADR-031: develop 環境と本番環境の分離

## Status

Accepted

---

## Date

2026-09-07

---

## Context

[ADR-022](ADR-022-deployment.md) で用意したデプロイ先は本番の1つだけで、本番公開（2026-07-22）以降も
そのまま運用してきた。その結果、次のいずれも実顧客のデータが入った環境で行うしかない状態にある。

- 見込み客に画面を見せる。空のアカウントでは何も伝わらないため、実データの画面を見せることになる。
- 未リリースのコードを、ローカル以外で動かして確かめる。
- [ADR-029](ADR-029-subscription-billing.md) の課金導線を通しで見せる。Live Mode しか無いので、
  デモのつもりの Checkout が**実際の請求**になる。

分けたい理由は2つあり、性質が違う。

- **デモ**: 見込み客に安全に見てもらう。デモデータが入っていて、壊れても誰も困らず、
  サブスクリプションを Stripe のテストモードで無料で試せる。
- **先行検証**: `main` に入れる前のコードを、ローカルではない環境で動かす。

この2つは本来要求が逆で（前者は壊れてはいけない、後者は壊れてよい）、通常は別環境に分ける。
MVP 段階の運用体制（個人開発・追加課金なし）では1つに載せる。作業量の大半はデモ側の要求
（データのリセット、外部連携の分離、Stripe のテストモード）が占める。

着手にあたり、以下が確認された。

- **`render.yaml` の `plan: free` が本番を降格させる指示になっている。** Web サービスと PostgreSQL は
  既に有料プランへ移行済みだが、Blueprint にはどちらも `plan: free` と書かれたままだった。Render は
  ダッシュボードでの変更が Blueprint と衝突した場合に**同期のたびに上書きされる**と明記しており、
  かつ同期は差分ではなく**ファイル全体**を適用する。Auto Sync は既定 ON で、既存 Blueprint には
  同期前の差分確認画面がない。つまり develop サービスを追記して push した同じ同期で、本番の
  Postgres が 256MB へ落ち、「作成30日で失効 → 猶予14日 → 削除」の経路に乗る。
- **`APP_ENV` が Stripe のモードを決めている。** `StripeClient::assertModeMatchesEnvironment()` は
  `app()->environment('production')` の二値判定で、production なら Live キー以外を、
  production 以外なら Live キーを拒否する。develop をテストモードにする手段は
  `APP_ENV` を production 以外にすることしかない。
- **キューワーカーがどの環境にも存在しない。** 既定の `database` ドライバではジョブが `jobs` テーブルに
  溜まったまま実行されない。かといって `sync` にすると、`PublicBookingService` が `DB::transaction` の
  内側で dispatch している LINE 送信ジョブが**コミット後のコールバックの中で同期実行される**。
  `afterCommit()` を付けていても `sync` ドライバはジョブの例外を呼び出し元へ投げ返すため、
  **予約行はコミットされたまま HTTP 500 が返る**。見込み客は「予約が失敗した」と判断して再送信する。
- **シーダーのデモデータは数日で腐る。** 予約日時を投入時点の `today()` で固定するため、
  「本日の予約」が空になりダッシュボードが死んで見える。再実行しても各レコードは `firstOrCreate` で、
  古い行は消えずに積み上がる。さらに Stripe デモは1人目でしか成立しない
  （Checkout 完了で `stripe_subscription_id` が入り、以降は「すでに契約中です」で弾かれる）。
- **ブランチ運用の記述がリポジトリ内で矛盾している。** [ADR-010](ADR-010-git-workflow.md) と
  `CONTRIBUTING.md` は「GitHub Flow、`develop` なし」、`README.md` と `docs/standards/git.md` は
  「`main` → `develop` → `feature/*`」と書いていた。1環境のあいだは実害が無かったが、2環境に分けると
  「どのブランチがどこへ出るか」が運用の根幹になるため、どちらかに寄せる必要がある。
- **予算の制約**: 追加課金なしで構成する。

---

## Decision

**`develop` ブランチを develop 環境へ、`main` を本番へデプロイする2環境構成にし、
develop は DB・オブジェクトストレージ・Stripe・LINE・Google・OpenAI のすべてを本番と分離する。**

| | production | develop |
|---|---|---|
| ブランチ | `main` | `develop` |
| Render Web | `realize-beauty-api`（有料・現状維持） | `realize-beauty-api-dev`（free） |
| DB | Render Managed PostgreSQL（現状維持） | Neon 無料プラン（AWS Singapore）を `DB_URL` で |
| `APP_ENV` | `production` | `staging` |
| `APP_KEY` | 既存 | **別の値** |
| Stripe | Live Mode | Test Mode（named sandbox） |
| `QUEUE_CONNECTION` | 現状維持（別課題） | `deferred` |
| R2 | 既存バケット | **専用バケット + 専用トークン** |
| LINE / Google / OpenAI | 本番の資格情報 | **専用の資格情報** |
| フロント | Worker `realize-beauty` | Worker `realize-beauty-develop` |

### 1. ブランチと環境を1対1に対応させる

`feature/* → develop → main`。日常の PR 先は `develop` で、develop 環境で確かめてから
`develop → main` で本番へ出す。ADR-010 の「GitHub Flow、`develop` なし」を本 ADR で改め、
`CONTRIBUTING.md` / `README.md` / `docs/standards/git.md` をこの形に揃える。

`.github/workflows/ci.yml` の push トリガに `develop` を追加した（`pull_request` は元から全ブランチが対象）。

**`main` への直接コミットは、この構成では本番への直接デプロイを意味する。**

### 2. develop の `APP_ENV` は `staging` にする

これが Stripe をテストモード側に倒す唯一の手段である。`STRIPE_ENFORCE_MODE=false` で検査を
無効化する道は取らない —— この検査は「ローカルから本番の Stripe を叩けてしまう」状態を
作らないための最後の砦であり、用途は自動テストに限る（ADR-029）。

`APP_ENV` が production かどうかで挙動が変わるのは、アプリ側では次の4箇所に限られる。

- `StripeClient::assertModeMatchesEnvironment()` — Live / Test キーの取り違え検査
- `stripe:check` の「想定モード」表示と Price ID の判定
- `DatabaseSeeder` の本番ガード
- `demo:reset` の本番ガード

**副作用として、フレームワーク側の `ConfirmableTrait` も production 限定である。**
`staging` では `migrate:fresh` / `db:wipe` / `db:seed` / `key:generate` が**確認なしで走る**。
`DatabaseSeeder` のガードも `APP_ENV` を見ているため、ローカルから `DB_URL` を本番へ向けた実行は
防げない。無料プランには Shell が無く運用コマンドをローカルから実行することになるので、
**最も安全網の薄い操作が日常操作になる**。この穴は後述の `demo:reset` が接続先の実体を
検査することで塞ぐ。

### 3. develop の DB は Neon の無料プラン

Render の無料 PostgreSQL は1ワークスペースに1つしか置けず、かつ**作成から30日で失効する**
（猶予14日ののち削除）。デモ環境が月次で消えるのは運用として成立しない。

Neon の無料プランは 0.5GB ストレージ / 100 CU 時間・月で**期限がない**。5分のアイドルで
コンピュートが停止するが、次の接続で自動復帰する（数百ms）。この自動復帰が採用理由で、
手動で解除するまで止まったままになる無料 DB では、週明けの初回デプロイが必ず失敗する。

- リージョンは **AWS Singapore**。Render に日本リージョンがなく、Tokyo に置くとアプリ⇔DB が
  約70ms 離れる。Laravel は1リクエストで多数のクエリを投げるため、同じ側に寄せる。
- 接続は**直接エンドポイント**（ホスト名に `-pooler` を含まないもの）を使う。
  アプリがセッションで `search_path` と時刻を設定するため。
- Render の `fromDatabase` 参照は使えないので `DB_URL` を渡す。`DB_SSLMODE=require` を
  明示し、接続文字列のクエリと config の既定値（`prefer`）が競合しないようにする。

**起動時マイグレーションにリトライを入れた。** `backend/docker/entrypoint.sh` は `migrate --force` を
`set -e` の下で一度だけ実行していたため、コールドスタート中の初回接続がタイムアウトすると
コンテナが起動しない。最大5回・3/6/9/12秒のバックオフで再試行し、全試行が失敗したら
従来どおり非ゼロで抜ける（壊れたマイグレーションを黙って無視しない）。本番の DB 再起動時にも効く。

### 4. R2 は共用せず develop 専用バケットを作る

当初は共用で合意していたが、検証の結果、分離に変えた。

R2 は**バケット単位の課金がない**（料金は保存量とオペレーション数）ため、分けても費用は変わらない。
一方、共用にすると `R2_PATH_PREFIX`（S3 ディスクの `root`）が必要になる。この仕組み自体は
`put` / `get` / `delete` / `exists` / 一覧 / `temporaryUrl` の全経路で動くことを確認したが、採用しない。

- **未設定時に fail-open する。** プレフィックスが空だと develop の書き込みと削除が
  そのまま本番のキー空間に載る。エラーにならない。
- **R2 の API トークンはバケット単位のスコープ**で、プレフィックスは権限の境界にならない。
- `PhotoRepository` の `Storage::delete()` は実削除であり、事故は読み取りではなく破壊になる。
- 見知らぬ人物がデモ環境からアップロードする写真を、本番の写真ストレージに
  同一資格情報で受け入れることになる。

同じ費用でコード変更ゼロの分離が取れるので、そちらを採る。

### 5. develop の `QUEUE_CONNECTION` は `deferred`

`sync` は使えない（Context のとおり、LINE 障害が予約 API の 500 になる）。`deferred` は
`config/queue.php` に定義済みで、レスポンス送出後に同一プロセスで実行される。コード変更なしで採用できる。

**本番側は変更しない。** キューワーカーの不在は本 ADR より前からの課題で、別途扱う。

### 6. 外部サービスの資格情報はすべて別にする

- **LINE**: develop 専用の Messaging API チャネルを作る。1つのチャネルには Webhook URL を
  1つしか設定できないため、本番のチャネルを使い回すと**本番の Webhook が develop に向く**。
  なおチャネルの認証情報は環境変数ではなくサロンごとの暗号化列に入る（ADR-024）ので、
  「専用の資格情報」とは「別チャネルを作り、デモ環境の設定画面から貼る」ことを意味する。
- **Google**: develop 専用の OAuth クライアント。承認済みリダイレクト URI は develop の
  `{APP_URL}/api/v1/google-calendar/callback`。
- **OpenAI**: develop 専用のキー。AI 要約はリクエスト内で同期的に課金されるため、
  使用量と予算上限を本番と混ぜない。
- **`APP_KEY`**: 本番とは必ず別の値にする。鍵を分けておけば、万一 develop に本番の DB ダンプを
  入れても LINE のチャネルトークンや Google のリフレッシュトークン（暗号化列）を**復号できない**。
  制約ではなく安全装置である。
- **`CORS_ALLOWED_ORIGINS` / `FRONTEND_URL`**: 互いの URL を相手側に足さない。
  `FRONTEND_URL` を本番で誤ると、実顧客の予約リンクや Stripe の戻り先がデモ環境に飛ぶ。

### 7. `render.yaml` に既存リソースの `plan` を書かない

Blueprint 仕様は、既存リソースで `plan` を省略した場合に**現行プランを保持する**と明記している。
実プラン ID を書き写すより、書かないほうが安全である —— ダッシュボードで昇格したときに
ファイルの追随を忘れても、降格指示にならない。

- 既存の Web サービスと PostgreSQL からは `plan` を削除した。
- 新規リソース（`realize-beauty-api-dev`）には `plan: free` を**明示する**。
  新規で省略すると既定の `0.5c-512mb`（有料）になる。
- 既存の本番サービスに `branch: main` を明示した。Blueprint 仕様上、`branch` の省略は
  リポジトリの既定ブランチを指す。ブランチに束縛された兄弟サービスが増える状況で
  暗黙のままにしない。

`sync: false` の値は**既存 Blueprint に足したサービスでは入力を促されない**（Render が尋ねるのは
初回作成時だけ）。develop の全シークレットは作成後にダッシュボードで手入力する。
未入力のまま起動すると `entrypoint.sh` の `set -e` + `migrate --force` でコンテナが落ち、
劣化ではなく起動不能になる。

### 8. Stripe は named sandbox を使い、Webhook で `livemode` を検査する

- **named sandbox を使う。** レガシーのテストモードは Dashboard 設定の一部を Live と共有するため、
  テスト側のカスタマーポータル設定をいじると Live 側が変わりうる。
- **`livemode` 検査を追加した。** それまで `livemode` はコード中に1度も現れておらず、モードの分離は
  エンドポイントごとの `whsec_` だけに依存していた。本番の `whsec_` を develop に貼り間違えると
  Live のイベントが develop の DB に適用される。両 DB のサロン ID は 1 から始まるため、
  `metadata.salon_id` の解決先は**必ず存在してしまう**。`StripeWebhookService` は dispatch の前に
  イベントの `livemode` と `str_starts_with(config('billing.stripe.secret'), 'sk_live_')` の一致を検査し、
  不一致なら `skipped` として記録して 200 を返す。ペイロードに元から入っている値なので追加コストはない。
- **Stripe 例外を利用者に読める形で返す。** `StripeConfigException` と `StripeApiException` は素の
  `RuntimeException` で `render()` を持たず、`APP_DEBUG=false` では `{"message":"Server Error"}` になり、
  SPA のトーストがその英語をそのまま出していた。初回セットアップで最も起きやすい誤設定
  （テスト側のポータル設定が未保存 / Price ID のモード違い / キーとモードの不一致）はすべてここに落ちる。
  `FeatureRequiredException` と同じ流儀で `render()` を実装し、**設定不備は 503、Stripe API 側の失敗は 502**、
  いずれも固定の日本語メッセージを返す。原因の詳細はログに残す（利用者へは出さない）。

### 9. `php artisan demo:reset` でデモを初期状態に戻す

```
php artisan demo:reset [--plan=lite|standard|pro] [--force --expect-database=<name>]
```

- `app()->isProduction()` なら即座に拒否する。
- 接続先の**ホストとデータベース名を表示し、データベース名の入力を求める**。`--force` を使う場合は
  `--expect-database` が実際の接続先と一致することを必須にする。`APP_ENV` ではなく
  **接続先の実体**を見るのが要点で、ローカルから `DB_URL` を向け違えた場合を捕まえられるのは
  この検査だけである。
- 実処理は `migrate:fresh --force` + `db:seed --force`。シーダーは実行時の `today()` で予約を作るため、
  これで日付が現在に戻り、古い行も残らない。`stripe_subscription_id` も消えるので、
  次の見込み客がまた Checkout を通せる。
- `--plan` は既定 `pro`（全機能を見せられる状態）。`lite` にするとアップグレード導線を見せられる。
- Shell の無い無料プランのため、実行はローカルから develop の `DB_URL` に向けて行う。
  **デモの前と、見せる相手が変わるたびに叩く運用**とし、cron 化はしない
  （Render の cron サービスは有料で、「追加課金なし」に反する）。

### 10. フロントは Worker を分け、環境バッジを出す

`frontend/wrangler.jsonc` に `"env": { "develop": {} }` を足すだけにする。Worker 名は
wrangler が `realize-beauty-develop` を導出するので書かない。`assets` と `compatibility_date` は
継承されるキーなので env ブロックに再掲しない（再掲は差異の発生源になる）。

- `wrangler` を devDependency に固定した。Workers Builds は package.json の wrangler を使うため、
  未指定だとビルドごとにその日の `npx wrangler` が引かれる。
- **env を定義した以上、本番の deploy は `wrangler deploy --env=""` と書く。** 引数なしの
  `wrangler deploy` は「環境が定義されているのに対象が指定されていない」と警告し、
  `--env production` は `realize-beauty-production` という**別の Worker** を作ってしまう。
  `--env=""` は警告を出さず、Worker 名 `realize-beauty` に解決される。
- **非 production ブランチのビルドは有効にしない。** 既定の `wrangler versions upload` は
  バージョンごとに別ホスト名を払い出し、完全一致の CORS 許可リストが毎回弾く。
- `VITE_ENV_LABEL` が設定されているときだけ、`EnvBadge` が画面上部にバッジを描画する
  （`App.vue` の公開／管理の分岐より前に置く）。develop のログイン情報は見込み客に渡すことになるため、
  本番と見分けが付く必要がある。本番ビルドでは未設定なので何も描画されない。

---

## Alternatives Considered

### Render に有料の PostgreSQL をもう1つ立てる（$6/月）

同一ベンダで完結し、`fromDatabase` 参照がそのまま使えて Blueprint が素直になる。
採用しなかったのは「追加課金なし」という前提を満たさないため。デモ用途の DB に月額を払うより、
無料で期限のない選択肢を先に試す。

### 既存の PostgreSQL インスタンス内に `CREATE DATABASE` する

追加費用ゼロで最も手間が少ない。しかし**接続数と PITR はインスタンス単位**であり、develop の暴走
（デモ中の連打、`migrate:fresh` の反復、ローカルからの一括処理）が本番の接続を枯渇させる。
バックアップの粒度も分けられない。分離の目的そのものを損なう。

### Render Preview Environments

PR ごとに一式が立ち上がる仕組みで、先行検証にはよく合う。だが**URL が PR ごとに変わる**ため、
完全一致の CORS 許可リスト（ADR-022）に載せられない。許可を緩めればフェイルクローズの設計を崩す。
デモ用の常設 URL としても使えない。

### `main` だけを使い、develop 環境へは `main` から自動、本番へは手動昇格

ブランチを増やさずに済む。しかし「本番へ出す前に検証する」場所が `main` になり、
未検証のコードが `main` に乗ることを前提にした運用になる。`main` を常にリリース可能に保つという
ADR-010 の原則と両立しない。

### R2 を共用し `R2_PATH_PREFIX` でキー空間を分ける

Decision 4 に記載。同じ費用でバケットを分けられるため、fail-open するプレフィックスに頼る理由がない。

### `STRIPE_ENFORCE_MODE=false` にして `APP_ENV=production` のまま Test キーを使う

`APP_ENV` を変えずに済むので `ConfirmableTrait` の副作用も避けられる。しかしこの検査は
「ローカルから本番の Stripe を叩けてしまう」状態を作らないための最後の砦であり（ADR-029）、
デモの都合で外すと、その後どの環境でも取り違えを検出できなくなる。副作用のほうを
`demo:reset` のガードで受け止める。

### `envVarGroups` で共通の環境変数を集約する

2つのサービスで値が同じリテラルは9個程度で、集約しても記述量はほとんど減らない。
一方で「どのサービスがどのグループを参照しているか」という間接参照が増える。割に合わない。

---

## Consequences

### メリット

- 見込み客に見せる環境で、**実顧客のデータにも実際の請求にも触れない**。Stripe は Test Mode の
  named sandbox で、カードはテストカードを使う。
- デモが壊れても本番に影響しない。`demo:reset` でいつでも初期状態に戻せる。
- 未リリースのコードを、ローカルではない環境（Docker・Render・実際の外部連携）で確かめてから
  `main` へ出せるようになった。
- `render.yaml` から `plan` を落としたことで、Blueprint 同期が本番を降格させる経路が消えた。
- `livemode` 検査と `demo:reset` の接続先検査により、**環境の取り違えが静かに成立しない**。
  従来は `whsec_` の貼り間違いも `DB_URL` の向け違えも、エラーを出さずに通っていた。
- Stripe の設定不備が日本語で表示されるようになり、初回セットアップの誤りを画面から切り分けられる。

### デメリット・注意点

- **無料 Web サービスは15分の無通信でスリープし、復帰に約1分かかる。** デモの直前に一度
  アクセスして温めておく運用が必要。**Shell も使えない**ため、運用コマンドはすべてローカルから
  `DB_URL` を develop へ向けて実行する。
- **`APP_ENV=staging` では Laravel の確認プロンプトが出ない。** `migrate:fresh` / `db:wipe` /
  `db:seed` が確認なしで走る。守られているのは `demo:reset` の経路だけで、素の artisan コマンドを
  ローカルから叩く場合は依然として自己責任である。
- **Google の同意画面が「テスト」ステータスのあいだ、リフレッシュトークンが7日で失効する**
  （カレンダーのような機密スコープ）。develop の Google 連携は週次で切れるので、
  デモ前に繋ぎ直す運用で回す。
- **デモの鮮度は `demo:reset` の手動実行に依存する。** 忘れると「本日の予約」が空のダッシュボードを
  見せることになり、2人目の見込み客は Stripe の Checkout で弾かれる。cron 化していないため、
  この運用の抜けを機械が拾うことはない。
- **デモ環境のログイン情報は見込み客に渡すことになる。** 誰でも LINE 設定を上書き・切断でき、
  デモデータを書き換えられる。そのつもりで扱う。
- 環境変数の変更は**再デプロイしないと反映されない**（`entrypoint.sh` が起動時に `config:cache` を
  実行するため）。ダッシュボードで値を入れただけでは動かない。
- develop で使う外部サービスのアカウント・キーが増えた分、棚卸しの対象も増えた。
  何をどこに登録したかは [runbook-develop-env.md](../runbook-develop-env.md) の表で追う。

### 本 ADR で決めなかったこと

いずれも既存の課題として認識したうえで、意図的に本 ADR の範囲外に置く。

- **本番のキューワーカーと cron。** `type: worker`（`queue:work`）と `type: cron`（`schedule:run`）は
  どの環境にも存在せず、`routes/console.php` に登録された3コマンドは一度も動いていない。
  本 ADR は develop に `deferred` を置いて回避するだけで、本番側は変更しない
  （[runbook-hardening.md](../runbook-hardening.md) §6）。
- **本番の `DB_SSLMODE=require` の有効化。** 未検証のまま有効化すると起動不能になるため、
  疎通確認の手順を踏んでから別途行う（[runbook-hardening.md](../runbook-hardening.md) §1）。
- **本番の `autoDeployTrigger` を `checksPass` にするか。** CI が通ってからデプロイするほうが安全だが、
  起動時マイグレーションと組み合わせた挙動を確かめてから判断する。本 ADR では `commit` のまま。
- **`envVarGroups` による共通変数の集約**（Alternatives Considered 参照）。
- **Stripe のテストクロック**を使った請求サイクル前倒しのデモ。

---

## References

- [develop 環境と本番環境の分離 設計書](../superpowers/specs/2026-09-07-develop-production-split-design.md)（検証の根拠と一次情報の引用）
- [docs/runbook-develop-env.md](../runbook-develop-env.md)（構築手順。ダッシュボード操作と外部サービス登録）
- [docs/deployment.md](../deployment.md)（本番の初回デプロイ手順）
- [docs/stripe.md](../stripe.md)（Stripe の設定手順。local / develop / production の3環境）
- [docs/runbook-hardening.md](../runbook-hardening.md)（本 ADR で扱わない既存課題）
- [ADR-005](ADR-005-cloudflare-r2.md)（R2。バケット分離の前提）
- [ADR-010](ADR-010-git-workflow.md)（ブランチ運用。本 ADR で `feature/* → develop → main` に改める）
- [ADR-022](ADR-022-deployment.md)（デプロイ構成。本 ADR で2環境に拡張する）
- [ADR-024](ADR-024-line-integration.md)（LINE 連携。チャネルを共有できない理由）
- [ADR-025](ADR-025-google-calendar-sync.md)（Googleカレンダー同期。OAuth クライアントの分離）
- [ADR-028](ADR-028-production-hardening.md)（本番ハードニング。DEV/本番の資格情報分離）
- [ADR-029](ADR-029-subscription-billing.md)（サブスクリプション課金。`STRIPE_ENFORCE_MODE` と Webhook の方針）
