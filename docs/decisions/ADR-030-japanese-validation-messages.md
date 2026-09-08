# ADR-030 バリデーションメッセージの日本語化

## Status

Accepted

## Date

2026-09-04

## Context

アプリケーションの `APP_LOCALE` は Laravel の初期値である `en` のままで、`backend/lang/` も存在しなかった。
そのため FormRequest の 422 レスポンスは Laravel 標準の英語メッセージをそのまま返していた。

管理画面はフロントエンド側でクライアントバリデーションを日本語で行っており、
サーバのメッセージが利用者の目に触れる機会は少なかったため、この矛盾は長く表面化しなかった。

これが公開Web予約ページで顕在化した。`is_first_visit` を必須にした際、フロントエンドが
この項目を送っていなかったため、一般の来店客に対して
`The is first visit field is required.` という英語メッセージが表示された。

公開予約ページの設計（docs/ui/public-booking.md）は「422 のメッセージをフィールド単位で表示する」
としており、サーバのメッセージが日本語であることを前提としている。
またサービス層（`PublicBookingService`）は `start_at` のエラーを日本語で投げており、
FormRequest だけが英語という不整合な状態だった。

## Decision

`backend/lang/ja/` に翻訳ファイルを置き、`APP_LOCALE` の既定値を `ja` に変更する。

- `lang/ja/validation.php` — Laravel 13 の全バリデーションルールの日本語訳。
  末尾の `attributes` に全カラムの日本語名（`is_first_visit` → 「新規ご来店」等）を定義する
- `lang/ja/auth.php` / `passwords.php` / `pagination.php` — 認証・パスワード再設定・ページネーション
- `config/app.php` の `locale` 既定値を `ja` に変更し、`.env.example` と `render.yaml` にも `APP_LOCALE=ja` を明記する
- `fallback_locale` は `en` のまま残す（将来 Laravel がルールを追加した際、キーがそのまま表示されるのを避けるため）

FormRequest ごとの `messages()` は原則書かない。項目名は `attributes` で一元管理し、
**カラムを追加したら `attributes` にも1行足す**ことを規約とする。

## Alternatives Considered

- **FormRequest ごとに `messages()` / `attributes()` を定義する** —
  影響範囲は最小だが、22個の FormRequest すべてに同じ内容を書くことになり重複が大きい。
  また新しい FormRequest で書き忘れると再び英語が漏れる。今回の不具合と同じ再発を防げない
- **フロントエンドでサーバのエラーキーごとに固定文言を持つ** —
  サーバの制約（文字数上限等）が変わるたびにフロントの文言も直す必要があり、二重管理になる。
  公開予約ページの設計が `start_at` について「サーバのメッセージをそのまま表示する」と
  定めている方針とも矛盾する
- **`APP_LOCALE` は `en` のままアプリ起動時に `App::setLocale('ja')` を呼ぶ** —
  設定を読めば分かる状態にならず、環境変数で切り替えられる利点も失う

## Consequences

**メリット**

- 22個すべての FormRequest が自動的に日本語になり、今後追加する FormRequest も既定で日本語になる
- 項目名の日本語訳が `attributes` の1箇所に集約され、表記ゆれが起きにくい
- サービス層（日本語）と FormRequest（英語）の不整合が解消される

**デメリット・注意点**

- カラムを追加して `attributes` への追記を忘れると、その項目だけスネークケースのまま表示される
- `lang/ja/validation.php` は Laravel 本体のルール追加に追従が必要になる。
  未翻訳キーは `fallback_locale` により英語で表示されるため、壊れはしないが英語が混ざる
- `faker_locale` は `en_US` のまま変更していない。テストデータの内容を変えないため

## References

- docs/ui/public-booking.md（422 のメッセージをフィールド単位で表示する）
- docs/requirements/booking.md
- docs/standards/backend.md
- backend/lang/ja/validation.php
