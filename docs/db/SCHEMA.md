# phpGRC Database Schema

Snapshot generated from migrations in `api/database/migrations` as of 2025-09-21.

- SQL dialect: targets MySQL 8.0+
- Time zones: `timestampsTz` / `dateTimeTz` store UTC.  
- IDs:
  - `users.id`: `BIGINT UNSIGNED` auto-increment.
  - `roles.id`: human-readable `VARCHAR(191)` primary key (e.g., `role_admin`).
  - `audit_events.id` and `exports.id`: 26-char ULID strings.
  - `evidence.id`: server-generated `ev_<ULID>` (prefix `ev_` + 26-char ULID).
  - `avatars.id`: string PK (scaffold).
- Notation: `✓` = NOT NULL, `✗` = NULL allowed. Sizes shown are MySQL effective lengths.

---

## Tables

### `users`
**Purpose:** Auth users.

| Column           | Type                 | Null | Default     | Notes                    |
|------------------|----------------------|------|-------------|--------------------------|
| id               | BIGINT UNSIGNED      | ✓    | AUTO_INC    | PK                       |
| name             | VARCHAR(255)         | ✗    | NULL        |                          |
| email            | VARCHAR(255)         | ✓    | —           | UNIQUE                   |
| password         | VARCHAR(255)         | ✓    | —           |                          |
| remember_token   | VARCHAR(100)         | ✗    | NULL        |                          |
| created_at       | TIMESTAMP            | ✗    | NULL        |                          |
| updated_at       | TIMESTAMP            | ✗    | NULL        |                          |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `UNIQUE (email)`

---

### `personal_access_tokens`
**Purpose:** Sanctum tokens.

| Column          | Type                 | Null | Default | Notes                             |
|-----------------|----------------------|------|---------|-----------------------------------|
| id              | BIGINT UNSIGNED      | ✓    | AUTO_INC| PK                                |
| tokenable_type  | VARCHAR(255)         | ✓    | —       | morphs                            |
| tokenable_id    | BIGINT UNSIGNED      | ✓    | —       | morphs                            |
| name            | VARCHAR(255)         | ✓    | —       |                                   |
| token           | VARCHAR(64)          | ✓    | —       | UNIQUE                            |
| abilities       | TEXT                 | ✗    | NULL    |                                   |
| last_used_at    | TIMESTAMP            | ✗    | NULL    |                                   |
| expires_at      | TIMESTAMP            | ✗    | NULL    |                                   |
| created_at      | TIMESTAMP            | ✗    | NULL    |                                   |
| updated_at      | TIMESTAMP            | ✗    | NULL    |                                   |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `UNIQUE (token)`
- `INDEX tokenable_type_tokenable_id (tokenable_type, tokenable_id)`

---

### `roles`
**Purpose:** Role catalog.

| Column     | Type          | Null | Default | Notes                 |
|------------|---------------|------|---------|-----------------------|
| id         | VARCHAR(191)  | ✓    | —       | PK, human-readable    |
| name       | VARCHAR(255)  | ✓    | —       | UNIQUE                |
| created_at | TIMESTAMP TZ  | ✗    | NULL    |                       |
| updated_at | TIMESTAMP TZ  | ✗    | NULL    |                       |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `UNIQUE (name)`

---

### `role_user`
**Purpose:** User↔Role mapping.

| Column  | Type          | Null | Default | Notes                      |
|---------|---------------|------|---------|----------------------------|
| user_id | BIGINT UNSIGNED | ✓  | —       | FK → `users(id)` CASCADE   |
| role_id | VARCHAR(191)  | ✓    | —       | FK → `roles(id)` CASCADE   |

**Indexes & Constraints**
- `PRIMARY KEY (user_id, role_id)`
- `FOREIGN KEY role_user_user_id_fk (user_id) REFERENCES users(id) ON DELETE CASCADE`
- `FOREIGN KEY role_user_role_id_fk (role_id) REFERENCES roles(id) ON DELETE CASCADE`

---

### `audit_events`
**Purpose:** Immutable audit trail.

| Column       | Type           | Null | Default     | Notes                                               |
|--------------|----------------|------|-------------|-----------------------------------------------------|
| id           | CHAR(26)       | ✓    | —           | ULID PK                                             |
| occurred_at  | DATETIME TZ    | ✓    | —           | indexed                                             |
| actor_id     | BIGINT UNSIGNED| ✗    | NULL        | optional, indexed                                   |
| action       | VARCHAR(191)   | ✓    | —           | indexed                                             |
| category     | VARCHAR(64)    | ✓    | —           | indexed; see Categories below                       |
| entity_type  | VARCHAR(128)   | ✓    | —           | indexed                                             |
| entity_id    | VARCHAR(191)   | ✓    | —           | indexed                                             |
| ip           | VARCHAR(45)    | ✗    | NULL        | IPv4/IPv6                                           |
| ua           | VARCHAR(255)   | ✗    | NULL        |                                                     |
| meta         | JSON           | ✗    | NULL        | arbitrary key/values                                |
| created_at   | DATETIME TZ    | ✓    | CURRENT     | record creation time                                |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `INDEX (occurred_at)`
- `INDEX (actor_id)`
- `INDEX (action)`
- `INDEX (category)`
- `INDEX (entity_type)`
- `INDEX (entity_id)`
- `INDEX ae_occurred_id_idx (occurred_at, id)`  *(pagination aid)*
- `INDEX idx_audit_cat_occurred_at (category, occurred_at)`  *(added 2025-09-20)*
- `INDEX idx_audit_action_occurred_at (action, occurred_at)` *(added 2025-09-20)*

**Categories (application-level, not enforced in DB)**
`SYSTEM, RBAC, AUTH, SETTINGS, EXPORTS, EVIDENCE, AVATARS, AUDIT`

---

### `evidence`
**Purpose:** Binary evidence storage.

| Column      | Type                 | Null | Default | Notes                                              |
|-------------|----------------------|------|---------|----------------------------------------------------|
| id          | VARCHAR(255)         | ✓    | —       | `ev_<ULID>` PK (server-generated)                  |
| owner_id    | BIGINT UNSIGNED      | ✓    | —       | FK → `users(id)` CASCADE                           |
| filename    | VARCHAR(255)         | ✓    | —       |                                                    |
| mime        | VARCHAR(128)         | ✓    | —       |                                                    |
| size_bytes  | BIGINT UNSIGNED      | ✓    | —       |                                                    |
| sha256      | CHAR(64)             | ✓    | —       | hex string                                         |
| version     | INT UNSIGNED         | ✓    | 1       |                                                    |
| bytes       | BLOB (LONGBLOB on MySQL) | ✓ | —   | body; migration upgrades to `LONGBLOB` on MySQL    |
| created_at  | DATETIME TZ          | ✓    | CURRENT |                                                    |
| updated_at  | DATETIME TZ          | ✓    | CURRENT ON UPDATE |                                          |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `INDEX evidence_owner_filename_idx (owner_id, filename)`
- `INDEX sha256 (sha256)`
- `INDEX evidence_created_id_idx (created_at, id)`
- `FOREIGN KEY evidence_owner_id_fk (owner_id) REFERENCES users(id) ON DELETE CASCADE`

---

### `avatars`
**Purpose:** User avatar metadata (Phase 4 scaffold).

| Column      | Type                 | Null | Default | Notes                                |
|-------------|----------------------|------|---------|--------------------------------------|
| id          | VARCHAR(255)         | ✓    | —       | PK                                   |
| user_id     | BIGINT UNSIGNED      | ✓    | —       | UNIQUE, FK → `users(id)` CASCADE     |
| path        | VARCHAR(255)         | ✓    | —       | storage path (deferred)              |
| mime        | VARCHAR(64)          | ✓    | —       |                                      |
| size_bytes  | BIGINT UNSIGNED      | ✓    | —       |                                      |
| width       | INT UNSIGNED         | ✓    | —       | px                                   |
| height      | INT UNSIGNED         | ✓    | —       | px                                   |
| created_at  | DATETIME TZ          | ✓    | NULL    |                                      |
| updated_at  | DATETIME TZ          | ✓    | NULL    |                                      |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `UNIQUE (user_id)`
- `FOREIGN KEY avatars_user_id_fk (user_id) REFERENCES users(id) ON DELETE CASCADE`

---

### `exports`
**Purpose:** Export job tracking.

| Column          | Type              | Null | Default | Notes                                              |
|-----------------|-------------------|------|---------|----------------------------------------------------|
| id              | CHAR(26)          | ✓    | —       | ULID PK                                            |
| type            | VARCHAR(16)       | ✓    | —       | `csv` \| `json` \| `pdf`                           |
| params          | JSON              | ✗    | NULL    | request parameters                                 |
| status          | VARCHAR(32)       | ✓    | —       | `pending` \| `running` \| `completed` \| `failed`  |
| progress        | TINYINT UNSIGNED  | ✓    | 0       | 0–100                                              |
| artifact_disk   | VARCHAR(64)       | ✗    | NULL    |                                                    |
| artifact_path   | VARCHAR(191)      | ✗    | NULL    |                                                    |
| artifact_mime   | VARCHAR(191)      | ✗    | NULL    |                                                    |
| artifact_size   | BIGINT UNSIGNED   | ✗    | NULL    |                                                    |
| artifact_sha256 | CHAR(64)          | ✗    | NULL    |                                                    |
| created_at      | DATETIME TZ       | ✓    | CURRENT |                                                    |
| completed_at    | DATETIME TZ       | ✗    | NULL    |                                                    |
| failed_at       | DATETIME TZ       | ✗    | NULL    |                                                    |
| error_code      | VARCHAR(64)       | ✗    | NULL    |                                                    |
| error_note      | VARCHAR(191)      | ✗    | NULL    |                                                    |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `INDEX exports_status_type_idx (status, type)`

---

### `core_settings`
**Purpose:** Persisted application settings.

| Column      | Type           | Null | Default | Notes                                  |
|-------------|----------------|------|---------|----------------------------------------|
| key         | VARCHAR(255)   | ✓    | —       | PK                                     |
| value       | LONGTEXT       | ✓    | —       | JSON stored as text                    |
| type        | VARCHAR(16)    | ✓    | 'json'  | discriminator                          |
| updated_by  | BIGINT UNSIGNED| ✗    | NULL    | optional user id (no FK)               |
| created_at  | TIMESTAMP      | ✗    | NULL    |                                        |
| updated_at  | TIMESTAMP      | ✗    | NULL    |                                        |

**Indexes & Constraints**
- `PRIMARY KEY (key)`

---

## Relationships

- `users (1) ──< role_user >── (N) roles`
- `users (1) ──< evidence (N)` *(cascade delete)*
- `users (1) ── avatars (1)` *(unique, cascade delete)*
- `audit_events`: no FKs; `actor_id` is informational.
- `core_settings.updated_by`: informational only, no FK.

---

## Engine Notes

- **MySQL**
  - `evidence.bytes` is upgraded to `LONGBLOB` for ≥25 MB payloads.
  - FK names may differ per platform; shown names are canonical.

---

## Recent Changes

- **FKs:** `evidence.owner_id → users(id)` and `avatars.user_id → users(id)` with `ON DELETE CASCADE`.  
- **IDs:** `evidence.id` is now server-generated `ev_<ULID>` and not user-controlled.  
- **Audit indexes:** composite indexes on `(category, occurred_at)` and `(action, occurred_at)`.

