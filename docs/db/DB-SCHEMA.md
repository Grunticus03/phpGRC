# phpGRC Database Schema

Snapshot generated from migrations against **phpgrc** as of 2025-10-08 (UTC).

- SQL dialect: MySQL 8.0+
- All times UTC.

---

## Tables

### `audit_events`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | varchar(26) | ✓ | NULL | — |
| occurred_at | datetime | ✓ | NULL | — |
| actor_id | bigint unsigned | ✗ | NULL | — |
| action | varchar(191) | ✓ | NULL | — |
| category | varchar(64) | ✓ | NULL | — |
| entity_type | varchar(128) | ✓ | NULL | — |
| entity_id | varchar(191) | ✓ | NULL | — |
| ip | varchar(45) | ✗ | NULL | — |
| ua | varchar(255) | ✗ | NULL | — |
| meta | json | ✗ | NULL | — |
| created_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `INDEX ae_occurred_id_idx (occurred_at, id)`
- `INDEX audit_events_action_index (action)`
- `INDEX audit_events_actor_id_index (actor_id)`
- `INDEX audit_events_category_index (category)`
- `INDEX audit_events_entity_id_index (entity_id)`
- `INDEX audit_events_entity_type_index (entity_type)`
- `INDEX audit_events_occurred_at_index (occurred_at)`
- `INDEX idx_audit_action_occurred_at (action, occurred_at)`
- `INDEX idx_audit_cat_occurred_at (category, occurred_at)`

---

### `avatars`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | varchar(255) | ✓ | NULL | — |
| user_id | bigint unsigned | ✓ | NULL | — |
| path | varchar(255) | ✓ | NULL | — |
| mime | varchar(64) | ✓ | NULL | — |
| size_bytes | bigint unsigned | ✓ | NULL | — |
| width | int unsigned | ✓ | NULL | — |
| height | int unsigned | ✓ | NULL | — |
| created_at | timestamp | ✗ | NULL | — |
| updated_at | timestamp | ✗ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `UNIQUE INDEX avatars_user_id_unique (user_id)`

- `FOREIGN KEY avatars_user_id_fk (user_id) REFERENCES users(id) ON UPDATE NO ACTION ON DELETE CASCADE`

---

### `core_settings`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| key | varchar(255) | ✓ | NULL | — |
| value | longtext | ✓ | NULL | — |
| type | varchar(16) | ✓ | 'json' | — |
| updated_by | bigint unsigned | ✗ | NULL | — |
| created_at | timestamp | ✗ | NULL | — |
| updated_at | timestamp | ✗ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (key)`

---

### `evidence`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | varchar(255) | ✓ | NULL | — |
| owner_id | bigint unsigned | ✓ | NULL | — |
| filename | varchar(255) | ✓ | NULL | — |
| mime | varchar(128) | ✓ | NULL | — |
| size_bytes | bigint unsigned | ✓ | NULL | — |
| sha256 | varchar(64) | ✓ | NULL | — |
| version | int unsigned | ✓ | 1 | — |
| bytes | longblob | ✗ | NULL | — |
| created_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED |
| updated_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED on update CURRENT_TIMESTAMP |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `INDEX evidence_created_id_idx (created_at, id)`
- `INDEX evidence_owner_id_filename_index (owner_id, filename)`
- `INDEX evidence_sha256_index (sha256)`

- `FOREIGN KEY evidence_owner_id_fk (owner_id) REFERENCES users(id) ON UPDATE NO ACTION ON DELETE CASCADE`

---

### `mime_labels`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | bigint unsigned | ✓ | NULL | auto_increment |
| value | varchar(191) | ✓ | NULL | — |
| match_type | enum('exact','prefix') | ✓ | 'exact' | — |
| label | varchar(191) | ✓ | NULL | — |
| created_at | timestamp | ✗ | NULL | — |
| updated_at | timestamp | ✗ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `UNIQUE INDEX mime_labels_value_match_type_unique (value, match_type)`

**Seed Data**
- Installed by migration `0000_00_00_000150_create_mime_labels_table.php` with common MIME to label mappings (e.g., PDF document, PNG image) and vendor prefixes (e.g., Office formats).

---

### `exports`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | varchar(26) | ✓ | NULL | — |
| type | varchar(16) | ✓ | NULL | — |
| params | json | ✗ | NULL | — |
| status | varchar(32) | ✓ | NULL | — |
| progress | tinyint unsigned | ✓ | 0 | — |
| artifact_disk | varchar(64) | ✗ | NULL | — |
| artifact_path | varchar(191) | ✗ | NULL | — |
| artifact_mime | varchar(191) | ✗ | NULL | — |
| artifact_size | bigint unsigned | ✗ | NULL | — |
| artifact_sha256 | varchar(64) | ✗ | NULL | — |
| created_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED |
| completed_at | datetime | ✗ | NULL | — |
| failed_at | datetime | ✗ | NULL | — |
| error_code | varchar(64) | ✗ | NULL | — |
| error_note | varchar(191) | ✗ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `INDEX exports_status_index (status)`
- `INDEX exports_status_type_index (status, type)`

---

### `role_user`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| user_id | bigint unsigned | ✓ | NULL | — |
| role_id | varchar(191) | ✓ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (user_id, role_id)`
- `INDEX role_user_role_id_foreign (role_id)`

- `FOREIGN KEY role_user_role_id_foreign (role_id) REFERENCES roles(id) ON UPDATE NO ACTION ON DELETE CASCADE`
- `FOREIGN KEY role_user_user_id_foreign (user_id) REFERENCES users(id) ON UPDATE NO ACTION ON DELETE CASCADE`

---

### `roles`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | varchar(191) | ✓ | NULL | — |
| name | varchar(255) | ✓ | NULL | — |
| created_at | timestamp | ✗ | NULL | — |
| updated_at | timestamp | ✗ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `UNIQUE INDEX roles_name_unique (name)`

**Seed Data**
- Inserted by migration `2025_09_22_000003_seed_default_roles.php`:
  - `role_admin` → `Admin`
  - `role_auditor` → `Auditor`
  - `role_risk_mgr` → `Risk Manager`
  - `role_user` → `User`

---

### `users`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | bigint unsigned | ✓ | NULL | auto_increment |
| name | varchar(255) | ✗ | NULL | — |
| email | varchar(255) | ✓ | NULL | — |
| password | varchar(255) | ✓ | NULL | — |
| remember_token | varchar(100) | ✗ | NULL | — |
| created_at | timestamp | ✗ | NULL | — |
| updated_at | timestamp | ✗ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `UNIQUE INDEX users_email_unique (email)`

---

