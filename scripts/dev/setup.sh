#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../../api"
cp -n .env.example .env
php -r 'echo "APP_KEY=base64:".base64_encode(random_bytes(32)).PHP_EOL;' >> .env
sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=file/' .env
sed -i 's/^CACHE_STORE=.*/CACHE_STORE=file/' .env
sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/' .env
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$(pwd)/database/database.sqlite|" .env
mkdir -p database && : > database/database.sqlite
php artisan optimize:clear
php artisan serve --host=phpgrc.gruntlabs.net --port=8000