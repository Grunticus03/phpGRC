# @phpgrc:/docs/modules/module-schema.md
# Module Manifest Schema — `module.json`

## Purpose
Canonical schema for phpGRC module manifests. Enables validation, safe hot enable/disable, and capability discovery.

## File location
Each module root must contain a `module.json`.

## Fields
| Field | Type | Req | Rules |
|---|---|---|---|
| `name` | string | ✓ | lowercase kebab-case, `^[a-z][a-z0-9-]{2,48}$` |
| `displayName` | string |  | 1–80 chars |
| `version` | string | ✓ | SemVer, `^\\d+\\.\\d+\\.\\d+(-[0-9A-Za-z.-]+)?(\\+[0-9A-Za-z.-]+)?$` |
| `description` | string |  | ≤ 280 chars |
| `requires` | string[] |  | module names, unique |
| `capabilities` | string[] |  | namespaced, `^[a-z][a-z0-9_.-]+$`, unique |
| `migrations` | string[] |  | relative file paths, unique |
| `settings.defaults` | object |  | key→default value map |
| `ui.nav` | object[] |  | nav stubs only; no logic |
| `ui.nav[].label` | string | ✓ | 1–40 chars |
| `ui.nav[].path` | string | ✓ | route path starting with `/` |
| `ui.nav[].order` | integer |  | sort hint |
| `openapi` | string |  | relative path to OpenAPI fragment (`.yaml|.yml|.json`) |
| `serviceProvider` | string |  | FQCN to module ServiceProvider |
| `health.endpoint` | string |  | optional health check path |
| `enabled` | boolean |  | default `true` |

All unspecified fields are rejected (`additionalProperties: false`).

## Example
```
{
  "name": "risks",
  "displayName": "Risks",
  "version": "0.1.0",
  "description": "Risk register and scoring scaffolds.",
  "requires": ["compliance"],
  "capabilities": ["risks.read", "risks.write", "risks.scoring"],
  "migrations": [
    "database/migrations/0000_00_00_000100_create_risks_table.php"
  ],
  "settings": {
    "defaults": {
      "risks.scoring.method": "qualitative"
    }
  },
  "ui": {
    "nav": [
      { "label": "Risks", "path": "/risks", "order": 20 }
    ]
  },
  "openapi": "openapi.yaml",
  "serviceProvider": "Modules\\Risks\\RisksServiceProvider",
  "health": { "endpoint": "/api/risks/health" },
  "enabled": true
}
```