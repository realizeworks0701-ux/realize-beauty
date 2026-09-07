#!/bin/sh
# Render コンテナ起動スクリプト
set -e

# 設定・ルートをキャッシュ（本番最適化）
php artisan config:cache
php artisan route:cache

# DB が起きるまで待つ。Neon のコンピュートはアイドルで停止し次の接続で自動復帰するため、
# 単発の migrate を set -e の下で走らせると初回接続のタイムアウトがそのまま起動失敗になる。
# 本番の Render Postgres が再起動した直後も同じ形で落ちる。
# 全試行が失敗したら従来どおり非ゼロで抜ける（壊れたマイグレーションを黙って無視しない）。
migrate_with_retry() {
    attempt=1
    max_attempts=5

    while true; do
        if php artisan migrate --force; then
            return 0
        fi

        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "migrate failed after ${max_attempts} attempts" >&2
            return 1
        fi

        delay=$((attempt * 3))
        echo "migrate failed (attempt ${attempt}/${max_attempts}); retrying in ${delay}s" >&2
        sleep "$delay"
        attempt=$((attempt + 1))
    done
}

migrate_with_retry

# 写真は R2（外部ストレージ）に置くため public/storage のシンボリックリンクは不要。
# 非rootで実行しており public/ に書き込めないので、ローカルディスク構成のときだけ作る。
if [ "${FILESYSTEM_DISK:-r2}" != "r2" ]; then
    php artisan storage:link || true
fi

# --no-reload はファイル監視を止めるだけでなく、PHP_CLI_SERVER_WORKERS による
# 複数ワーカー起動の前提条件（無いと1ワーカーに黙って降格する）。
exec php artisan serve --no-reload --host=0.0.0.0 --port="${PORT:-8080}"
