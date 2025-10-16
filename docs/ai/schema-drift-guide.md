# Schema Drift Workflow Guide

## Purpose
Explain how to run and troubleshoot the schema-drift workflow that compares our documented schema against the database generated from migrations. Use this whenever schema updates or workflow failures occur.

---

## Prerequisites
- Docker / container support (workflow runs MySQL 8.0).
- PHP 8.3 with Composer installed (workflow uses `shivammathur/setup-php`).
- Access to the repo with `docs/db/DB-SCHEMA.md` and `api/scripts/schema_docgen.php`.
- Ability to run Laravel Artisan commands locally (`php artisan`).

---

## Workflow Overview (`.github/workflows/schema-drift.yml`)
1. **Checkout** repository
2. **Set up PHP 8.3** with extensions (pdo_mysql)
3. **Install API dependencies** (`composer install`, copy `.env`, `php artisan key:generate`)
4. **Start MySQL service** on port `3307`, database `phpgrc_test`
5. **`php artisan migrate:fresh --force`** against MySQL container
6. **Generate live schema:** `php scripts/schema_docgen.php > docs/db/schema.live.md`
7. **Normalize docs schema:** `php api/scripts/schema_normalize.php docs/db/DB-SCHEMA.md > /tmp/schema.doc.norm`
8. **Normalize live schema:** `php api/scripts/schema_normalize.php docs/db/schema.live.md > /tmp/schema.live.norm`
9. **Diff normalized output** (`diff -u`); failure indicates drift
10. On failure, **upload artifacts** to inspect live vs. docs schema

---

## Running Locally
```bash
# Start MySQL 8 container (optional)
docker run --rm -d \
  --name phpgrc-mysql \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=phpgrc_test \
  -p 3307:3306 mysql:8.0

# Install dependencies
cd api
composer install
cp -n .env.example .env
php artisan key:generate

# Migrate fresh against container
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=3307 \
DB_DATABASE=phpgrc_test \
DB_USERNAME=root \
DB_PASSWORD=root \
php artisan migrate:fresh --force

# Generate live schema
php scripts/schema_docgen.php > ../docs/db/schema.live.md

# Normalize and diff
php scripts/schema_normalize.php ../docs/db/DB-SCHEMA.md > /tmp/schema.doc.norm
php scripts/schema_normalize.php ../docs/db/schema.live.md > /tmp/schema.live.norm
diff -u /tmp/schema.doc.norm /tmp/schema.live.norm
```

Clean up the container when done:
```bash
docker stop phpgrc-mysql
```

---

## Common Failure Scenarios & Fixes
| Symptom | Likely Cause | Resolution |
| --- | --- | --- |
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL container not ready or wrong port | Ensure service is running (3307). Re-run after container is healthy. |
| Schema diff shows new table (e.g., `permissions`) | Migration added/removed tables without doc update | Update `docs/db/DB-SCHEMA.md` to match or revert placeholder migrations. |
| Diff shows column/type mismatch | Migration changed column definition but docs not updated | Regenerate docs and copy relevant section into `DB-SCHEMA.md`. |
| Workflow fails at migrate step | Missing migration dependencies or bad seed data | Fix migration logic; verify `php artisan migrate:fresh` passes locally. |
| `schema_docgen.php` references wrong DB | `.env` not configured for container | Use env vars during run (`DB_HOST`, `DB_PORT`, etc.). |

---

## Updating Documentation
When legitimate schema changes occur:
1. Update migrations and run them locally.
2. Regenerate `schema.live.md` via `php scripts/schema_docgen.php > docs/db/schema.live.md`.
3. Review diff versus `docs/db/DB-SCHEMA.md` and apply updates.
4. Normalize/diff to confirm no further drift.
5. Remove `schema.live.md` before committing.
6. Commit schema changes with clear message (e.g., `docs: update DB-SCHEMA for new permissions`).
7. Run `./scripts/check-migration-doc-drift.sh` (the CI check) to ensure migration/doc parity.

---

## Best Practices
- Avoid committing temporary migrations or tables (`permissions` stub) without documentation.
- Always run `migrate:fresh` against MySQL (not SQLite) before regenerating docs.
- Remove generated `schema.live.md` after diffing to prevent accidental commits.
- Use backlog entries for future schema work so placeholder migrations aren’t required.

---

## References
- Workflow file: `.github/workflows/schema-drift.yml`
- Live schema generator: `api/scripts/schema_docgen.php`
- Normalizer script: `api/scripts/schema_normalize.php`
- Documentation: `docs/db/DB-SCHEMA.md`
- Backlog tracking (fine-grained permissions): `docs/BACKLOG.md#core-017`
