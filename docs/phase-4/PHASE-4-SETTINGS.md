# docs/SETTINGS.md
# Core Settings — Phase 4 Snapshot

> Purpose: document **Phase-4** `core.*` keys and defaults used by API and CI. UI/Theming keys are Phase-5.5 and are intentionally excluded here.

## 1) Precedence and Runtime

- **Runtime source of truth:** `config()`  
- **Precedence:** base app config → `/opt/phpgrc/shared/config.php` overlay → test/runtime injection  
- **Note:** `.env` is read only at bootstrap; production should use cached config.

## 2) RBAC (`core.rbac.*`)

| Key | Type | Default | Notes |
|---|---|---:|---|
| `core.rbac.enabled` | bool | `true` | When `false`, `/api/rbac/*` returns 404 and other routes skip role/policy gates. |
| `core.rbac.require_auth` | bool | `false` | When `true`, unauthenticated requests get 401. |
| `core.rbac.mode` | enum(`stub`,`persist`) | `stub` | In `stub`, policy allows; in `persist`, policy enforces and unknown keys deny. |
| `core.rbac.persistence` | bool | `false` | Enables DB-backed roles/assignments even if `mode=stub`. |
| `core.rbac.roles` | string[] | `["Admin","Auditor","Risk Manager","User"]` | Catalog when DB roles are absent. |
| `core.rbac.policies` | map<string,list<string>> | `{}` | Overrides replace defaults; names normalized; empty list denies in `persist`. |

**Default PolicyMap (effective base):**
```json
{
  "core.settings.manage":  ["Admin"],
  "core.audit.view":       ["Admin","Auditor"],
  "core.evidence.view":    ["Admin","Auditor"],
  "core.evidence.manage":  ["Admin"],
  "core.exports.generate": ["Admin"],
  "rbac.roles.manage":     ["Admin"],
  "rbac.user_roles.manage":["Admin"]
}
```

Normalization of role names: trim → collapse spaces → replace spaces with `_` → lowercase. Case-insensitive match to catalog or DB roles.

## 3) Audit (`core.audit.*`)

| Key | Type | Default | Notes |
|---|---|---:|---|
| `core.audit.enabled` | bool | `true` | Disables audit sinks and scheduler when `false`. |
| `core.audit.persistence` | bool | `true` | Persist events to DB. |
| `core.audit.retention_days` | int (30..730) | `365` | Used by `audit:purge`; **clamped** on execution. |
| `core.audit.csv_use_cursor` | bool | `true` | Stream CSV with cursor iterator for low memory use. |

**Scheduler:** when `core.audit.enabled=true`, the Kernel registers  
`audit:purge --days=<clamped 30..730> --emit-summary` **daily at 03:10 UTC**.  
Omitted when disabled. Locks are disabled in `testing`.

## 4) Capabilities (`core.capabilities.*`)

Boolean feature gates checked by middleware or controllers.

| Key | Type | Default | Notes |
|---|---|---:|---|
| `core.capabilities.core.exports.generate` | bool | `true` | Required to create exports. |

> Deny on capability returns 403 with code `CAPABILITY_DISABLED`.

## 5) Evidence (`core.evidence.*`)

| Key | Type | Default | Notes |
|---|---|---:|---|
| `core.evidence.enabled` | bool | `true` | Gates persistence and access paths. |
| `core.evidence.max_mb` | int | `25` | **Not enforced in Phase-4.** |
| `core.evidence.allowed_mime` | string[] | `["application/pdf","image/png","image/jpeg","text/plain"]` | **Not enforced in Phase-4.** |

**Phase-4 contract:** **file-only; no MIME or max-size validation.**  
**API invariant:** responses expose **`size`** only. No `size_bytes`.

## 6) Exports (`core.exports.*`)

| Key | Type | Default | Notes |
|---|---|---:|---|
| `core.exports.enabled` | bool | `true` | Enables persisted export jobs and artifact downloads when tables exist. |
| `core.exports.disk` | string | `local` | Storage disk for artifacts. |
| `core.exports.dir` | string | `exports` | Directory on the disk. |

## 7) Avatars (`core.avatars.*`)

| Key | Type | Default | Notes |
|---|---|---:|---|
| `core.avatars.enabled` | bool | `true` | Enables avatar upload. |
| `core.avatars.size_px` | int | `128` | Canonical pixel size. |
| `core.avatars.format` | string | `webp` | Phase-4 validation allows **WEBP only**. |
| `core.avatars.max_kb` | int | `1024` | 1 MB default cap. |

## 8) Setup (`core.setup.*`)

| Key | Type | Default | Notes |
|---|---|---:|---|
| `core.setup.enabled` | bool | `true` | Enables setup endpoints. |
| `core.setup.shared_config_path` | string | `/opt/phpgrc/shared/config.php` | Path for DB config overlay. |
| `core.setup.allow_commands` | bool | `false` | When false, command endpoints return stub 202. |

## 9) Overlay example (`/opt/phpgrc/shared/config.php`)

```php
<?php
return [
  'core' => [
    'rbac' => [
      'enabled' => true,
      'require_auth' => false,
      'mode' => 'stub',
      'persistence' => false,
      'policies' => [
        // example override: allow Risk Manager to generate exports
        'core.exports.generate' => ['Admin','Risk Manager'],
      ],
      'roles' => ['Admin','Auditor','Risk Manager','User'],
    ],
    'audit' => [
      'enabled' => true,
      'persistence' => true,
      'retention_days' => 365,
      'csv_use_cursor' => true,
    ],
    'capabilities' => [
      'core' => [
        'exports' => ['generate' => true],
      ],
    ],
    'evidence' => [
      'enabled' => true,
      // Phase-4: validation keys accepted but not enforced
      'max_mb' => 25,
      'allowed_mime' => ['application/pdf','image/png','image/jpeg','text/plain'],
    ],
    'exports' => [
      'enabled' => true,
      'disk' => 'local',
      'dir'  => 'exports',
    ],
    'avatars' => [
      'enabled' => true,
      'size_px' => 128,
      'format' => 'webp',
      'max_kb' => 1024,
    ],
    'setup' => [
      'enabled' => true,
      'shared_config_path' => '/opt/phpgrc/shared/config.php',
      'allow_commands' => false,
    ],
  ],
];
```

## 10) CI Notes

- OpenAPI lint and **diff guard** against 0.4.6. Evidence responses must not include `size_bytes`.
- **Schema drift guard** compares live DB schema to `docs/db/SCHEMA.md`.
- Static analysis: PHPStan Level 9, Psalm clean.
