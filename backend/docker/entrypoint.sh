#!/bin/sh
# Render コンテナ起動スクリプト
set -e

# 設定・ルートをキャッシュ（本番最適化）
php artisan config:cache
php artisan route:cache

# マイグレーション（本番なので --force）
php artisan migrate --force

# 本番は R2（外部ストレージ）を使うため storage:link は失敗しても無視する
php artisan storage:link || true

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
