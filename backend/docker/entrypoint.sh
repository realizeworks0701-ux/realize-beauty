#!/bin/sh
# Render コンテナ起動スクリプト
set -e

# 設定・ルートをキャッシュ（本番最適化）
php artisan config:cache
php artisan route:cache

# マイグレーション（本番なので --force）
php artisan migrate --force

# 写真は R2（外部ストレージ）に置くため public/storage のシンボリックリンクは不要。
# 非rootで実行しており public/ に書き込めないので、ローカルディスク構成のときだけ作る。
if [ "${FILESYSTEM_DISK:-r2}" != "r2" ]; then
    php artisan storage:link || true
fi

# --no-reload はファイル監視を止めるだけでなく、PHP_CLI_SERVER_WORKERS による
# 複数ワーカー起動の前提条件（無いと1ワーカーに黙って降格する）。
exec php artisan serve --no-reload --host=0.0.0.0 --port="${PORT:-8080}"
