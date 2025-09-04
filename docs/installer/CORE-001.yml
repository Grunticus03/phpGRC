core-001-planning:
  description: >
    Bootstrap Installer & first-run Setup Wizard.
    Only DB config lives on disk (/opt/phpgrc/shared/config.php); all other settings in DB.
    Phase 1 deliverable = planning + stubs scaffold (no implementation yet).

  instruction-preamble:
    date: 2025-09-04
    phase: 1
    step: 1
    goal: Produce planning deliverables (no code).
    constraints:
      - full-file outputs only with header discriminator
      - scope limited to CORE-001 acceptance criteria
      - no implementation until later
    acceptance-criteria: Instruction Preamble drafted & aligned.

  db-config-file:
    path: /opt/phpgrc/shared/config.php
    ownership:
      dir: { perms: "0750", owner: deploy, group: www-data }
      file: { perms: "0640", owner: deploy, group: www-data }
    content:
      type: php-file
      format: "return [driver, host, port, database, username, password, charset, collation, options];"
    notes:
      - only DB config stored on disk
      - secrets never echoed back
      - backup & rotation handled via atomic writer

  wizard-step-list:
    - id: db_config
      route: /setup/db
      api: /api/setup/db/*
      purpose: Write DB config.php
    - id: app_key
      route: /setup/app-key
      api: /api/setup/app-key
      purpose: Generate & persist app key
    - id: schema_init
      route: /setup/schema
      api: /api/setup/schema/init
      purpose: Run migrations & baseline schema
    - id: admin_seed
      route: /setup/admin
      api: /api/setup/admin
      purpose: Create seeded admin + MFA seed
    - id: admin_mfa_verify
      route: /setup/admin/mfa
      api: /api/setup/admin/totp/verify
      purpose: Verify TOTP enrollment
    - id: smtp
      route: /setup/smtp
      api: /api/setup/smtp
      purpose: Save SMTP config & optional test
    - id: idp
      route: /setup/idp
      api: /api/setup/idp
      purpose: Save starter IdP config
    - id: branding
      route: /setup/branding
      api: /api/setup/branding
      purpose: Save theme/branding
    - id: finish
      route: /setup/finish
      api: /api/setup/finish
      purpose: Final validation & mark setup complete

  api-db-payload:
    fields:
      driver: { type: string, required: true, enum: [mysql] }
      host: { type: string, required: true, length: "1-255", noScheme: true }
      port: { type: integer, required: false, default: 3306, range: "1-65535" }
      database: { type: string, required: true, regex: "^[A-Za-z0-9_-]{1,64}$" }
      username: { type: string, required: true, length: "1-128" }
      password: { type: string, required: true, length: "1-256", spacesAllowed: true }
      charset: { type: string, required: false, default: utf8mb4 }
      collation: { type: string, required: false, default: utf8mb4_unicode_ci }
      options: { type: object, required: false }
    normalization:
      - lowercase: driver, charset, collation
      - trim: host, database, username
    validation:
      - no whitespace in host/database/username
      - reject invalid db names
      - reject unsupported driver

  db-test-contract:
    steps:
      - validate-payload
      - normalize-defaults
      - resolve-host (timeout 1.5s)
      - tcp-connect (timeout 3s)
      - authenticate (timeout 3s)
      - probe-select1 (timeout 3s)
    wall-clock-cap: 10s
    retry: none
    response:
      success: { ok: true, driver, host, port, roundTripMs }
      failure: { ok: false, error: { code, message, details }, roundTripMs }

  error-taxonomy:
    validation:
      - VALIDATION_FAILED
      - UNSUPPORTED_DRIVER
      - INVALID_HOST
      - INVALID_PORT
      - INVALID_DATABASE
      - MISSING_REQUIRED_FIELD
    network:
      - HOST_RESOLUTION_FAILED
      - DB_CONNECT_TIMEOUT
      - DB_CONNECT_REFUSED
      - TLS_NEGOTIATION_FAILED
    auth:
      - DB_AUTH_FAILED
      - DB_PROTOCOL_ERROR
    probe:
      - DB_PROBE_FAILED
      - DB_UNSUPPORTED_VERSION
      - DB_INSUFFICIENT_PRIVILEGES
    persistence:
      - CONFIG_WRITE_FAILED
      - CONFIG_INVALID_PATH
      - CONFIG_PERMISSIONS_WEAK
      - CONFIG_ATOMIC_RENAME_FAILED
    app-key-schema:
      - APP_KEY_GEN_FAILED
      - SCHEMA_INIT_FAILED
      - SCHEMA_ALREADY_INITIALIZED
    admin-mfa:
      - ADMIN_SEED_FAILED
      - TOTP_SEED_FAILED
      - ADMIN_NOT_FOUND
      - TOTP_CODE_INVALID
      - MFA_ALREADY_VERIFIED
    smtp-idp:
      - SMTP_CONFIG_INVALID
      - SMTP_TEST_FAILED
      - INVALID_PORT_FOR_MODE
      - AUTH_INCOMPLETE
      - IDP_CONFIG_INVALID
      - IDP_METADATA_FETCH_FAILED
    finish:
      - SETUP_NOT_READY
      - SETUP_ALREADY_COMPLETE
      - SETUP_FLAG_WRITE_FAILED
      - CONFIG_READ_FAILED
    catchall:
      - INTERNAL_ERROR
      - UNKNOWN_ERROR

  canonical-settings-keys:
    core.setup_complete: { type: bool, default: false }
    core.app.key: { type: string }
    core.smtp.host: { type: string }
    core.smtp.port: { type: int, default: 587 }
    core.smtp.secure: { type: enum, default: starttls }
    core.smtp.username: { type: string }
    core.smtp.password: { type: secret }
    core.smtp.from.email: { type: string }
    core.smtp.from.name: { type: string, default: phpGRC }
    core.idp.*: starter IdP config keys
    core.evidence.max_mb: { type: int, default: 25 }
    core.brand.theme: { type: enum, default: flatly }
    core.brand.name: { type: string, default: phpGRC }
    core.brand.logo_url: { type: string }
    core.admin.seeded: { type: bool, default: false }
    core.mfa.totp.required_for_admin: { type: bool, default: true }
    core.schema.version: { type: string }
    core.setup.last_step: { type: string }

  bootswatch-themes:
    allow-list:
      - cerulean
      - cosmo
      - cyborg
      - darkly
      - flatly
      - journal
      - litera
      - lumen
      - lux
      - materia
      - minty
      - pulse
      - sandstone
      - simplex
      - sketchy
      - slate
      - solar
      - spacelab
      - superhero
      - united
      - yeti

  atomic-write-protocol:
    steps:
      - write-temp-file in same dir (0600)
      - fflush + fsync
      - atomic rename to config.php
      - chmod/chown to 0640 deploy:www-data
      - fsync directory
    validation-after-write:
      - require file, parse keys, validate structure
    failure-handling:
      - abort + cleanup temp file
      - error codes: CONFIG_WRITE_FAILED, CONFIG_ATOMIC_RENAME_FAILED

  wizard-resume-mechanism:
    db-keys:
      core.setup.last_step: string
      core.setup.complete: bool
      core.admin.seeded: bool
      core.schema.version: string
    status-response:
      includes: setupComplete, lastStep, checks, nextStep
    step-rules:
      - update last_step only on success
      - idempotency guards for schema/admin
    ui:
      - call /api/setup/status on load
      - redirect to nextStep
    security:
      - never return secrets

  prerequisites-graph:
    db_config: []
    app_key: [db_config]
    schema_init: [db_config, app_key]
    admin_seed: [schema_init]
    admin_mfa_verify: [admin_seed]
    smtp: [schema_init]
    idp: [schema_init]
    branding: [schema_init]
    finish: [db_config, app_key, schema_init, admin_seed, admin_mfa_verify]

  smtp-payload:
    fields:
      host: { type: string, required: true }
      port: { type: int, default: 587 }
      secure: { enum: [none, starttls, tls], default: starttls }
      username: { type: string }
      password: { type: string, secret: true }
      fromEmail: { type: string, required: true }
      fromName: { type: string, default: phpGRC }
      timeoutSec: { type: int, default: 10 }
      testRecipient: { type: string, requiredIf: test=true }
      allowInvalidTLS: { type: bool, default: false }
      authMethod: { enum: [auto, login, plain, cram-md5], default: auto }

  admin-seed-payload:
    fields:
      email: { type: string, required: true }
      name: { type: string, required: true }
      password: { type: string, required: true, rules: "≥12 chars, 3 of 4 classes" }
      timezone: { type: string, optional }
      locale: { type: string, optional }
    response:
      totp: { issuer, account, secret, digits: 6, period: 30, algorithm: SHA1, otpauth: URI }

  admin-mfa-verification:
    endpoint: /api/setup/admin/totp/verify
    request: { email, code }
    preconditions: schema_init + admin_seed
    success: { ok: true }
    error-codes: ADMIN_NOT_FOUND, TOTP_CODE_INVALID, MFA_ALREADY_VERIFIED

  finish-step:
    endpoint: /api/setup/finish
    strict-prereqs: [db_config, app_key, schema_init, admin_seed, admin_mfa_verify]
    optional: [smtp, branding, idp]
    behavior:
      - validate config.php
      - recheck DB connectivity
      - confirm app.key, schema.version, admin seeded, MFA verified
    success:
      summary: { db, schema, admin, smtp, branding }
    errors: SETUP_NOT_READY, SETUP_ALREADY_COMPLETE, CONFIG_READ_FAILED, DB_CONNECT*, INTERNAL_ERROR

  ui-routes:
    /setup/db -> db_config
    /setup/app-key -> app_key
    /setup/schema -> schema_init
    /setup/admin -> admin_seed
    /setup/admin/mfa -> admin_mfa_verify
    /setup/smtp -> smtp
    /setup/idp -> idp
    /setup/branding -> branding
    /setup/finish -> finish
    /setup -> redirect to nextStep via /api/setup/status

  minimal-file-stubs:
    scripts:
      - /scripts/install/bootstrap.sh
    api:
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
    web:
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
    docs:
      - /docs/installer/README.md
      - /docs/installer/CORE-001.md

  file-header-template:
    example: |
      # @phpgrc:/api/app/Http/Controllers/Setup/DbController.php
      # Purpose: Handle `/api/setup/db/*` endpoints for testing and writing DB config
