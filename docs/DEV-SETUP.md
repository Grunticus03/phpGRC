# phpGRC API — Developer Setup

## Requirements
- PHP 8.3  
  - Extensions: mbstring, intl, pdo_mysql (or sqlite3), dom, xml
- Composer 2.x
- MySQL 8.0+ (or MariaDB 10.6+)
- Git and curl

## Clone
```
git clone <your-fork-url> phpgrc
cd phpgrc
```

## Configure database
```
sudo install -d -m 0750 /opt/phpgrc/shared
cp scripts/templates/shared-config.php /opt/phpgrc/shared/config.php
sudo chown -R <you>:<web-group> /opt/phpgrc
sudo chmod 0640 /opt/phpgrc/shared/config.php
# Edit DB creds in that file.
```

## Install
```
cd api
composer install
composer app:prepare
php artisan key:generate --ansi
php artisan about
```

## Database
```
# Create DB and user (adjust creds)
mysql -uroot -p -e "
CREATE DATABASE IF NOT EXISTS phpgrc CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'phpgrc'@'localhost' IDENTIFIED BY 'CHANGE_ME';
GRANT ALL ON phpgrc.* TO 'phpgrc'@'localhost';
FLUSH PRIVILEGES;"

# Run all migrations
php artisan migrate --force
```

## Run locally
```
# from ./api
php -S phpgrc.gruntlabs.net:9000 -t public
# or:
php artisan serve --host=phpgrc.gruntlabs.net --port=9000
```

## Smoke test
```
curl -fsS http://phpgrc.gruntlabs.net:9000/api/health
printf 'hello\n' > /tmp/e.txt
curl -fSsi -X POST http://phpgrc.gruntlabs.net:9000/api/evidence -F "file=@/tmp/e.txt;type=text/plain"
curl -fsS  http://phpgrc.gruntlabs.net:9000/api/evidence
```

## QA
```
# format, static analysis, types, tests
composer check
composer test
composer phpmd
```

## Common issues
- “bootstrap/cache must be present”: run `composer app:prepare`.
- “could not find driver”: install `php8.3-mysql` or `php8.3-sqlite3`.
- 404 at `/api/...`: ensure you are serving `api/public`.
