# API Error Envelope

Common error shape:
```json
{ "ok": false, "code": "<UPPER_SNAKE_CODE>", "errors": { "...optional details..." } }
```

## Codes in Phase 4
- `VALIDATION_FAILED`  
  - Context: Admin Settings payload or Evidence upload validation.  
  - Shape: `errors` is a map of field → array of messages.
- `EVIDENCE_NOT_ENABLED`  
  - Context: POST `/api/evidence` when `core.evidence.enabled=false`.
- `EVIDENCE_NOT_FOUND`  
  - Context: GET/HEAD `/api/evidence/{id}` when record missing.

## Conventions
- 4xx for client issues, 5xx for server faults.
- Boolean `ok` is always present.
- Additional diagnostic keys may be included on success responses (e.g., `note: "stub-only"`).
