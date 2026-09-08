# develop 環境の構築手順（手作業分）

[設計書](superpowers/specs/2026-09-07-develop-production-split-design.md) の Phase 0 / Phase 1 / Phase 3（Cloudflare 側）にあたる、**ダッシュボード操作と外部サービス登録**をまとめた手順書。コード変更は別途リポジトリ側で行う。

**順序に依存関係がある。** 特に STEP 0 は他のすべてに先行する。飛ばすと本番が落ちる。

---

## 進捗（2026-09-08 時点）

| STEP | 内容 | 状態 |
|---|---|---|
| 0 | 本番を守る（プラン是正・実設定の突き合わせ） | ✅ 完了。`render.yaml` を実設定に一致させた（`a140960`）|
| 0.5 | push の順序と `main` のブランチ保護 | ⏳ push は完了（`main`/`develop` とも `f4acbd1`）。保護ルールは設定中 |
| 1 | Neon（develop の DB） | ✅ 完了。接続文字列を取得済み |
| 2 | Cloudflare R2（develop の写真保管） | ⬜ **未着手** |
| 3 | Stripe sandbox（Price ×3 / キー / ポータル保存） | ⬜ **未着手** |
| 3.5 | develop 用 OpenAI キー | ⬜ 未着手（任意。未設定でもアプリは動く）|
| 4 | develop 用 `APP_KEY` の生成 | ✅ 完了 |
| 5 | Render に develop サービスを作る | ⬜ **次はここ**（STEP 2・3 を先に済ませると手戻りがない）|
| 6 | Cloudflare に develop 用 Worker を作る | ⬜ 未着手 |
| 7 | develop API に CORS とフロント URL を入れる | ⬜ 未着手 |
| 8 | Stripe の Webhook 登録 | ⬜ 未着手 |
| 9 | Google カレンダー連携 | ⬜ 未着手 |
| 10 | LINE 連携 | ⬜ 未着手 |
| 11 | デモデータ投入 | ⬜ 未着手 |
| 12 | 動作確認 | ⬜ 未着手 |

**確認済みの前提**

- Blueprint がリンクしているブランチは **`main`**。`render.yaml` は既に `main` に載っているので、Manual Sync を押せば develop サービスが作られる。
- Auto Sync は **No**。`render.yaml` を変更しても、Manual Sync を押すまで適用されない。
- CI は `main` / `develop` の両方で green。ブランチ保護の必要ステータスチェックに `Backend (Laravel)` / `Frontend (Vue)` を選べる状態。
- 本番は `oregon`、develop は `singapore` に置く（意図的に変える。理由は [ADR-031](decisions/ADR-031-two-environment-deployment.md)）。

**STEP 5 に進む前に STEP 2 と STEP 3 を済ませること。** develop サービスは作成直後に初回デプロイが走り、環境変数が空なので必ず失敗する。R2 と Stripe の値を手元に揃えてから作れば、失敗は一度で済む。

---

## 未対応のまま残っている本番の課題

develop 環境とは別件だが、試運転の前に潰す価値がある。

- **本番の Stripe が6変数とも未設定**（`STRIPE_SECRET` / `STRIPE_KEY` / `STRIPE_WEBHOOK_SECRET` / `STRIPE_PRICE_*`）。`sync: false` は既存 Blueprint の更新時に無視されるため、`render.yaml` に書いても設定されない。**本番の課金は現在動かない。** ダッシュボードで手入力する。
- **cron サービスが存在しない。** `routes/console.php` に登録された3コマンド（前日リマインダー、Google カレンダーのチャネル更新、同期窓の前進）がどの環境でも動いていない。特にチャネル更新が止まると、**外部カレンダーの予定取り込みが静かに停止し、公開予約ページが埋まっている枠を空きとして出す**。
- **本番DBの `ipAllowList` が `0.0.0.0/0`。** Shell が無くローカルから運用コマンドを叩く運用のために必要だが、範囲が最大。固定IPを用意できたら絞る。

---

## 控える値（先に空欄の表を作っておくと楽）

| # | 値 | 取得する STEP | 使う場所 |
|---|---|---|---|
| 1 | Neon の接続文字列（直接エンドポイント） | 1 | Render `DB_URL` |
| 2 | R2 develop バケット名 / エンドポイント / アクセスキー / シークレット | 2 | Render `R2_*` |
| 3 | Stripe sandbox の Price ID ×3 | 3 | Render `STRIPE_PRICE_*` |
| 4 | Stripe sandbox の `sk_test_` / `pk_test_` | 3 | Render `STRIPE_SECRET` / `STRIPE_KEY` |
| 5 | develop 用 `APP_KEY` | 4 | Render `APP_KEY` |
| 6 | develop API の URL | 5 | 下の 8/9/10、Cloudflare `VITE_API_BASE_URL` |
| 7 | develop フロントの URL | 6 | Render `CORS_ALLOWED_ORIGINS` / `FRONTEND_URL` |
| 8 | Stripe sandbox の `whsec_` | 8 | Render `STRIPE_WEBHOOK_SECRET` |
| 9 | develop 用 Google OAuth のクライアント ID / シークレット | 9 | Render `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` |
| 10 | develop 用 OpenAI キー | 3.5 | Render `OPENAI_API_KEY` |

---

## STEP 0. 本番を守る（最優先・単独で完了させる）

> **✅ コード側は対応済み（`90a5dbb` / `main` にマージ済み）。** `render.yaml` から本番リソースの
> `plan` 行を削除し、`APP_LOCALE: ja` も同じコミットで入っている。以下は**なぜその対応が必要だったか**の
> 記録として残す。ダッシュボード側の 1.〜4.（Auto Sync の停止と Generate Blueprint による突き合わせ）は
> **今後 `render.yaml` を触るときに毎回効く手順**なので、読み飛ばさないこと。

`render.yaml` は Web サービスと PostgreSQL の両方に `plan: free` と書いてあったが、実際は両方とも有料プラン。Render は **Blueprint がダッシュボードの設定を上書きする**と明記しており、かつ**同期は差分ではなくファイル全体を適用**する。Auto Sync は既定 ON、既存 Blueprint には同期前の差分確認画面がない。

> "if any of those changes conflict with configuration defined in the Blueprint, they're overwritten the next time you sync your Blueprint."
> — [render.com/docs/infrastructure-as-code](https://render.com/docs/infrastructure-as-code)

つまり **`render.yaml` を変更した push が1回走るだけで、本番の Postgres が 256MB に降格し、数分停止し、「作成30日で失効→猶予14日→削除」の経路に乗る。**

当時は作業ツリーに `render.yaml` の未コミット変更（`APP_LOCALE: ja` の追加）があり、**それを push する前に以下を終わらせる**必要があった。`render.yaml` を変更するときは毎回同じ確認をする。

**Auto Sync を止める:**

1. `https://dashboard.render.com/blueprints` を開く（左ペインの **Blueprints**。**Projects** のすぐ下、**Environment groups** の上にある）。
   - **ここが空なら、サービスは Blueprint 管理下にない。** その場合 `render.yaml` を push しても何も起きず、develop サービスも作られない。先へ進む前にこの前提を確認すること。
2. Blueprint 名をクリックする。**作成時に入力した名前であり、リポジトリ名とは限らない。** 見覚えがなければリポジトリで照合する。
3. その Blueprint の **Settings** を開く。
4. **Auto Sync** セクション（説明文は "Automatically sync changes to your Blueprint file? Select "No" to handle syncs manually."）。
5. 鉛筆の **Edit** を押すと編集可能になる。トグルではなく**ドロップダウン**（Yes / No）。**No** にして **Save changes**。

**実設定との差分を取る:**

6. `https://dashboard.render.com`（左ペインの **Projects**）を開き、**Ungrouped Services** のテーブルまでスクロールする。`render.yaml` に `projects:` を書いていないため、2つのリソースはここに並ぶ。
7. 各行の**左端のチェックボックス**で `realize-beauty-api` と `realize-beauty-db` を選ぶ。
8. 選択すると**画面下部に黒いバー**が出る: `N services selected: [Move] [Generate Blueprint] [Suspend]`。**Generate Blueprint** を押してダウンロードまたはコピーする。
9. ダウンロードした YAML とリポジトリの `render.yaml` を見比べ、**`plan` 以外にずれている項目がないか**確認する。特に `region` / `diskSizeGB` / `numInstances` / `ipAllowList` / `connectionPool` / `storageAutoscalingEnabled` / `postgresMajorVersion`。
   - 生成される YAML は環境変数の**名前だけで値を含まず**、すべて `sync: false` になる。値の差分は見られない。
10. ずれがあれば相談すること。`plan` は**行ごと削除**してある（既存リソースは現行プランを保持する、と Blueprint 仕様に明記がある。実 ID を書くより安全）。

**CLI で代替する（ダッシュボードを触らずに済む）:**

`brew install render` で入る Render CLI には、push 前に `render.yaml` を**実際のワークスペースに対して**検証するコマンドがある。YAML 構文とスキーマに加え、プラン名やリージョンの妥当性、**既存リソースとの衝突**、そして**ブランチの存在**まで見る。

```sh
render login
render blueprints validate render.yaml
```

ブランチ未作成のときの出力はドキュメントに例がある: `services[0].branch (line 19, column 5): branch prod could not be found`。
失敗時は非ゼロで終了する。CLI v2.7.1 以降が必要。

> **注意**: 新規に作る develop サービスの方は `plan: free` を**明示する**。新規リソースで `plan` を省略すると既定の `0.5c-512mb`（$7/月）になる。

STEP 0 の修正を push したあと、**Manual Sync を手動で実行**してデプロイ結果を確認する。問題なければ Auto Sync を戻すかどうかを判断する。

---

## STEP 0.5. GitHub のブランチ保護

> **✅ push は完了済み**（`main` / `develop` とも `f4acbd1`）。CI も両ブランチで green のため、
> 必要ステータスチェックを選べる状態にある。以下は保護ルールの設定手順。

**保護は push の後にかけること。** 先にかけると溜まったコミットの直 push が弾かれうる。方式で挙動が違い、
**Classic branch protection rule** は「Do not allow bypassing the above settings」が既定 OFF で管理者は
免除されるが、**Ruleset**（今の GitHub UI が誘導する方）は bypass リストが既定で空で管理者も免除されない。

`https://github.com/realizeworks0701-ux/realize-beauty/settings/rules` → **New ruleset → New branch ruleset**

| 項目 | 設定 |
|---|---|
| Ruleset Name | `main protection` |
| Enforcement status | **Active** |
| Bypass list | **+ Add bypass** → **Repository admin** → Allow for **All** |
| Target branches | **+ Add target** → **Include default branch**（= `main`）|

有効にするルール:

- ☑ **Require a pull request before merging**
  - Required approvals: **0**（一人開発では自分の PR を自分で承認できないため）
  - ☑ Dismiss stale pull request approvals when new commits are pushed
- ☑ **Require status checks to pass**
  - ☑ Require branches to be up to date before merging
  - **+ Add checks** で **`Backend (Laravel)`** と **`Frontend (Vue)`** を追加する。
    **`CI` でも `backend` でもない** — ジョブの `name:` の値。
- ☑ Restrict deletions（`main` の誤削除防止。任意）
- ☐ **Restrict creations は入れない。** `develop` や `feature/*` を作れなくなる恐れがある。

> **必要ステータスチェックの選択肢には、過去7日に成功したチェックしか出ない。** CI がしばらく走って
> いないと選べなくなるので、その場合は何か push して green にしてから設定する。

**確認**: 設定後に `git push origin main` を試す。管理者例外が効いていれば通る。弾かれたら bypass が
入っていない。

`develop` にも同じ保護を付けるなら、Target branches を **Include by pattern** → `develop` にした
2つ目の ruleset を作る。ただし develop は日常の作業先なので、まずは `main` だけにしておくほうが動きやすい。

[ADR-010](decisions/ADR-010-git-workflow.md) と [CONTRIBUTING.md](../CONTRIBUTING.md) は「main へ直接
コミットしない」と定めているが、実際には守られてこなかった。2環境に分けた今、`main` への直接コミットは
**本番への直接デプロイ**を意味する。

### 日常の流れ（保護後）

```
feature/xxx → develop → develop環境で確認 → main → 本番へ自動デプロイ
```

**ただし `render.yaml` の変更だけはこの流れに乗らない。** Blueprint がリンクしているのは `main` で、
かつ Auto Sync が No なので、`main` に到達したうえで **Manual Sync を押すまで適用されない**。
develop サービスの設定変更であっても同じで、develop 環境で先に試すことはできない。

混同しやすい2つの仕組みを区別すること:

| | 何が起きるか | 引き金 |
|---|---|---|
| サービスの自動デプロイ | そのサービスの**コード**が入れ替わる | `branch:` に指定したブランチへの push（`autoDeployTrigger: commit`）|
| Blueprint 同期 | `render.yaml` の内容がリソースに**適用**される（プラン・リージョン・環境変数・サービス追加）| **Manual Sync**（Auto Sync が No のため）|

ダッシュボードで環境変数を変えたときに必要なのは Manual Sync ではなく**再デプロイ**。
`entrypoint.sh` が起動時に `config:cache` を走らせるため、値を変えただけでは反映されない。

---

## STEP 1. Neon（develop の DB）

> **✅ 完了。** 接続文字列は取得済み。以下は再構築するときの手順として残す。
>
> **接続文字列のパネルが「Something went wrong」で開かないとき**は、まず1分置いてリロードする
> （新規プロジェクトは compute の起動中に接続系の画面が落ちることがある）。直らなければ CLI で迂回できる。
> 接続文字列はプロジェクトのメタデータから組み立てられるだけで、compute が動いている必要はない。
>
> ```sh
> npx neon auth
> npx neon projects list -o json
> npx neon connection-string --project-id <project-id> --database-name realize_beauty_develop
> ```
>
> **`--pooled` を付けないのが直接エンドポイント**（既定が false）。出力にはパスワードが含まれる。
> なお Neon の REST API のホストは `console.neon.tech/api/v2` で、`api.neon.tech` は存在しない。
> パスワードは再発行しなくても
> `GET /projects/{id}/branches/{branch}/roles/{role}/reveal_password` で取得できる。

1. [neon.tech](https://neon.tech) でアカウントを作る。
2. プロジェクトを **AWS Singapore (`ap-southeast-1`)** に作成する。develop の Render Web サービス
   （`render.yaml` で `region: singapore`）に合わせるため。Render に日本リージョンがなく、
   Tokyo に置くとアプリ⇔DB が約70ms 離れる。Laravel は1リクエストで多数のクエリを投げるので、
   アプリと同じリージョンに寄せる方が体感が速い（本番の DB とは別物なので、本番のリージョンとは揃えない）。
3. データベース名は `realize_beauty_develop` にする。
4. 接続文字列をコピーする。**プーラー経由（ホスト名に `-pooler` が入るもの）ではなく、直接エンドポイントを使う。** `config/database.php` の pgsql 接続が `search_path` を持ち、接続ごとにセッションへ設定するため。

   ```
   postgresql://<user>:<password>@ep-xxxx.ap-southeast-1.aws.neon.tech/realize_beauty_develop?sslmode=require
   ```

無料プランは 0.5GB ストレージ / 100 CU 時間・月で、**期限がない**。5分のアイドルでコンピュートが停止するが、次の接続で自動復帰する（数百ms）。この自動復帰が Neon を選んだ理由で、Supabase や Aiven は手動で解除するまで止まったままになり、週明けの初回デプロイが必ず失敗する。

---

## STEP 2. Cloudflare R2（develop の写真保管）

**本番バケットは共用しない。** R2 はバケット単位の課金がないので、分けても費用は変わらない。

### バケットを作る

`https://dash.cloudflare.com/?to=/:account/r2/overview` を開く（アカウント ID を手元に持っていなくても
`:account` を Cloudflare が解決する）。左ペインからたどる場合は **Storage & databases → R2 Object Storage**。
ドメインの Overview に居ると出てこない。R2 はアカウント単位の機能なので、アカウントの階層まで上がること。

1. **Create bucket** を押す。
2. **Bucket name**: `realize-beauty-photos-develop`。小文字・数字・ハイフンのみ、3〜63文字。
   **R2 にバケットのリネームは無い**ので、名前は最初に決め切る。
3. **Location**: **None（自動配置）のままにする。** ロケーションヒントは「best effort であり保証ではない」と
   ドキュメントにあり、しかも写真は署名付き URL で利用者のブラウザが直接取りに行くので、アプリ側の
   リージョンとは関係しない。**本番バケットと条件を揃えることの方が重要**で、片方だけヒントを付けると
   後で比較したときに理由の分からない差になる。
4. **Default storage class**: **Standard** のまま（無料枠は Standard のみ）。
5. **Create bucket**。
6. 作成後、**Settings → Public Development URL は有効にしない。** 触らなければ非公開のままで本番と揃う。

> **Jurisdiction（データ所在地）は設定しない。** これはヒントと違って変更不可の強い制約で、しかも
> 専用の別ホスト（`https://<id>.eu.r2.cloudflarestorage.com`）でしかアクセスできなくなる。
> 日本向けの選択肢は無く、設定すると develop だけ `R2_ENDPOINT` の形が変わって混乱する。
> 作成フォームに出てこなければそれが正常。

### バケット1つだけに絞った API トークンを作る

**R2 のトークン管理画面を使うこと。** アカウント全体の API Tokens ページ（`/profile/api-tokens`）にも
「Workers R2 Storage」権限があるが、あちらが払い出すのは**ベアラートークンで、S3 のアクセスキーの
組ではない**。用途が違う。

1. `https://dash.cloudflare.com/<ACCOUNT_ID>/r2/api-tokens` を開く。R2 の Overview 右側の
   **Account details** パネルにある **API Tokens → Manage** からも行ける。
2. **Create API token**。**Create Account API token** を選ぶ（Super Administrator 権限が要る）。
   User API token は自分の在籍に紐づいて消えるので、Render に持たせる用途には向かない。
3. **Token name**: `realize-beauty-develop-r2` など。
4. **Permissions**: **Object Read & Write** を選ぶ。
   - Laravel が必要とするのは PutObject / GetObject / DeleteObject / ListObjectsV2 と署名付き URL の生成だけで、
     バケットの作成権限は要らない。
   - **Admin 系の2つはバケットに絞り込めない**（構造上アカウント全体になる）。ここを間違えると
     develop のトークンで本番バケットを消せてしまう。
5. **Specify bucket(s)**: 既定は **Apply to all buckets in this account**。
   **Apply to specific buckets only** に切り替え、`realize-beauty-photos-develop` だけを選ぶ。
   **送信前に、選択チップに本番バケットが入っていないことを目で確認する。**
6. **TTL**: ローテーション運用が無いなら **Forever**。
7. **Client IP Address Filtering**: **空のままにする。** Render の送信元 IP はこのプランでは固定されないため、
   ここを設定すると R2 が静かに 403 を返し、資格情報の間違いにしか見えない障害になる。
8. 作成すると **Access Key ID** と **Secret Access Key** が表示される。**Secret は一度しか表示されない。**

### 控える値

| 画面の表示 | 環境変数 |
|---|---|
| Access Key ID | `R2_ACCESS_KEY_ID` |
| Secret Access Key（一度きり） | `R2_SECRET_ACCESS_KEY` |
| バケット名 | `R2_BUCKET` |
| `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` | `R2_ENDPOINT` |
| （非公開バケットなので空でよい） | `R2_PUBLIC_URL` |

アカウント ID は R2 Overview の **Account details** パネル、またはダッシュボード URL の
`dash.cloudflare.com/<ACCOUNT_ID>/...` の部分。エンドポイントに**バケット名は含めない**
（`config/filesystems.php` が `use_path_style_endpoint: true` なので、パスとして付く）。

`R2_PUBLIC_URL` は本番でも空運用で、写真は `temporaryUrl()` の署名付き URL で配っている。
Render の入力欄は空のままでよい。

---

## STEP 3. Stripe（develop のサブスク）

**必ず「named sandbox」を使う。** レガシーのテストモードは Dashboard 設定の一部を Live と共有するため、テスト側のポータル設定をいじると本番側が変わりうる。

Stripe は「サンドボックス」を試験環境の総称として使うようになった。アカウントには**消せない
「テスト環境のサンドボックス」**（レガシーのテストモード）が1つ常にあり、それとは別に
**名前付きサンドボックスを5つまで**作れる。レガシー側は危険で、日本語ドキュメントがこう書いている:

> 「ダッシュボードで test mode sandboxes を使用しているときに設定を変更すると、**本番環境の設定も
> 変更される可能性があります**。（…）通知が表示されない場合は、テスト環境のサンドボックスで加えた
> 変更は本番環境の設定に影響すると考えてください」
> — [testing-use-cases（日本語）](https://docs.stripe.com/testing-use-cases?locale=ja-JP)

警告が出ていないことは安全の証明にならない、という既定拒否の書き方である。共有される設定の一覧は
公開されていないので、**読んで避けることができない。名前付きサンドボックスを使うこと。**

> **日本語ドキュメントの URL は `docs.stripe.com/ja-jp/...` ではない**（404 になる）。
> `docs.stripe.com/<path>?locale=ja-JP` が正しい形式。

### どちらに入っているかの見分け方

**`/test/` の有無では判別できない。** 公式のディープリンク仕様がこう定めている:

> 「**MODE**: サンドボックス（テスト環境のサンドボックスを含む）には `test` を使用するか、
> 本番環境では値を省略します」
> — [stripe-apps/deep-links](https://docs.stripe.com/stripe-apps/deep-links)

つまり `dashboard.stripe.com/acct_xxx/test/...` は名前付きサンドボックスでもこの形になる。

**判定はアカウント ID で行う。** 名前付きサンドボックスは**本番とは別の `acct_` を持つ**。

| 環境 | URL の形 | 判定 |
|---|---|---|
| 本番 | `dashboard.stripe.com/<page>` | `/test/` が無い |
| テスト環境のサンドボックス（レガシー） | `dashboard.stripe.com/acct_本番ID/test/<page>` | `acct_` が**本番と同じ** |
| 名前付きサンドボックス | `dashboard.stripe.com/acct_別ID/test/<page>` | `acct_` が**本番と違う** |
| `~` 形式 | `dashboard.stripe.com/~/test/<page>` | **判定不能**（`~` は現在のアカウントのプレースホルダ） |

画面側では、左上に**「サンドボックス」**のバナーと**「本番環境に切り替える」**ボタンが出て、
アカウント切替にサンドボックス名が表示される。

### サンドボックスを作る

1. アカウント切替（画面左上）→ **サンドボックスに切り替える** → **サンドボックスを作成**。
   `https://dashboard.stripe.com/sandboxes` を直接開いてもよい。
2. **名前**: `realize-beauty-dev` など。
3. **アカウントをコピー** ではなく **アカウントを最初から作成** を選ぶ。
   コピーは本番の決済手段や入金設定まで持ち込む一方、**カスタマーポータルのドメインや公開情報は
   元々コピーされない**ので、選んでも手間は変わらない。
4. 入ったら**バナーとアカウント切替でサンドボックス名を確認する。**
5. 新規サンドボックスは既定で**プライベート**（管理者のみ）。他の人も入るなら
   サンドボックス一覧の ⋯ → **アクセス権を変更** → **開発者** または **すべてのチームメンバー** → **保存**。

### 商品と価格を3つ作る（Lite / Standard / Pro）

**サンドボックスのバナーが出ていることを確認してから。**
ナビの **その他** → **商品カタログ** → **+商品を追加**。

| 項目 | 設定 |
|---|---|
| **名前** | `Realize Beauty Lite` など |
| **説明** | 決済画面と**カスタマーポータルに表示される**ので埋めておく |
| **料金体系モデル** | **定額の料金体系** |
| 課金タイプ | **継続**（**1 回限り** ではない） |
| 通貨 | **JPY** |
| **請求期間** | 月次 |
| **税込み価格** | 消費税の扱いを決めてから。**後から変更できない** |
| **価格の説明** | 社内用。`lite` / `standard` / `pro` を入れておくと後で分かる |

- **JPY はゼロ十進通貨。`3000` は ¥3,000 であって ¥30 ではない。**
  100倍間違えても画面上はもっともらしく見える。最低請求額は ¥50。
- 保存ボタンも **商品を追加**。

`price_...` は商品を開いた価格の行に表示される。API で作る方が確実で速い:

```sh
curl https://api.stripe.com/v1/products -u "sk_test_...:" \
  -d "name=Realize Beauty Lite" \
  -d "default_price_data[unit_amount]=3000" \
  -d "default_price_data[currency]=jpy" \
  -d "default_price_data[recurring][interval]=month" \
  -d "expand[]=default_price"
```

### カスタマーポータルの設定を保存する（忘れやすい）

**設定** → **Billing**（ここは英語のまま）→ **カスタマーポータルの設定**。
サンドボックス内で `https://dashboard.stripe.com/test/settings/billing/portal`。

1. **始める方法** の **リンクを有効化** を押す。
2. 必要な項目を設定する。
3. **保存を押す。ここが本体。** 保存して初めて既定の `bpc_...` 設定が生成され、
   `/v1/billing_portal/sessions` がそれにフォールバックできるようになる。

必須項目は **見出し** と **ビジネス名**。ビジネス名はこの画面ではなく
**設定** → **アカウント設定** → **公開情報**（`/settings/public`）にあり、
「最初から作成」したサンドボックスには入っていないので先に埋める。

保存を忘れると、ポータルを開いた時点で Stripe が **400** を返す:

> "No configuration provided and your test mode default configuration has not been created."

アプリはこれを `StripeApiException` として **502「お支払いサービスに接続できませんでした」** で返す。
**`php artisan stripe:check` は env の静的検査なので、この保存漏れを検出できない。**
デモの前に実際にボタンを押して確かめること。

ついでに **設定** → **ブランディング** でロゴと色も入れておくとよい。ポータルは見込み客に見える画面になる。

### API キーを控える

サンドボックスのトップ画面右側の **API キー** パネルにコピーボタン付きで出ている。
一覧を見るなら **API キー** ページ（`/test/apikeys`）で、**標準キー** の下にある。

| 表示 | 値の形 | 環境変数 |
|---|---|---|
| **公開可能キー** | `pk_test_...` | `STRIPE_KEY` |
| **シークレットキー** | `sk_test_...` | `STRIPE_SECRET` |

サンドボックスではシークレットキーもそのまま表示される（本番のような **本番環境キーを表示** の
確認手順が無い）。**接頭辞は名前付きサンドボックスでも `sk_test_` / `pk_test_`** で、アプリの
`StripeClient::configuredMode()` はこれを受理する。コード側の変更は要らない。

Webhook エンドポイントは **STEP 8**（develop API の URL が確定してから）。

> **デモを長く使うなら知っておくこと。** サンドボックスで作られたサブスクリプションは
> **90日で自動キャンセル**され、さらに30日後にオブジェクトごと削除される。予告もメールも無い。
> 3か月後に「なぜかデモの契約が消えている」となるのはこれ。
>
> **サンドボックスの削除は取り消せない。** `price_...` も一緒に消え、参照している環境変数が全部壊れる。

### 参考: 本番の Stripe はまだ未設定

2026-09-07 の Generate Blueprint 突き合わせで判明した事実として記録する。`STRIPE_SECRET` /
`STRIPE_KEY` / `STRIPE_WEBHOOK_SECRET` / `STRIPE_PRICE_LITE` / `STRIPE_PRICE_STANDARD` /
`STRIPE_PRICE_PRO` は `badb307` で `render.yaml` に追加されたが、**Render は既存 Blueprint への
同期で `sync: false` の変数の入力を求めない**（入力を促すのは Blueprint の初回作成時だけ）。
そのため本番サービスの Environment にはこれらの値が一つも入っておらず、**本番の課金機能は
誰かがダッシュボードから手入力するまで動かない。** develop の Stripe（この STEP 3）はそれとは
無関係に、sandbox として別途セットアップする値であり、本番側の未設定を埋めるものではない。

### STEP 3.5. OpenAI

develop 用に**別の API キー**を発行する（本番キーと使用量を混ぜないため）。AI 要約はリクエスト内で同期的に呼ばれ、クリックのたびに課金される。予算上限を低く設定しておくとよい。

---

## STEP 4. develop 用 `APP_KEY` を生成する

> **✅ 完了。** 生成済みの `base64:...` を STEP 5 で入力する。

ローカルで実行し、出力された `base64:...` を控える。**本番の APP_KEY は絶対に流用しない。**

```sh
cd backend && php artisan key:generate --show
```

鍵を分けておくと、万一 develop に本番の DB ダンプを入れてしまっても、LINE のチャネルトークンや Google のリフレッシュトークン（暗号化列）を**復号できない**。これは制約ではなく安全装置。

---

## STEP 5. Render に develop サービスを作る

> **前提は揃っている。** `render.yaml` は `main` に載っており（`f4acbd1`）、Blueprint が
> リンクしているのも `main`。**Manual Sync を押せば `realize-beauty-api-dev` が作られる。**
>
> **ただし STEP 2（R2）と STEP 3（Stripe）を先に済ませること。** 作成直後に初回デプロイが走り、
> 環境変数が空なので必ず失敗する。値を手元に揃えてから作れば失敗は一度で済む。

> **リージョンは `render.yaml` に明示済みで、本番と揃えるのが目的ではない。** 本番の Web は
> `region: oregon`、develop の Web は `region: singapore` と書いてあり、これは意図的な違いである。
> develop は日本の見込み客に見せるデモで、Oregon だと太平洋を往復する分だけ体感が明確に遅くなるため
> Singapore を選んだ。**リージョンは作成後に変更できない**ので、Manual Sync で develop サービスが
> 作られたあとに気づいても直せない —— push する前に `render.yaml` の `region` を確認すること。
> STEP 1 の Neon プロジェクトは develop の Web と同じ **AWS Singapore** に作る（こちらは本番と
> 揃える理由が無いのではなく、そもそも本番用の DB ではないので比較対象にならない）。

1. Blueprint ページで **Manual Sync** を実行する。`realize-beauty-api-dev` が作成される。
2. **初回デプロイは失敗する。** 環境変数が空でコンテナが起動できないため。想定内なのでそのまま進む。
3. サービスの **Environment** タブで、`sync: false` の変数をすべて入力する。

   > Render は Blueprint の**初回作成時にしか**入力を促さない。既存 Blueprint に足したサービスは何も聞かれず、全部が空のまま始まる。

   | 変数 | 値 |
   |---|---|
   | `APP_KEY` | STEP 4 の値 |
   | `APP_URL` | このサービスの URL（下の 4. で確定） |
   | `DB_URL` | STEP 1 の Neon 接続文字列 |
   | `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` / `R2_BUCKET` / `R2_ENDPOINT` / `R2_PUBLIC_URL` | STEP 2 の値 |
   | `STRIPE_SECRET` / `STRIPE_KEY` | STEP 3 の `sk_test_` / `pk_test_` |
   | `STRIPE_PRICE_LITE` / `STRIPE_PRICE_STANDARD` / `STRIPE_PRICE_PRO` | STEP 3 の Price ID |
   | `STRIPE_WEBHOOK_SECRET` | STEP 8 で入れる（今は空でよい） |
   | `OPENAI_API_KEY` | STEP 3.5 の値 |
   | `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | STEP 9 で入れる（今は空でよい） |
   | `CORS_ALLOWED_ORIGINS` / `FRONTEND_URL` | STEP 7 で入れる |

4. 払い出された URL（`https://realize-beauty-api-dev.onrender.com` の形）を控える。`APP_URL` にこの値を入れる。
5. **手動でデプロイし直す。** `entrypoint.sh` が起動時に `config:cache` を実行するため、**環境変数を変えただけでは反映されない。**
6. `https://<develop API>/up` が 200 を返せば OK。

> 無料プランのため、15分無通信でスリープし復帰に約1分かかる。デモの直前に一度アクセスして温めておくこと。Shell も使えないので、運用コマンドはローカルから実行する（STEP 11）。

---

## STEP 6. Cloudflare に develop 用 Worker を作る

初回だけローカルからの手動デプロイが必要（Workers Builds は接続先の Worker が既に存在していることを前提にするため）。

1. こちらが `wrangler.jsonc` に `env.develop` を追加し、`wrangler` を devDependency に入れる。その後ローカルで:

   ```sh
   cd frontend
   VITE_API_BASE_URL=https://<develop API>/api/v1 VITE_ENV_LABEL=DEVELOP npm run build
   npx wrangler deploy --env develop
   ```

2. `https://realize-beauty-develop.realizeworks-0701.workers.dev` が払い出される。この URL は**デプロイをまたいで安定**する。
3. Cloudflare ダッシュボード → その Worker → **Settings → Build** でリポジトリを接続し、以下を設定する。

   | 項目 | 値 |
   |---|---|
   | Root directory | `frontend` |
   | Build command | `npm run build` |
   | Deploy command | `npx wrangler deploy --env develop` |
   | Branch control（production branch） | `develop` |
   | ビルド変数 `VITE_API_BASE_URL` | `https://<develop API>/api/v1` |
   | ビルド変数 `VITE_ENV_LABEL` | `DEVELOP` |

4. **「非 production ブランチのビルド」は有効にしない。** 既定の `wrangler versions upload` はバージョンごとに別ホスト名を払い出すため、完全一致の CORS 許可リストが毎回弾く。

### 既存の `realize-beauty` Worker（本番）も Deploy command だけ直す

| 項目 | 変更前 | 変更後 |
|---|---|---|
| Deploy command | `npx wrangler deploy` | `npx wrangler deploy --env=""` |
| Branch control（production branch） | `main` | `main`（変更なし） |

`wrangler.jsonc` に `env`（develop）を定義したことで、引数なしの `npx wrangler deploy` は次の警告を出すようになった。デプロイ自体は成功する（非致命的）が、毎回ログに残る。

> Multiple environments are defined in the Wrangler configuration file, but no target environment was specified for the deploy command.

`--env=""`（空文字）は「トップレベルの設定を使う」という明示になり、警告が出ず、Worker 名も `realize-beauty` のままに解決される（終了コード 0 で確認済み）。

> **`--env production` は使えない。** `wrangler.jsonc` に `env.production` の節が無いため、wrangler は設定の読み込み段階で止まり、**終了コード 1 で失敗する**（デプロイは走らない）。
>
> ```
> ✘ [ERROR] Processing wrangler.jsonc configuration:
>
>     - No environment found in configuration with name "production".
>       Before using `--env=production` there should be an equivalent environment section in the configuration.
>       The available configured environment names are: ["develop"]
> ```
>
> 静かに間違った先へ出るのではなく、その場で落ちる。仮に `env.production` の節を足せば通るようになるが、そのときは wrangler が env 名から `realize-beauty-production` という**別の Worker 名を導出する**。節を足さず、`--env=""` を使うこと。

ローカルからの手動デプロイも同じで、本番は `npm run deploy`、develop は `npm run deploy:develop` を使う。どちらも `npm run build` を実行してから `wrangler deploy` する（`dist/` は最後にビルドしたものが残っているだけで、どの環境向けかを持たない）。

> **本番へ出すときは、本番のビルド変数を与えて実行すること。** `VITE_API_BASE_URL` と `VITE_ENV_LABEL` は
> ビルド時にバンドルへ焼き込まれるため、スクリプトが `npm run build` を挟んでも**値までは面倒を見ない**。
> ビルド時の値はスクリプトの中には入っていない。この STEP の 1. のようにコマンドの前置きで
> 環境変数を渡している場合、同じシェルでそのまま `npm run deploy` しても値は引き継がれず、
> **`VITE_API_BASE_URL` も `VITE_ENV_LABEL` も未設定のバンドルが本番 Worker に出る**。
> API のベース URL は同一オリジンの `/api/v1` にフォールバックし、Worker には API がないので
> 公開予約ページも管理画面も動かない（[apiClient.ts:8](../frontend/src/services/apiClient.ts#L8)）。
> 逆に `export` していた場合は develop 向けのバンドルがそのまま本番に出る。どちらにしても事故なので、
> **デプロイ先に対応する変数を毎回明示的に渡す**。
>
> ```sh
> cd frontend
> VITE_API_BASE_URL=https://<本番 API>/api/v1 npm run deploy    # VITE_ENV_LABEL は渡さない（バッジを出さない）
> VITE_API_BASE_URL=https://<develop API>/api/v1 VITE_ENV_LABEL=DEVELOP npm run deploy:develop
> ```
>
> ふだんの本番デプロイは Workers Builds（ビルド変数がダッシュボードに入っている）に任せ、
> ローカルからの手動デプロイは復旧時などに限るのが安全。

---

## STEP 7. develop API に CORS とフロント URL を入れる

Render の `realize-beauty-api-dev` の Environment に入力し、**再デプロイする**。

| 変数 | 値 |
|---|---|
| `CORS_ALLOWED_ORIGINS` | `https://realize-beauty-develop.realizeworks-0701.workers.dev` |
| `FRONTEND_URL` | 同上 |

CORS は未設定なら全拒否（フェイルクローズ）なので、ここを飛ばすとフロントから API を一切呼べない。

> **本番側の `CORS_ALLOWED_ORIGINS` に develop の URL を足さないこと。** 逆も同じ。`FRONTEND_URL` を本番で間違えると、実顧客の予約リンクや Stripe の戻り先がデモ環境に飛ぶ。

---

## STEP 8. Stripe の Webhook を登録する（develop API の URL 確定後）

**STEP 3 で作ったサンドボックスの中で**行う。バナーを確認すること。

1. **ワークベンチ**（ナビ表記は `Workbench` の場合もある）→ **Webhook** タブ。
   `https://dashboard.stripe.com/webhooks` を直接開いてもよい。
2. **イベント送信先を作成**（画面によっては **新しい送信先を作成**）。
3. 送信元は **アカウント** を選ぶ（Connect プラットフォームではないため）。
4. API バージョンは `2024-06-20` に固定する（`STRIPE_API_VERSION` と揃えるため）。
5. **イベントタイプ**を選ぶ。必要なのはこの6つだけ:
   `checkout.session.completed` / `customer.subscription.created` /
   `customer.subscription.updated` / `customer.subscription.deleted` /
   `invoice.payment_failed` / `invoice.paid`
6. **続行** → 送信先の種類に **Webhook エンドポイント** を選ぶ。
7. **エンドポイント URL**: `https://<develop API>/api/webhooks/stripe`
   - **`/api/v1/` 配下ではない。** 認証なし・throttle なしのルート。
8. **送信先を作成する**。作成後の画面で **シークレットを表示**（既存のものは **クリックして表示**）を押し、
   `whsec_...` をコピーする。
9. Render の `STRIPE_WEBHOOK_SECRET` に入れ、**再デプロイする**。

> **本番の `whsec_` を develop に貼らないこと。** 貼ると Live のイベントが develop の DB に適用される。両方の DB はサロン ID が 1 から始まるため、宛先は必ず存在してしまう。逆（テストの `whsec_` を本番に貼る）はもっと静かで、本番の Webhook が延々 400 を返し続け、契約状態が同期されなくなる。

---

## STEP 9. Google カレンダー連携（develop 用）

1. Google Cloud Console で **develop 用の OAuth クライアント**を新規作成する（種類: ウェブアプリケーション）。本番のクライアントは使わない。
2. 承認済みリダイレクト URI に `https://<develop API>/api/v1/google-calendar/callback` を登録する。
3. クライアント ID / シークレットを Render の `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` に入れ、**再デプロイする**。

> **既知の制約**: OAuth 同意画面が「テスト」ステータスのあいだ、カレンダーのような機密スコープではリフレッシュトークンが**7日で失効**する。develop の Google 連携は週次で切れる。デモ前に繋ぎ直す運用で回す。
>
> また、Google のプッシュ通知はドメイン所有権の確認を要求するため、`*.onrender.com` では登録できない。これは本番も同じ状況で、develop で新たに壊れるものではない。

---

## STEP 10. LINE 連携（develop 用）

LINE のチャネルトークンは環境変数ではなく、**サロンごとの暗号化された DB 列**に入る（画面から登録する）。したがって「develop 専用の資格情報」とは、**別の LINE チャネルを作り、デモ環境の設定画面からトークンを貼る**ことを意味する。

1. LINE Developers で **develop 用の Messaging API チャネル**を作る。
2. Webhook URL に develop の値を設定する（アプリの LINE 設定画面が表示する URL をそのまま使う）。
3. STEP 11 でシードしたあと、develop にログインして設定画面からチャネルシークレットとアクセストークンを登録する。

> 1つの LINE チャネルには Webhook URL が1つしか設定できない。本番のチャネルを使い回すと**本番の Webhook が develop に向いてしまう**。必ず別チャネルを作ること。
>
> デモ環境のログイン情報は見込み客に渡すことになるため、**誰でも LINE 設定を上書き・切断できる**。そのつもりで扱う。

---

## STEP 11. develop の DB にデモデータを入れる

無料プランには Shell がないため、**ローカルから develop の DB に向けて**実行する。`demo:reset` コマンドはこちらで実装する。

```sh
cd backend
DB_URL='postgresql://…@ep-xxxx.ap-southeast-1.aws.neon.tech/realize_beauty_develop?sslmode=require' \
  php artisan demo:reset
```

コマンドは実行前に**接続先のホストとデータベース名を表示し、そのデータベース名の入力を求める**。`APP_ENV` ではなく接続先の実体を見ている。

ただし、この確認が止められるのは**打ち間違い**であって、向け違えそのものではない。対話実行では期待値（接続先のデータベース名）が入力を求める2行上に表示されるため、`DB_URL` を本番へ向けたまま表示どおりに入力すれば、そのまま通る。`--force` を使う場合だけは、`--expect-database` に**自分で**書いた名前が接続先と一致しなければ止まる（CI やスクリプトから叩くときはこちらを使う）。**接続先が正しいことは、実行者が表示されたホスト名で確かめること。**

- デモの前に毎回実行する運用にする。シーダーは実行時の日付で予約を作るため、これで「本日の予約」が現在に戻る。
- 見込み客が Stripe の契約を完了すると、次の人は「すでに契約中です」で弾かれる。**人が変わるたびに実行する。**
- アップグレードの流れを見せたい場合は `--plan=lite` を付ける。

---

## STEP 12. 動作確認

```sh
DEV_API=https://<develop API>
DEV_APP=https://realize-beauty-develop.realizeworks-0701.workers.dev

# 1. API が起きている
curl -s -o /dev/null -w '%{http_code}\n' "$DEV_API/up"

# 2. CORS に develop フロントが載っている（access-control-allow-origin が返ればOK）
curl -sI -H "Origin: $DEV_APP" "$DEV_API/api/v1/auth/login" | grep -i access-control-allow-origin
```

Stripe の設定はローカルから develop の env を渡して検査できる。

```sh
cd backend
APP_ENV=staging \
STRIPE_SECRET=sk_test_… STRIPE_KEY=pk_test_… STRIPE_WEBHOOK_SECRET=whsec_… \
STRIPE_PRICE_LITE=price_… STRIPE_PRICE_STANDARD=price_… STRIPE_PRICE_PRO=price_… \
  php artisan stripe:check
```

> `stripe:check` は環境変数の静的検査であり、**Stripe と通信しない**。「Price ID が sandbox に実在するか」「テスト側のカスタマーポータルが保存済みか」は検出できない。この2つは実際に画面を触って確認するしかない。

### 画面での確認

- [ ] `$DEV_APP` を開き、画面に `DEVELOP` バッジが出る
- [ ] `admin@example.com` / `password` でログインできる
- [ ] ダッシュボードに本日の予約とグラフが出ている（空でない）
- [ ] 顧客 → カルテ作成 → 写真アップロードが通り、写真が表示される
- [ ] R2 の **develop バケット**にオブジェクトが増えている（本番バケットは増えていない）
- [ ] 設定 → プラン → アップグレードで Stripe の Checkout に飛び、テストカード `4242 4242 4242 4242`（有効期限は未来の任意、CVC は任意の3桁）で完了できる
- [ ] 戻ってきた画面で契約状態が反映されている
- [ ] 「お支払い情報の変更」でカスタマーポータルが開く（開かなければ STEP 3-3 の保存漏れ）
- [ ] 公開予約ページから予約でき、develop の LINE チャネルに通知が届く

> **この最後の1項目は本番と挙動が違う。** develop だけ `QUEUE_CONNECTION=deferred` で、LINE 通知と
> Google カレンダー連携のジョブがレスポンス送出後に実際に実行される。本番は `database` のままで
> キューワーカーが居ないため、同じジョブは `jobs` テーブルに積まれるだけで**実行されない**。
> つまり develop で通っても本番で通ったことにはならない（この点だけ develop のほうが機能する）。
> 本番のキューワーカーは未対応の課題として残っている（[ADR-031](decisions/ADR-031-two-environment-deployment.md) 参照）。

### 見込み客に見せる前の確認

- [ ] `demo:reset` を実行した直後である
- [ ] デモ直前に一度アクセスして、コンテナを温めてある
- [ ] Stripe の決済画面がテスト環境であることを、先に口頭で伝えてある（Stripe 側にテスト表示が出るため、隠すことはできない）

---

## 参考

- [設計書](superpowers/specs/2026-09-07-develop-production-split-design.md)
- [本番デプロイ手順](deployment.md)
- [本番ハードニング手順](runbook-hardening.md)
- [Stripe 設定手順](stripe.md)
