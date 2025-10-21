# AGENTS.md
Principles: follow Charter.md and STYLEGUIDE.md. Never change OpenAPI responses without tests.
Tasks allowed: add/modify PHP controllers, Laravel routes, tests; TS client regen from OpenAPI; React pages.
Commands: (cd api && composer install); (cd api && vendor/bin/phpunit --testdox); (cd web && npm ci); (cd web && npm run typecheck); (cd web && npm run build); (cd web && npm run test)
Agents may run CLI HTTP clients (curl/httpie) and headless browsers (e.g., playwright test --project=chromium) for diagnostics and development.
Agents may use SSH to access the test server, phpgrc.gruntlabs.net:2332. Agents have full authority on this server to accomplish tasks related to the phpGRC project. Agents may request the credentials if desired.
Env: PHP 8.3, Node 20, SQLite for tests; PHP files must pass PHPStan max level, Psalm, and PHPUnit.
When you modify a PHP file, run PHPMD on that file (e.g. `(cd api && php vendor/bin/phpmd path/to/File.php ansi phpmd.xml)`) and resolve any violations before finishing.
PR rules: small diffs; conventional commits; CI green required.
Consult guides in docs/ai after file changes to ensure additional changes are not required.