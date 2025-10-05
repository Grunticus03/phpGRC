/// FILE: /docs/phase-5/CAPABILITY-GATES.md
# Phase 5 — Capability Gates

## Preamble
- Date: 2025-09-26
- Scope: Internal. Explains feature switches enforced alongside RBAC policies.

## Capabilities
- `core.capabilities.core.audit.export`  
  Controls `GET /audit/export.csv`. Deny path returns `403` with `code="CAPABILITY_DISABLED"`.
- `core.capabilities.core.evidence.upload`  
  Controls `POST /evidence`. Deny path returns `403` with `code="CAPABILITY_DISABLED"`.

## Interaction with RBAC
- Both endpoints still require their RBAC policies:
  - `core.audit.export` for `/audit/export.csv`
  - `core.evidence.manage` for `POST /evidence`
- Effective allow = RBAC allow **and** capability enabled.

## Defaults (config → DB overrides take precedence)
```json
{
  "core": {
    "capabilities": {
      "core": {
        "audit": { "export": true },
        "evidence": { "upload": true }
      }
    }
  }
}
```

## Examples
```bash
# Deny export when capability off
curl -H "Authorization: Bearer <token>" \
  "https://<host>/api/audit/export.csv"
# -> 403 {"ok":false,"code":"CAPABILITY_DISABLED",...}

# Deny upload when capability off
curl -H "Authorization: Bearer <token>" -F "file=@doc.txt" \
  "https://<host>/api/evidence"
# -> 403 {"ok":false,"code":"CAPABILITY_DISABLED",...}
```

## Tests
- See `api/tests/Feature/Capabilities/CapabilityGatesTest.php`.
