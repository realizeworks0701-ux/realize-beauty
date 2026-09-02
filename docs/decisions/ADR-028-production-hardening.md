# ADR-028: 本番セキュリティ・実行環境のハードニング

## Status

Accepted

---

## Date

2026-09-02

---

## Context

本番公開（2026-07-22）以降、機能実装を優先しておりセキュリティ・実行環境の設計が未整備だった。
DB接続エラーの調査を起点に監査したところ、以下が実機で確認された。

- `POST /api/v1/auth/login` にレート制限が無く、30回連続の失敗でも 429 が返らない。
  本番には既定パスワードの `admin@example.com` / `password` が公開URLで有効なまま存在する。
- `TrustProxies` が未設定。Render はプロキシ配下で TLS を終端するため `$request->ip()` が
  常にプロキシのIPになり、レート制限が全利用者で1つのバケットを共有してしまう。
- `.dockerignore` が存在せず `COPY . .` がローカルの `.env`（APP_KEY・Google OAuth シークレット）と
  dev依存 133MB を本番イメージに焼き込む。`composer install --no-dev` が無効化されていた。
- 公式PHPイメージは `php.ini` を読み込まない。`upload_max_filesize=2M` に対しアプリは10MBの写真を
  許可しており、2MB超の写真が PHP 層で無言で破棄され「画像が必要です」という誤ったエラーになる。
  `display_errors=On` / `expose_php=On`（`X-Powered-By: PHP/8.3.33` が漏出）。
- `LOG_CHANNEL` 未設定によりログはコンテナの一時ディスクへ書かれ、Render のログストリームに現れない。
  一方 `QueryException` の既定メッセージはバインド値（顧客の氏名・メール）をそのまま含む。
- R2 ディスクが `visibility=public` で、`Storage::url()` が恒久的な未認証URLを返す。
  URLを知る第三者はログイン・テナント・論理削除に関係なくカルテ写真を永続的に閲覧できる。
- Sanctum トークンが無期限（`config/sanctum.php` 未publish）。SPA は localStorage に保持している。
- コンテナが root 実行、ベースイメージがタグ指定で再現性が無い、`artisan serve` が1リクエスト直列。
- 顧客に渡す予約ページURLを `APP_URL`（API）で組み立てており 404 になる。
  公開予約APIのベースURLは未設定時に相対パスとなり、Cloudflare の SPA フォールバックで HTML が返る。

---

## Decision

**MVP の構成（Render の Docker + `artisan serve`）は維持したまま、設定と境界の防御を入れる。**

- 認証: ログインに throttle（IP 20/分・メール+IP 5/分）。メール単位のみにすると第三者が正規利用者を
  締め出せるため、必ずIPと組み合わせる。Sanctum トークンに有効期限（`SANCTUM_EXPIRATION`、既定12時間）。
- プロキシ: `trustProxies(at: '*')`。Render はプロキシ経由でしか到達できないため `'*'` で安全。
- 写真: R2 を `visibility=private` にし、`Photo::url` は private ディスクのときだけ
  `temporaryUrl`（`PHOTO_URL_TTL_MINUTES`、既定60分）を返す。public ディスク（ローカル）は従来どおり。
- イメージ: `.dockerignore` を追加し `.env`・`vendor/`・`tests/` を除外。`php.ini-production` を有効化し
  アップロード上限をアプリの制限（10MB）より大きい12MBに、`expose_php=Off`。非rootユーザーで実行し、
  ベースイメージと composer を digest で固定。ビルド専用パッケージは最終イメージに残さない。
- 実行: `artisan serve --no-reload` + `PHP_CLI_SERVER_WORKERS=4`（`--no-reload` が複数ワーカーの前提）。
- ログ: `LOG_CHANNEL=stderr` / `LOG_LEVEL=info`。`QueryException` は report フックで
  プレースホルダのままのSQLと SQLSTATE だけを記録し、バインド値を残さない。
- 初期投入: `DatabaseSeeder` は `APP_ENV=production` で例外を投げる。本番の初期オーナーは
  `php artisan salon:create-owner`（対話入力・12文字以上）で作成する。
- URL: 予約ページURLは `FRONTEND_URL` から組み立てる。公開APIのベースURLは `VITE_API_BASE_URL` から
  導出し、環境変数を2つ設定させない（片方の設定漏れで壊れるのを防ぐ）。

---

## Alternatives Considered

### 写真を公開バケットのまま、推測困難なキー名で運用する

現状もランダムなキー名だが、一度URLが外部へ渡ると失効させる手段が無い。カルテ写真は要配慮個人情報に
準じる扱いが必要なため、期限付き署名URLを採用した。

### `DB_SSLMODE=require` を無条件で入れる

Render の PostgreSQL が内部接続でTLSを受けるかを確認できていない。受けない場合、`entrypoint.sh` の
`set -e` と `migrate --force` によりコンテナが起動できず全断する（ローカルで再現確認済み）。
`render.yaml` には**コメントアウトした状態で記載**し、疎通確認後に有効化する運用とした
（docs/runbook-hardening.md 手順1）。

### nginx + php-fpm / FrankenPHP / Octane へ移行する

同時実行の根本解決になるが、Dockerfile とデプロイ構成の作り直しになる。MVP の負荷では
`PHP_CLI_SERVER_WORKERS` で十分と判断し、移行は将来の課題として残す。

### 役割ベースのアクセス制御を同時に入れる

認証済みユーザーが全データを操作できる状態は残るが、ADR-003 は MVP 未適用の方針であり、
影響範囲が大きいため本ADRの対象外とした。

---

## Consequences

### メリット

- 総当たり・PHPバージョン漏洩・イメージへの資格情報混入・ログへのPII混入といった経路を塞いだ。
- 2MB超の写真が無言で失われる不具合が解消される（本来の10MB制限が機能する）。
- イメージが digest 固定・非root・dev依存なしになり、ビルドが再現可能になった。

### デメリット・注意点

- デプロイ時に**全利用者が一度ログアウト**される（発行済みトークンが即期限切れになるため）。
- 写真URLに期限ができ、画面を長時間放置すると画像が切れる。
- `DB_SSLMODE=require` は未適用のまま（疎通確認後に有効化する）。それまでDB接続は平文の可能性が残る。
- 期限切れトークンが `personal_access_tokens` に残る（スケジューラ未稼働のため `sanctum:prune-expired`
  を回せない）。キュー/スケジューラの方針決定とセットで解消する。
- `sslmode=require` は暗号化のみで証明書検証はしないため、中間者攻撃までは防げない。

---

## References

- docs/runbook-hardening.md（手動作業手順）
- docs/deployment.md
- [ADR-005](ADR-005-cloudflare-r2.md)（写真ストレージ。公開URL前提の記述を本ADRで更新）
- [ADR-022](ADR-022-deployment.md)（デプロイ構成）
- [ADR-003](ADR-003-role-based-access-control.md)（認可。MVP未適用のまま）
