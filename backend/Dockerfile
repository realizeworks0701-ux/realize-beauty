# Realize Beauty API (Laravel) — Render 向け Docker イメージ
# MVP 構成: php artisan serve で起動する。大きなトラフィックが必要になったら
# Laravel Octane / FrankenPHP や nginx + php-fpm への移行を検討する。
# ベースイメージは digest で固定する（タグは差し替わるためビルドの再現性が無い）。
# 更新するときは `docker buildx imagetools inspect php:8.3-cli-alpine` で新しい digest を取得する。
FROM php:8.3-cli-alpine@sha256:afdf8b1fee58486ccc0dab5f30f634b86873d56dac985f71ba217945647c05ad

# PHP 拡張（PostgreSQL 接続に pdo_pgsql、金額計算等に bcmath、バイトコードキャッシュに opcache）
# opcache は公式イメージに同梱されているが有効化されていないため、明示的に入れる。
# postgresql-dev はビルド時のみ必要なので最終イメージには残さない（libpq は実行時に必要）。
RUN apk add --no-cache libpq git unzip \
    && apk add --no-cache --virtual .build-deps postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql bcmath opcache \
    && apk del .build-deps

# PHP 設定。公式イメージは php.ini を一切読み込まない（display_errors=On / expose_php=On のまま）ため
# 本番用設定を有効化する。アップロード上限は UploadPhotoRequest の 10MB より大きくして、
# PHP が黙って破棄せずアプリのバリデーションでエラーを返せるようにする（既定は 2M）。
#
# opcache: enable_cli=1 が必須。artisan serve は CLI SAPI で動くため、既定の 0 のままだと
# 拡張を入れても一切効かず、毎リクエストで Laravel のブートストラップ全体を再コンパイルする。
# validate_timestamps=0 はイメージが不変で、コード変更が必ずコンテナ再起動を伴うから安全。
# memory_consumption はプラン 0.5c-512mb を踏まえて控えめにする（共有メモリなのでワーカー間で1つ）。
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf '%s\n' \
        'upload_max_filesize=12M' \
        'post_max_size=16M' \
        'expose_php=Off' \
        'opcache.enable=1' \
        'opcache.enable_cli=1' \
        'opcache.memory_consumption=64' \
        'opcache.interned_strings_buffer=8' \
        'opcache.max_accelerated_files=20000' \
        'opcache.validate_timestamps=0' \
        > "$PHP_INI_DIR/conf.d/zz-app.ini"

# Composer
COPY --from=composer:2@sha256:8fa35f42911ff8bbee92aa37d781de6799168d4a0535ac6991f1b250bc2e0245 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 依存だけ先に入れてレイヤーキャッシュを効かせる
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress

# アプリ本体
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && chmod +x docker/entrypoint.sh

# 非rootで実行する。書き込みが必要なのは storage と bootstrap/cache のみ。
RUN addgroup -g 1000 -S app && adduser -u 1000 -S -G app app \
    && chown -R app:app storage bootstrap/cache
USER app

# Render は $PORT を注入する（既定 8080）
ENV PORT=8080
EXPOSE 8080

CMD ["sh", "docker/entrypoint.sh"]
