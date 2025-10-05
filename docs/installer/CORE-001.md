# core-001-planning

## Description
Bootstrap Installer & first-run Setup Wizard.  
Only DB config lives on disk (`/opt/phpgrc/shared/config.php`); all other settings in DB.  
Phase 1 deliverable = planning + stubs scaffold (no implementation yet).

---

## Instruction Preamble
- **Date:** 2025-09-04  
- **Phase:** 1  
- **Step:** 1  
- **Goal:** Produce planning deliverables (no code).  
- **Constraints:**
  - full-file outputs only with header discriminator
  - scope limited to CORE-001 acceptance criteria
  - no implementation until later
- **Acceptance Criteria:** Instruction Preamble drafted & aligned.

---

## DB Config File
- **Path:** `/opt/phpgrc/shared/config.php`
- **Ownership:**
  - Dir: perms `0750`, owner `deploy`, group `www-data`
  - File: perms `0640`, owner `deploy`, group `www-data`
- **Content:**
  - Type: php-file
  - Format:  
    ```php
    return [driver, host, port, database, username, password, charset, collation, options];
    ```
- **Notes:**
  - only DB config stored on disk
  - secrets never echoed back
  - backup & rotation handled via atomic writer

---

## Wizard Step List
| ID               | Route              | API                           | Purpose                               |
|------------------|--------------------|-------------------------------|---------------------------------------|
| db_config        | /setup/db          | /api/setup/db/*               | Write DB config.php                   |
| app_key          | /setup/app-key     | /api/setup/app-key            | Generate & persist app key             |
| schema_init      | /setup/schema      | /api/setup/schema/init        | Run migrations & baseline schema       |
| admin_seed       | /setup/admin       | /api/setup/admin              | Create seeded admin + MFA seed         |
| admin_mfa_verify | /setup/admin/mfa   | /api/setup/admin/totp/verify  | Verify TOTP enrollment                 |
| smtp             | /setup/smtp        | /api/setup/smtp               | Save SMTP config & optional test       |
| idp              | /setup/idp         | /api/setup/idp                | Save starter IdP config                |
| branding         | /setup/branding    | /api/setup/branding           | Save theme/branding                    |
| finish           | /setup/finish      | /api/setup/finish             | Final validation & mark setup complete |

---

## API DB Payload
- **Fields:**
  - driver: string, required, enum [mysql]
  - host: string, required, length 1–255, noScheme
  - port: integer, default 3306, range 1–65535
  - database: string, regex `^[A-Za-z0-9_-]{1,64}$`
  - username: string, length 1–128
  - password: string, length 1–256, spaces allowed
  - charset: string, default utf8mb4
  - collation: string, default utf8mb4_unicode_ci
  - options: object, optional
- **Normalization:**
  - lowercase: driver, charset, collation
  - trim: host, database, username
- **Validation:**
  - no whitespace in host/database/username
  - reject invalid db names
  - reject unsupported driver

---

## DB Test Contract
- **Steps:**
  1. validate-payload
  2. normalize-defaults
  3. resolve-host (timeout 1.5s)
  4. tcp-connect (timeout 3s)
  5. authenticate (timeout 3s)
  6. probe-select1 (timeout 3s)
- **Wall-Clock Cap:** 10s  
- **Retry:** none  
- **Response:**
  - Success: `{ ok: true, driver, host, port, roundTripMs }`
  - Failure: `{ ok: false, error: { code, message, details }, roundTripMs }`

---

## Error Taxonomy
- **Validation:** VALIDATION_FAILED, UNSUPPORTED_DRIVER, INVALID_HOST, INVALID_PORT, INVALID_DATABASE, MISSING_REQUIRED_FIELD  
- **Network:** HOST_RESOLUTION_FAILED, DB_CONNECT_TIMEOUT, DB_CONNECT_REFUSED, TLS_NEGOTIATION_FAILED  
- **Auth:** DB_AUTH_FAILED, DB_PROTOCOL_ERROR  
- **Probe:** DB_PROBE_FAILED, DB_UNSUPPORTED_VERSION, DB_INSUFFICIENT_PRIVILEGES  
- **Persistence:** CONFIG_WRITE_FAILED, CONFIG_INVALID_PATH, CONFIG_PERMISSIONS_WEAK, CONFIG_ATOMIC_RENAME_FAILED  
- **App-Key-Schema:** APP_KEY_GEN_FAILED, SCHEMA_INIT_FAILED, SCHEMA_ALREADY_INITIALIZED  
- **Admin-MFA:** ADMIN_SEED_FAILED, TOTP_SEED_FAILED, ADMIN_NOT_FOUND, TOTP_CODE_INVALID, MFA_ALREADY_VERIFIED  
- **SMTP/IdP:** SMTP_CONFIG_INVALID, SMTP_TEST_FAILED, INVALID_PORT_FOR_MODE, AUTH_INCOMPLETE, IDP_CONFIG_INVALID, IDP_METADATA_FETCH_FAILED  
- **Finish:** SETUP_NOT_READY, SETUP_ALREADY_COMPLETE, SETUP_FLAG_WRITE_FAILED, CONFIG_READ_FAILED  
- **Catchall:** INTERNAL_ERROR, UNKNOWN_ERROR  

---

## Canonical Settings Keys
- `core.setup_complete`: bool, default false  
- `core.app.key`: string  
- `core.smtp.host`: string  
- `core.smtp.port`: int, default 587  
- `core.smtp.secure`: enum, default starttls  
- `core.smtp.username`: string  
- `core.smtp.password`: secret  
- `core.smtp.from.email`: string  
- `core.smtp.from.name`: string, default phpGRC  
- `core.idp.*`: starter IdP config keys  
- `core.evidence.max_mb`: int, default 25  
- `core.brand.theme`: enum, default flatly  
- `core.brand.name`: string, default phpGRC  
- `core.brand.logo_url`: string  
- `core.admin.seeded`: bool, default false  
- `core.mfa.totp.required_for_admin`: bool, default true  
- `core.schema.version`: string  
- `core.setup.last_step`: string  

---

## Bootswatch Themes
Allowed list: cerulean, cosmo, cyborg, darkly, flatly, journal, litera, lumen, lux, materia, minty, pulse, sandstone, simplex, sketchy, slate, solar, spacelab, superhero, united, yeti.

---

## Atomic Write Protocol
- **Steps:**
  1. write-temp-file in same dir (0600)
  2. fflush + fsync
  3. atomic rename to config.php
  4. chmod/chown to 0640 deploy:www-data
  5. fsync directory
- **Validation after write:**
  - require file, parse keys, validate structure
- **Failure handling:**
  - abort + cleanup temp file
  - error codes: CONFIG_WRITE_FAILED, CONFIG_ATOMIC_RENAME_FAILED

---

## Wizard Resume Mechanism
- **DB Keys:** core.setup.last_step, core.setup.complete, core.admin.seeded, core.schema.version  
- **Status Response:** includes setupComplete, lastStep, checks, nextStep  
- **Step Rules:** update last_step only on success, idempotency guards for schema/admin  
- **UI:** call `/api/setup/status` on load, redirect to nextStep  
- **Security:** never return secrets  

---

## Prerequisites Graph
- db_config: []  
- app_key: [db_config]  
- schema_init: [db_config, app_key]  
- admin_seed: [schema_init]  
- admin_mfa_verify: [admin_seed]  
- smtp: [schema_init]  
- idp: [schema_init]  
- branding: [schema_init]  
- finish: [db_config, app_key, schema_init, admin_seed, admin_mfa_verify]  

---

## SMTP Payload
- **Fields:** host, port=587, secure=[none, starttls, tls], username, password(secret), fromEmail, fromName=phpGRC, timeoutSec=10, testRecipient (conditional), allowInvalidTLS=false, authMethod=[auto, login, plain, cram-md5]

---

## Admin Seed Payload
- **Fields:** email, name, password (≥12 chars, 3 of 4 classes), timezone (opt), locale (opt)  
- **Response:** TOTP { issuer, account, secret, digits=6, period=30, algorithm=SHA1, otpauth URI }

---

## Admin MFA Verification
- **Endpoint:** `/api/setup/admin/totp/verify`  
- **Request:** { email, code }  
- **Preconditions:** schema_init + admin_seed  
- **Success:** `{ ok: true }`  
- **Errors:** ADMIN_NOT_FOUND, TOTP_CODE_INVALID, MFA_ALREADY_VERIFIED  

---

## Finish Step
- **Endpoint:** `/api/setup/finish`  
- **Strict Prereqs:** [db_config, app_key, schema_init, admin_seed, admin_mfa_verify]  
- **Optional:** [smtp, branding, idp]  
- **Behavior:** validate config.php, recheck DB, confirm app.key, schema.version, admin seeded, MFA verified  
- **Success:** summary { db, schema, admin, smtp, branding }  
- **Errors:** SETUP_NOT_READY, SETUP_ALREADY_COMPLETE, CONFIG_READ_FAILED, DB_CONNECT*, INTERNAL_ERROR  

---

## UI Routes
- /setup/db → db_config  
- /setup/app-key → app_key  
- /setup/schema → schema_init  
- /setup/admin → admin_seed  
- /setup/admin/mfa → admin_mfa_verify  
- /setup/smtp → smtp  
- /setup/idp → idp  
- /setup/branding → branding  
- /setup/finish → finish  
- /setup → redirect to nextStep via /api/setup/status  

---

## Minimal File Stubs
- **Scripts:**  
  - /scripts/install/bootstrap.sh  
- **API:**  
  - /api/app/Http/Controllers/Setup/SetupStatusController.php  
  - /api/app/Http/Controllers/Setup/DbController.php  
  - /api/app/Http/Controllers/Setup/AppKeyController.php  
  - /api/app/Http/Controllers/Setup/SchemaController.php  
  - /api/app/Http/Controllers/Setup/AdminController.php  
  - /api/app/Http/Controllers/Setup/AdminMfaController.php  
  - /api/app/Http/Controllers/Setup/SmtpController.php  
  - /api/app/Http/Controllers/Setup/IdpController.php  
  - /api/app/Http/Controllers/Setup/FinishController.php  
  - /api/app/Services/Setup/ConfigFileWriter.php  
  - /api/routes/api.php  
  - /api/app/Models/Setting.php  
  - /api/database/migrations/...create_core_settings_table.php  
  - /api/database/migrations/...create_users_and_auth_tables.php  
  - /api/app/Http/Middleware/SetupGuard.php  
- **Web:**  
  - /web/src/routes/setup/Db.tsx  
  - /web/src/routes/setup/AppKey.tsx  
  - /web/src/routes/setup/Schema.tsx  
  - /web/src/routes/setup/Admin.tsx  
  - /web/src/routes/setup/AdminMfa.tsx  
  - /web/src/routes/setup/Smtp.tsx  
  - /web/src/routes/setup/Idp.tsx  
  - /web/src/routes/setup/Branding.tsx  
  - /web/src/routes/setup/Finish.tsx  
  - /web/src/routes/setup/index.ts  
- **Docs:**  
  - /docs/installer/README.md  
  - /docs/installer/CORE-001.md  

---

## File Header Template
Example:
```text
# @phpgrc:/api/app/Http/Controllers/Setup/DbController.php
# Purpose: Handle `/api/setup/db/*` endpoints for testing and writing DB config
