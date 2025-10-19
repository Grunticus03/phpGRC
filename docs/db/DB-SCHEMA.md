# phpGRC Database Schema

Snapshot generated from migrations against **phpgrc_test** as of 2026-01-12 (UTC).

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

### `brand_assets`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | varchar(255) | ✓ | NULL | — |
| profile_id | varchar(255) | ✓ | 'bp_default' | — |
| kind | varchar(32) | ✓ | NULL | — |
| name | varchar(160) | ✓ | NULL | — |
| mime | varchar(96) | ✓ | NULL | — |
| size_bytes | bigint unsigned | ✓ | NULL | — |
| sha256 | varchar(64) | ✓ | NULL | — |
| bytes | longblob | ✗ | NULL | — |
| uploaded_by | bigint unsigned | ✗ | NULL | — |
| uploaded_by_name | varchar(120) | ✗ | NULL | — |
| created_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED |
| updated_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED on update CURRENT_TIMESTAMP |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `INDEX brand_assets_created_at_index (created_at)`
- `INDEX brand_assets_kind_index (kind)`
- `INDEX brand_assets_profile_id_index (profile_id)`
- `INDEX brand_assets_sha256_index (sha256)`
- `INDEX brand_assets_uploaded_by_foreign (uploaded_by)`

- `FOREIGN KEY brand_assets_uploaded_by_foreign (uploaded_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL`

---

### `brand_profiles`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | varchar(255) | ✓ | NULL | — |
| name | varchar(120) | ✓ | NULL | — |
| is_default | tinyint(1) | ✓ | 0 | — |
| is_active | tinyint(1) | ✓ | 0 | — |
| is_locked | tinyint(1) | ✓ | 0 | — |
| title_text | varchar(120) | ✓ | NULL | — |
| favicon_asset_id | varchar(64) | ✗ | NULL | — |
| primary_logo_asset_id | varchar(64) | ✗ | NULL | — |
| secondary_logo_asset_id | varchar(64) | ✗ | NULL | — |
| header_logo_asset_id | varchar(64) | ✗ | NULL | — |
| footer_logo_asset_id | varchar(64) | ✗ | NULL | — |
| background_login_asset_id | varchar(64) | ✗ | NULL | — |
| background_main_asset_id | varchar(64) | ✗ | NULL | — |
| footer_logo_disabled | tinyint(1) | ✓ | 0 | — |
| created_at | timestamp | ✗ | NULL | — |
| updated_at | timestamp | ✗ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (id)`

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

---

### `policy_role_assignments`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| policy | varchar(255) | ✓ | NULL | — |
| role_id | varchar(255) | ✓ | NULL | — |
| created_at | timestamp | ✗ | NULL | — |
| updated_at | timestamp | ✗ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (policy, role_id)`
- `INDEX policy_role_assignments_role_id_foreign (role_id)`

- `FOREIGN KEY policy_role_assignments_policy_foreign (policy) REFERENCES policy_roles(policy) ON UPDATE NO ACTION ON DELETE CASCADE`
- `FOREIGN KEY policy_role_assignments_role_id_foreign (role_id) REFERENCES roles(id) ON UPDATE NO ACTION ON DELETE CASCADE`

---

### `integration_connectors`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | varchar(26) | ✗ | NULL | — |
| key | varchar(64) | ✗ | NULL | — |
| name | varchar(120) | ✗ | NULL | — |
| kind | varchar(60) | ✗ | NULL | — |
| enabled | tinyint(1) | ✓ | 0 | — |
| config | text | ✗ | NULL | — |
| meta | json | ✓ | NULL | — |
| last_health_at | timestamp | ✓ | NULL | — |
| created_at | timestamp | ✓ | NULL | — |
| updated_at | timestamp | ✓ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `UNIQUE INDEX integration_connectors_key_unique (key)`

---

### `policy_roles`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| policy | varchar(255) | ✓ | NULL | — |
| label | varchar(255) | ✗ | NULL | — |
| created_at | timestamp | ✗ | NULL | — |
| updated_at | timestamp | ✗ | NULL | — |

**Indexes & Constraints**
- `PRIMARY KEY (policy)`

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

---

### `ui_settings`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| key | varchar(255) | ✓ | NULL | — |
| value | text | ✓ | NULL | — |
| type | varchar(16) | ✓ | NULL | — |
| updated_by | bigint unsigned | ✗ | NULL | — |
| created_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED |
| updated_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED on update CURRENT_TIMESTAMP |

**Indexes & Constraints**
- `PRIMARY KEY (key)`
- `INDEX ui_settings_updated_at_index (updated_at)`

---

### `ui_theme_pack_files`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| id | bigint unsigned | ✓ | NULL | auto_increment |
| pack_slug | varchar(255) | ✓ | NULL | — |
| path | varchar(255) | ✓ | NULL | — |
| mime | varchar(96) | ✓ | NULL | — |
| size_bytes | bigint unsigned | ✓ | NULL | — |
| sha256 | varchar(64) | ✓ | NULL | — |
| bytes | longblob | ✗ | NULL | — |
| created_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED |
| updated_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED on update CURRENT_TIMESTAMP |

**Indexes & Constraints**
- `PRIMARY KEY (id)`
- `INDEX ui_theme_pack_files_created_at_index (created_at)`
- `INDEX ui_theme_pack_files_pack_slug_index (pack_slug)`
- `UNIQUE INDEX ui_theme_pack_files_pack_slug_path_unique (pack_slug, path)`

- `FOREIGN KEY ui_theme_pack_files_pack_slug_foreign (pack_slug) REFERENCES ui_theme_packs(slug) ON UPDATE CASCADE ON DELETE CASCADE`

---

### `ui_theme_packs`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| slug | varchar(255) | ✓ | NULL | — |
| name | varchar(160) | ✓ | NULL | — |
| version | varchar(64) | ✗ | NULL | — |
| author | varchar(160) | ✗ | NULL | — |
| license_name | varchar(120) | ✗ | NULL | — |
| license_file | varchar(160) | ✗ | NULL | — |
| enabled | tinyint(1) | ✓ | 1 | — |
| imported_by | bigint unsigned | ✗ | NULL | — |
| imported_by_name | varchar(120) | ✗ | NULL | — |
| assets | json | ✗ | NULL | — |
| files | json | ✗ | NULL | — |
| inactive | json | ✗ | NULL | — |
| created_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED |
| updated_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED on update CURRENT_TIMESTAMP |

**Indexes & Constraints**
- `PRIMARY KEY (slug)`
- `INDEX ui_theme_packs_created_at_index (created_at)`
- `INDEX ui_theme_packs_enabled_index (enabled)`
- `INDEX ui_theme_packs_imported_by_foreign (imported_by)`

- `FOREIGN KEY ui_theme_packs_imported_by_foreign (imported_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL`

---

### `user_ui_prefs`

| Column | Type | Null | Default | Extra |
|-------:|------|------|---------|-------|
| user_id | bigint unsigned | ✓ | NULL | — |
| theme | varchar(64) | ✗ | NULL | — |
| mode | varchar(16) | ✗ | NULL | — |
| overrides | text | ✗ | NULL | — |
| sidebar_collapsed | tinyint(1) | ✓ | 0 | — |
| sidebar_pinned | tinyint(1) | ✓ | 1 | — |
| sidebar_width | int unsigned | ✓ | 280 | — |
| sidebar_order | text | ✗ | NULL | — |
| sidebar_hidden | text | ✗ | NULL | — |
| dashboard_layout | text | ✗ | NULL | — |
| created_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED |
| updated_at | datetime | ✓ | CURRENT_TIMESTAMP | DEFAULT_GENERATED on update CURRENT_TIMESTAMP |

**Indexes & Constraints**
- `PRIMARY KEY (user_id)`

- `FOREIGN KEY user_ui_prefs_user_id_foreign (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE`

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
