# /docs/db/SCHEMA.md
# phpGRC — Database Schema (mirror of migrations)

**Source of truth:** Laravel migrations in `/api/database/migrations`.  
**Scope date:** 2025-09-20.  
**Note:** This document mirrors structure. Business rules live in services, middleware, and OpenAPI.

---

## Conventions

- **IDs**
  - `users.id`: `BIGINT` auto-increment.
  - `roles.id`: string PK, human-readable slug `^role_[a-z0-9_]+(_[0-9]+)?$`, length ≤191.
  - `audit_events.id`: ULID 26 chars.
  - `exports.id`: ULID 26 chars.
  - `evidence.id`: string (comment: `ev_<ULID>` expected).
  - `avatars.id`: string (stub).
- **Timestamps**
  - `*_at` use `datetime` or `datetimeTz` per migration. Audit uses immutable in model casts.
- **FKs**
  - Some FKs are logical only (not enforced in DB): e.g., `evidence.owner_id`, `avatars.user_id`.
- **RBAC**
  - Pivot table `role_user` has composite PK (`user_id`,`role_id`).

---

## Tables

### users
From: `0000_00_00_000000_create_users_table.php`

| Column          | Type              | Null | Default    | Key    | Notes          |
|-----------------|-------------------|------|------------|--------|----------------|
| id              | BIGINT UNSIGNED   | NO   | auto inc   | PK     |                |
| name            | VARCHAR(255)      | YES  |            |        |                |
| email           | VARCHAR(255)      | NO   |            | UNIQUE |                |
| password        | VARCHAR(255)      | NO   |            |        |                |
| remember_token  | VARCHAR(100)      | YES  |            |        | Laravel helper |
| created_at      | TIMESTAMP         | YES  |            |        |                |
| updated_at      | TIMESTAMP         | YES  |            |        |                |

---

### personal_access_tokens
From: `0000_00_00_000001_create_personal_access_tokens_table.php`

| Column         | Type              | Null | Default | Key     | Notes                         |
|----------------|-------------------|------|---------|---------|-------------------------------|
| id             | BIGINT UNSIGNED   | NO   | auto    | PK      |                               |
| tokenable_type | VARCHAR(255)      | NO   |         | INDEX   | via `morphs('tokenable')`     |
| tokenable_id   | BIGINT UNSIGNED   | NO   |         | INDEX   |                               |
| name           | VARCHAR(255)      | NO   |         |         |                               |
| token          | VARCHAR(64)       | NO   |         | UNIQUE  |                               |
| abilities      | TEXT              | YES  |         |         | JSON string                   |
| last_used_at   | TIMESTAMP         | YES  |         |         |                               |
| expires_at     | TIMESTAMP         | YES  |         |         |                               |
| created_at     | TIMESTAMP         | YES  |         |         |                               |
| updated_at     | TIMESTAMP         | YES  |         |         |                               |

---

### roles
From: `0000_00_00_000100_create_roles_table.php`

| Column     | Type         | Null | Default | Key    | Notes              |
|------------|--------------|------|---------|--------|--------------------|
| id         | VARCHAR(191) | NO   |         | PK     | e.g., `role_admin` |
| name       | VARCHAR(255) | NO   |         | UNIQUE | Human name         |
| created_at | TIMESTAMPTZ  | YES  |         |        |                    |
| updated_at | TIMESTAMPTZ  | YES  |         |        |                    |

---

### role_user
From: `0000_00_00_000101_create_role_user_table.php`

| Column  | Type              | Null | Default | Key                | Notes             |
|---------|-------------------|------|---------|--------------------|-------------------|
| user_id | BIGINT UNSIGNED   | NO   |         | FK→users.id, INDEX | cascade on delete |
| role_id | VARCHAR(191)      | NO   |         | FK→roles.id, INDEX | cascade on delete |

- Primary Key: (`user_id`,`role_id`)

---

### audit_events
From: `0000_00_00_000110_create_audit_events_table.php`, `2025_09_20_000000_add_indexes_to_audit_events_table.php`

| Column       | Type              | Null | Default             | Key                               | Notes                      |
|--------------|-------------------|------|---------------------|-----------------------------------|----------------------------|
| id           | VARCHAR(26)       | NO   |                     | PK                                | ULID                       |
| occurred_at  | TIMESTAMPTZ       | NO   |                     | INDEX                             | Event time                 |
| actor_id     | BIGINT UNSIGNED   | YES  |                     | INDEX                             | Optional user id           |
| action       | VARCHAR(191)      | NO   |                     | INDEX                             | Canonical dotted verb      |
| category     | VARCHAR(64)       | NO   |                     | INDEX                             | See enums below            |
| entity_type  | VARCHAR(128)      | NO   |                     | INDEX                             | e.g., `user`               |
| entity_id    | VARCHAR(191)      | NO   |                     | INDEX                             | Target identifier          |
| ip           | VARCHAR(45)       | YES  |                     |                                   | IPv4/IPv6                  |
| ua           | VARCHAR(255)      | YES  |                     |                                   | User-Agent                 |
| meta         | JSON              | YES  |                     |                                   | Arbitrary                  |
| created_at   | TIMESTAMPTZ       | NO   | CURRENT_TIMESTAMP   |                                   | Insert time                |

Indexes:
- `ae_occurred_id_idx` on `(occurred_at, id)`
- `idx_audit_cat_occurred_at` on `(category, occurred_at)`
- `idx_audit_action_occurred_at` on `(action, occurred_at)`

Model casts:
- `occurred_at`, `created_at`: immutable datetime
- `meta`: array

---

### evidence
From: `0000_00_00_000120_create_evidence_table.php`

| Column      | Type                    | Null | Default           | Key    | Notes                                               |
|-------------|-------------------------|------|-------------------|--------|-----------------------------------------------------|
| id          | VARCHAR(255)            | NO   |                   | PK     | expected `ev_<ULID>`                                |
| owner_id    | BIGINT UNSIGNED         | NO   |                   | INDEX  | logical FK→users.id (not enforced)                  |
| filename    | VARCHAR(255)            | NO   |                   | INDEX  | with `owner_id`                                     |
| mime        | VARCHAR(128)            | NO   |                   |        |                                                     |
| size_bytes  | BIGINT UNSIGNED         | NO   |                   |        |                                                     |
| sha256      | VARCHAR(64)             | NO   |                   | INDEX  |                                                     |
| version     | INT UNSIGNED            | NO   | 1                 |        |                                                     |
| bytes       | BLOB (MySQL LONGBLOB)   | NO   |                   |        | upgraded to LONGBLOB on MySQL via raw statement     |
| created_at  | TIMESTAMPTZ             | NO   | CURRENT_TIMESTAMP |        | covered by `evidence_created_id_idx`                |
| updated_at  | TIMESTAMPTZ             | NO   | CURRENT_TIMESTAMP |        | on update current                                   |

Indexes:
- `(owner_id, filename)`
- `(sha256)`
- `evidence_created_id_idx` on `(created_at, id)`

Model casts: `owner_id`, `size_bytes`, `version` ints; `bytes` string; `created_at`,`updated_at` datetime.

---

### avatars
From: `0000_00_00_000130_create_avatars_table.php` (Phase 4 placeholder)

| Column      | Type              | Null | Default | Key     | Notes                               |
|-------------|-------------------|------|---------|---------|-------------------------------------|
| id          | VARCHAR(255)      | NO   |         | PK      | stub                                |
| user_id     | BIGINT UNSIGNED   | NO   |         | UNIQUE  | 1:1 user (logical FK not enforced)  |
| path        | VARCHAR(255)      | NO   |         |         | storage path (deferred)             |
| mime        | VARCHAR(64)       | NO   |         |         |                                     |
| size_bytes  | BIGINT UNSIGNED   | NO   |         |         |                                     |
| width       | INT UNSIGNED      | NO   |         |         | pixels                              |
| height      | INT UNSIGNED      | NO   |         |         | pixels                              |
| created_at  | TIMESTAMPTZ       | YES  |         |         |                                     |
| updated_at  | TIMESTAMPTZ       | YES  |         |         |                                     |

---

### exports
From: `0000_00_00_000140_create_exports_table.php`

| Column           | Type              | Null | Default           | Key     | Notes                                           |
|------------------|-------------------|------|-------------------|---------|-------------------------------------------------|
| id               | VARCHAR(26)       | NO   |                   | PK      | ULID                                            |
| type             | VARCHAR(16)       | NO   |                   |         | `csv` \| `json` \| `pdf`                        |
| params           | JSON              | YES  |                   |         | request parameters                               |
| status           | VARCHAR(32)       | NO   |                   | INDEX   | migration text implies `pending|running|completed|failed` |
| progress         | TINYINT UNSIGNED  | NO   | 0                 |         | 0–100                                           |
| artifact_disk    | VARCHAR(64)       | YES  |                   |         |                                                 |
| artifact_path    | VARCHAR(191)      | YES  |                   |         |                                                 |
| artifact_mime    | VARCHAR(191)      | YES  |                   |         |                                                 |
| artifact_size    | BIGINT UNSIGNED   | YES  |                   |         |                                                 |
| artifact_sha256  | VARCHAR(64)       | YES  |                   |         |                                                 |
| created_at       | TIMESTAMPTZ       | NO   | CURRENT_TIMESTAMP |         |                                                 |
| completed_at     | TIMESTAMPTZ       | YES  |                   |         |                                                 |
| failed_at        | TIMESTAMPTZ       | YES  |                   |         |                                                 |
| error_code       | VARCHAR(64)       | YES  |                   |         |                                                 |
| error_note       | VARCHAR(191)      | YES  |                   |         |                                                 |

Indexes:
- `(status, type)`

Model helpers:
- `createPending(type, params=[])` → status `pending`, progress 0.
- `markRunning()` → status `running`, progress ≥10.
- `markCompleted()` → status `completed`, progress 100.
- `markFailed(code='INTERNAL_ERROR', note='')` → status `failed`.

**OpenAPI drift:** spec uses `status` enum `queued|running|done|failed`. See “Drift & fixes”.

---

### core_settings
From: `2025_09_04_000001_create_core_settings_table.php`

| Column     | Type              | Null | Default | Key | Notes                                          |
|------------|-------------------|------|---------|-----|------------------------------------------------|
| key        | VARCHAR(255)      | NO   |         | PK  | string PK for cross-driver upsert              |
| value      | LONGTEXT          | NO   |         |     | JSON string (app encodes/decodes)              |
| type       | VARCHAR(16)       | NO   | 'json'  |     | discriminator                                   |
| updated_by | BIGINT UNSIGNED   | YES  |         |     | user id (logical)                               |
| created_at | TIMESTAMP         | YES  |         |     |                                                |
| updated_at | TIMESTAMP         | YES  |         |     |                                                |

Model casts: `value` string passthrough; `updated_by` int.

---

### (No-op) users_and_auth_tables
From: `2025_09_04_000002_create_users_and_auth_tables.php`  
Stub only (no DDL).

---

## Seed data

From: `Database\Seeders\RolesSeeder`, `TestRbacSeeder`

- Roles inserted if RBAC persistence enabled or tests running:
  - `role_admin` → `Admin`
  - `role_auditor` → `Auditor`
  - `role_risk_mgr` → `Risk Manager`
  - `role_user` → `User`

---

## Factories (shapes)

- `Database\Factories\UserFactory`
  - `name`: `Test User ####`
  - `email`: `user####@example.test`
  - `password`: bcrypt('secret')
- `Database\Factories\RoleFactory`
  - `id`: `role_<slug(name)>`
  - `name`: `Role N` or provided via `named()`
- `Database\Factories\AuditEventFactory`
  - `id`: ULID
  - `occurred_at`: now UTC
  - `actor_id`: null
  - `action`: `test.event`
  - `category`: `TEST`  **(not in OpenAPI enum)**
  - `entity_type`: `test`
  - `entity_id`: ULID
  - `ip`: `127.0.0.1`
  - `ua`: `phpunit`
  - `meta`: `[]`
  - `created_at`: now UTC

---

## Enumerations and constants inventory

**From OpenAPI (`docs/api/openapi.yaml`)**

- `AuditCategory` (query param and schema):  
  `SYSTEM | RBAC | AUTH | SETTINGS | EXPORTS | EVIDENCE | AVATARS | AUDIT`
- `Order` (query param): `asc | desc`
- `Avatar size` (query param): `32 | 64 | 128`
- `Export type` (path and schemas): `csv | json | pdf`
- `Export status` (schema `ExportStatusResponse`): `queued | running | done | failed`
- `Setup nextStep` (schema `SetupStatusResponse.nextStep`): `db_config | app_key | schema_init | admin_seed | admin_mfa_verify | null`
- `DB driver` (schema `SetupDbTestRequest.driver`): `mysql | pgsql | sqlite | sqlsrv`

**From PHP code**

- `Export::markFailed()` default error code: `"INTERNAL_ERROR"`.
- `RolesSeeder` canonical role IDs: `role_admin`, `role_auditor`, `role_risk_mgr`, `role_user`.
- `AuditEventFactory` test constants: `action="test.event"`, `category="TEST"`.

**From migrations (implicit)**

- `exports.status` comment implies: `pending | running | completed | failed`.

---

## Drift & fixes (action items)

1. **Export status mismatch**
   - DB/Model: `pending | running | completed | failed`
   - OpenAPI: `queued | running | done | failed`
   - **Decision needed:** align one side. Minimal code churn = update OpenAPI to `pending|running|completed|failed`, or change model/helpers + any UI text to `queued|running|done|failed`.

2. **Audit category in tests**
   - Factory uses `category="TEST"`, not in OpenAPI enum.
   - **Option A:** extend enum to include `TEST` for test environments.  
   - **Option B:** change factory default to a valid enum (e.g., `SYSTEM`) and override in test cases as needed.

3. **Foreign keys (logical vs enforced)**
   - `evidence.owner_id` and `avatars.user_id` are not FK-enforced.
   - **Confirm:** keep logical only, or add FKs with `on delete` policies.

4. **Evidence ID format**
   - Migration allows any string; comments imply `ev_<ULID>`.
   - **Consider:** add format validation at service layer or a DB check constraint where supported.

---

## Query helpers (suggested for tests)

```sql
-- Audit latest by category
SELECT * FROM audit_events
WHERE category = 'RBAC'
ORDER BY occurred_at DESC, id DESC
LIMIT 100;
```

```sql
-- Evidence by SHA-256 prefix
SELECT id, sha256 FROM evidence
WHERE sha256 LIKE 'ABCDEF%';
```

---

## CI guardrail (doc drift)

Add a lightweight check that fails when migrations change but this file does not:

- Detect migration diffs:
  - If `git diff --name-only origin/main... -- api/database/migrations | wc -l > 0`
  - Then require changes in `/docs/db/SCHEMA.md`.
- Optionally run a linter that verifies table names and column sets.

---

## Appendix: model casts (for serialization)

- `App\Models\AuditEvent`: `occurred_at`, `created_at` immutable; `meta` array.
- `App\Models\Export`: `params` array; `progress` int; `artifact_size` int; `created_at/completed_at/failed_at` immutable.
- `App\Models\Evidence`: numeric casts for sizes and version; timestamps as datetime.
- `App\Models\Setting`: `value` string; `updated_by` int; timestamps datetime.

---
