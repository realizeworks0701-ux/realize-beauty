# Realize Beauty API (Laravel) — Render 向け Docker イメージ
# MVP 構成: php artisan serve で起動する。大きなトラフィックが必要になったら
# Laravel Octane / FrankenPHP や nginx + php-fpm への移行を検討する。
FROM php:8.3-cli-alpine

# PHP 拡張（PostgreSQL 接続に pdo_pgsql、金額計算等に bcmath）
RUN apk add --no-cache postgresql-dev libpq git unzip \
    && docker-php-ext-install pdo pdo_pgsql bcmath

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 依存だけ先に入れてレイヤーキャッシュを効かせる
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress

# アプリ本体
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative \
    && chmod +x docker/entrypoint.sh

# Render は $PORT を注入する（既定 8080）
ENV PORT=8080
EXPOSE 8080

CMD ["sh", "docker/entrypoint.sh"]
