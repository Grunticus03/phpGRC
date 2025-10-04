# AGENTS.md
Principles: follow Charter.md and STYLEGUIDE.md. Never change OpenAPI responses without tests.
Tasks allowed: add/modify PHP controllers, Laravel routes, tests; TS client regen from OpenAPI; React pages.
Commands: composer install; vendor/bin/phpunit --testdox; npm ci; npm run typecheck; npm run build; npm run test
Env: PHP 8.3, Node 20, SQLite for tests; RBAC must pass PHPStan L9 and Psalm.
PR rules: small diffs; conventional commits; CI green required.
