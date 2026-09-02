# 本番ハードニング適用手順（2026-09-02）

セキュリティ・環境面の修正をまとめて入れた際の**手動作業手順**。コード側の変更は main への push で
自動デプロイされるが、以下は人手でしか実施できない。**上から順に実施すること。**

関連: [ADR-028](decisions/ADR-028-production-hardening.md) / [deployment.md](deployment.md)

### Render ダッシュボードでの辿り方

Project を作っていないため、サービスは **Project ではなく `Ungrouped Services` に並ぶ**。

```
左メニュー「Projects」→ Overview 下部の「Ungrouped Services」
  ├─ realize-beauty-api   … APIサービス（Docker）  → 環境変数は開いて「Environment」
  └─ realize-beauty-db    … PostgreSQL            → 接続情報は開いて「Connect」/「Info」
```

以降「API サービス」「DB サービス」と書いた箇所はここから開く。

---

## 0. 前提と影響範囲

このデプロイで**利用者に見える変化**が2つある。周知してから実施する。

- **全員が一度ログアウトされる**。Sanctum トークンに有効期限（既定12時間）を入れたため、
  発行済みトークンは `created_at` 基準で即座に期限切れになる。SPA は 401 を検知してログイン画面へ遷移する。
- **写真のURLが署名付き・期限付きに変わる**（既定60分）。画面を開いたまま長時間放置すると画像が切れるので、
  その場合は再読み込みしてもらう。過去に外部へ共有した r2.dev のURLは手順3の後で無効になる。

---

## 1. 【push 前に必須】DB の TLS 疎通確認

`render.yaml` の `DB_SSLMODE` は**コメントアウトした状態で push している**。未検証のまま反映すると、
Render の PostgreSQL が TLS を受けない場合に `entrypoint.sh` の `set -e` と `migrate --force` で
コンテナが起動できず全断するため。疎通確認してから有効化する。

ローカルの postgres（TLS 無効）で失敗モードを再現済み:

```
SQLSTATE[08006] server does not support SSL, but SSL was required
→ コンテナ ExitCode=1
```

### 手順

**Render の Shell から実行する。**本番が実際に使う*内部*接続をそのまま検証できるため、
External URL 経由（＝外部接続の確認にしかならない）より確実。

1. Ungrouped Services → `realize-beauty-api` → **Shell** を開く
2. 設定キャッシュを触らずに PDO だけで TLS 接続を試す（環境変数はShellに入っている）

   ```sh
   php -r '$dsn="pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE").";sslmode=require";
   try { new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo "TLS OK\n"; }
   catch (Throwable $e) { echo "NG: ".$e->getMessage()."\n"; }'
   ```

   - **成功**: `TLS OK`
   - **失敗**: `NG: SQLSTATE[08006] ... server does not support SSL, but SSL was required`

   > `php artisan db:show` ではなく素の PDO を使うのは、本番コンテナは起動時に `config:cache` を
   > 実行しており、`DB_SSLMODE` を後から env で渡しても設定キャッシュ側が優先されて反映されないため。
   > `config:clear` すると稼働中のアプリに影響するので触らない。

   比較のため `sslmode=require` を `sslmode=prefer` に変えて実行すると、TLS 以外の原因
   （認証情報の誤り等）と切り分けられる。

3. **成功したら** 有効化する。どちらでもよい:
   - Ungrouped Services → `realize-beauty-api` → Environment に `DB_SSLMODE=require` を追加（即再デプロイ）
   - または `render.yaml` の該当2行のコメントを外して push
4. **失敗したら** 有効化しない。DB 接続は平文の可能性が残るため、Render のサポート/ドキュメントで
   内部接続の TLS 可否を確認する
5. 有効化後、再デプロイが終わったら Shell で**実際に暗号化されているか**を確認する

   ```sh
   php artisan tinker --execute="dump(DB::select('select ssl from pg_stat_ssl where pid = pg_backend_pid()'));"
   ```

   `ssl: true` なら TLS で接続できている。
   （`php artisan db` は `psql` バイナリを要求するが本番イメージには入れていないので使えない）

> 注: External と Internal でTLS設定が異なる可能性は低いが、確実を期すならデプロイ直後に
> `https://realize-beauty-api.onrender.com/up` が 200 を返すことを必ず確認する（手順5）。

### ロールバック

`/up` が 200 を返さない場合、Ungrouped Services → `realize-beauty-api` → Environment で
`DB_SSLMODE` を `prefer` に変更して保存（自動で再デプロイされる）。

---

## 2. 【最優先】ログインできるオーナーを用意する

2026-09-02 に既定パスワード対策として本番で `migrate:fresh` を実行済み。そのため
**`users` が空で、現状どのアカウントでもログインできない**。

`salon:create-owner` は本デプロイで追加したコマンドなので、**push → デプロイ完了を待ってから**
実行する（それ以前のイメージには存在しない）。

Ungrouped Services → `realize-beauty-api` → **Shell**。

### まず現状を確認する

```sh
php artisan tinker --execute='
$rows = App\Models\User::withTrashed()->get(["id","salon_id","email","role","is_active","deleted_at"]);
echo "件数: ".$rows->count().PHP_EOL;
foreach ($rows as $r) { printf("#%d salon=%s %s role=%s active=%s deleted_at=%s%s",
  $r->id, $r->salon_id, $r->email, $r->role->value, var_export($r->is_active,true), $r->deleted_at ?? "null", PHP_EOL); }'
```

`User` は SoftDeletes のため、`withTrashed()` を付けないと論理削除済みの行が見えない
（`->first()` が null になる原因になる）。

### A. 0件の場合（`migrate:fresh` 後はこちら）

```sh
php artisan salon:create-owner
# サロン名・電話・郵便番号・住所・氏名・メールを対話入力
# パスワードは12文字以上・非表示入力（Shell の履歴に残らない）
```

作成されるサロンには `booking_slug`（公開予約ページ用の16文字）が `creating` フックで自動採番される。

`migrate:fresh` でメニュー・営業時間・顧客もすべて消えているため、オーナー作成後に管理画面から
**メニューと営業時間を登録する**こと。これが無いと公開予約ページに空き枠が出ない。

### B. 既定パスワードのユーザーが残っている場合

```sh
php artisan tinker
```

```php
$u = App\Models\User::where('email', 'admin@example.com')->first();
$u->password = '<新しい12文字以上のパスワード>';   // password は hashed キャストなので平文を入れる
$u->save();
$u->tokens()->delete();   // 既存トークンも失効させる
```

`staff@example.com` も残っていれば同様に扱う（不要なら `is_active = false` か論理削除）。

> Shell の履歴に平文パスワードが残る。気になる場合は A の `salon:create-owner`（非表示入力）で
> 新しいオーナーを作り、旧アカウントを無効化する。

### 以後も `db:seed` は使わない

デモの顧客・予約と既定パスワードのユーザーが入るため。`DatabaseSeeder` は `APP_ENV=production` で
例外を投げるが、ローカルから `DB_URL` で本番DBを指した場合はこのガードが効かない。

---

## 3. 【デプロイ後】R2 バケットの公開アクセスを無効化する

写真は `visibility=private` + 署名付きURL（`temporaryUrl`）配信に変更した。
**バケット側が公開のままだと、URLを知る第三者は引き続き恒久的にアクセスできる。**

### 手順（順序厳守）

1. main へ push し、デプロイ完了を待つ
2. 管理画面でカルテ写真が表示されることを確認する（URLが `...r2.cloudflarestorage.com/...?X-Amz-Signature=...`
   の形になっていること。ブラウザの開発者ツール → Network で確認）
3. **表示が確認できてから** Cloudflare ダッシュボード → R2 → `realize-beauty-photos` → Settings →
   **Public Development URL (r2.dev) を無効化**する（カスタムドメインを設定している場合はそれも解除）
4. 無効化後、ブラウザのシークレットウィンドウで旧 r2.dev のURLを開き、403/404 になることを確認する
5. 管理画面をリロードし、写真が引き続き表示されることを確認する

### 注意

- 既存の写真オブジェクトはキーが変わらないため、署名付きURL経由では引き続き閲覧できる。移行作業は不要。
- ただし 2026-09-02 の `migrate:fresh` で `photos` テーブルが空になったため、バケット内の既存
  オブジェクトはどのレコードからも参照されない孤児になっている。手順3で非公開にすれば露出は無くなるが、
  容量が気になるならバケットを空にしてよい。
- Render の環境変数 `R2_PUBLIC_URL` は公開アクセスを再度有効にした場合にのみ使われる。削除は不要。
- **手順2で写真が表示されない場合は手順3を実行しないこと。** 先に原因を調べる
  （`R2_ENDPOINT` / アクセスキーが署名付きURL生成に使える権限を持っているか）。

### ロールバック

R2 の Public Development URL を再度有効化し、`config/filesystems.php` の r2 の `visibility` を
`public` に戻して再デプロイする。

---

## 4. 【デプロイ後】本番のデモデータを棚卸しする

2026-09-02 の `migrate:fresh` で全テーブルが作り直されているため、**この時点では不要**。
以降に誤って `db:seed` を流した場合のみ実施する。

```sh
# Render の Shell で
php artisan tinker
```

```php
App\Models\Customer::withTrashed()->pluck('name', 'id');     // 佐藤/鈴木などのダミーが無いか
App\Models\Reservation::withTrashed()->count();
App\Models\Menu::pluck('name', 'id');
```

実データと混ざっているため一括削除はせず、ダミーと判断できるものだけ個別に削除する。

---

## 5. デプロイ後の確認項目

| 確認 | 方法 | 期待値 |
| --- | --- | --- |
| API が起動している | `curl -s -o /dev/null -w '%{http_code}' https://<api>/up` | `200` |
| PHPバージョンが漏れない | `curl -sI https://<api>/up \| grep -i x-powered-by` | 出力なし |
| ログが Render に出る | `realize-beauty-api` → Logs | JSON ではなくテキストのログ行が流れる |
| ログイン throttle | 誤ったパスワードで6回連続ログイン | 5回目以降 `429` |
| 予約ページURL | 管理画面 → LINE設定 → 予約ページURL | フロントのドメイン（`.workers.dev`）で始まる |
| 公開予約ページ | 予約ページURLをシークレットウィンドウで開く | メニュー・空き枠が表示される（404/HTMLパースエラーが出ない） |
| 写真アップロード | 5MB程度の写真をアップロード | 成功する（従来は2MB超で「画像が必要です」エラー） |
| トークン期限 | 12時間後に画面を操作 | ログイン画面へ遷移する |

---

## 6. 積み残し（このデプロイには含まれない）

- **キューとスケジューラが本番で動いていない**。`queue:work` も `schedule:run` も無いため、
  LINE返信・予約確定通知・カレンダー同期のジョブが `jobs` テーブルに溜まり続けている。
  有料プランなので `render.yaml` に `type: worker`（`queue:work`）と `type: cron`（`schedule:run`）を
  追加する正攻法が取れる。サービスが増えるぶん課金対象も増えるため、追加費用ゼロで済ませるなら
  `QUEUE_CONNECTION=sync`（リクエスト内で同期実行）。方針は別途決定する。
- **期限切れトークンが `personal_access_tokens` に残る**。`sanctum:prune-expired` を回す手段が
  スケジューラ未稼働のため無い。無効なので害は無いが、上記のキュー方針とセットで検討する。
- **認可（役割ベースのアクセス制御）が未実装**。認証済みユーザーは role に関わらず全顧客・カルテ・写真を
  操作できる（ADR-003 は予約済みで MVP 未適用）。
- **`sslmode=require` は暗号化するがサーバ証明書の検証はしない**。中間者攻撃までは防げない。
