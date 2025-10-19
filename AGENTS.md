# AGENTS.md
Principles: follow Charter.md and STYLEGUIDE.md. Never change OpenAPI responses without tests.
Tasks allowed: add/modify PHP controllers, Laravel routes, tests; TS client regen from OpenAPI; React pages.
Commands: (cd api && composer install); (cd api && vendor/bin/phpunit --testdox); (cd web && npm ci); (cd web && npm run typecheck); (cd web && npm run build); (cd web && npm run test)
Env: PHP 8.3, Node 20, SQLite for tests; PHP files must pass PHPStan L10, Psalm, and PHPUnit.
PR rules: small diffs; conventional commits; CI green required.
Consult guides in docs/ai after file changes to ensure additional changes are not required.