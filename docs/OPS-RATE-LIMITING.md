# OPS — API Rate Limiting and Throttling

Status: Proposed  
Owners: API + Ops  
Applies to: Auth endpoints, metrics endpoints, and any burst-prone route

---

## 1) Goals
- Protect availability under brute force and bursts.
- Return correct HTTP semantics and headers.
- Stay testable and deterministic.

---

## 2) HTTP behavior

### Status codes
- `429 Too Many Requests` when a quota is exceeded.
- `401 Unauthorized` for unauthenticated access when `core.rbac.require_auth=true`.
- `403 Forbidden` for RBAC/capability denies. Do **not** use 403 for rate limits.

### Required headers on 429
- `Retry-After: <seconds>` — whole seconds until next allowed attempt.
- Optional standards-aligned fields (enable when clients are ready):
  - `RateLimit:` and `RateLimit-Policy:` (draft standard).
- Optional legacy fallback for clients expecting them (disable by default):
  - `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.

### Error body (json)
```json
{ "ok": false, "code": "RATE_LIMITED", "retry_after_seconds": 60 }
```

---

## 3) Partition keys (choose per route)
- `ip` — good default for public/auth.
- `session` — preferred when a session cookie exists.
- `user_id` — only after auth success.
- Composite keys (e.g., `ip+route`) are acceptable for hot endpoints.

---

## 4) Knobs and defaults

### 4.1 Auth brute-force limiter (login)
- `core.auth.bruteforce.enabled`: `true`
- `core.auth.bruteforce.strategy`: `session` (alt: `ip`)
- `core.auth.bruteforce.window_seconds`: `900`
- `core.auth.bruteforce.max_attempts`: `5`
- `core.auth.bruteforce.lock_seconds`: `900`
- On first attempt in `session` mode, issue the session cookie.
- On lock:
  - Return `429` with `Retry-After: <lock_seconds>`.
  - Emit audit `auth.bruteforce.locked` once per lock window.

### 4.2 Metrics throttling (read-only KPIs)
- `core.metrics.throttle.enabled`: `true` (prod), `false` (tests)
- `core.metrics.throttle.window_seconds`: `60`
- `core.metrics.throttle.max_requests`: `120`  (≈2 RPS windowed; tune per env)
- `core.metrics.cache_ttl_seconds`: `30` (range `0..3600`; `0` disables)
- Behavior: throttle first, then serve from cache if present.

### 4.3 General API burst control (optional global bucket)
- `core.api.throttle.enabled`: `false` (default)
- If enabled, use token bucket per `ip` with:
  - `core.api.throttle.rate_per_second`: `10`
  - `core.api.throttle.burst`: `50`

> Note: If these `core.api.throttle.*` keys don’t exist yet, treat them as proposed. Add only when implementing.

---

## 5) Auditing
- Failed login attempts: `auth.login.failed` (include partition + counter in `meta`).
- Lock event: `auth.bruteforce.locked` with `meta:{strategy, window_seconds, max_attempts}`.
- RBAC denies remain `rbac.deny.*` and are independent of rate limits.
- Exactly one audit row per denied request.

---

## 6) Testing matrix

### Unit
- Partitioning: `ip` vs `session` counting.
- Window roll-over and counter reset.
- `Retry-After` formatting as integer seconds.

### Feature
- Login: 5 failures → 429 on 6th with correct headers + body.
- Lock expires after `lock_seconds` → next attempt allowed.
- Metrics: exceed window → 429; with cache enabled, warm cache then throttle.
- RBAC: ensure 403 takes precedence only when limiter didn’t fire (one outcome per request).

### Headers/assertions
- `Retry-After` present on every 429.
- No charset on CSV or YAML responses affected by this feature.
- Optional `RateLimit*` headers appear only when enabled.

---

## 7) Deployment notes
- Ensure trusted proxy config so limiter sees client IP (X-Forwarded-For).
- Keep limiter state in fast storage (in-memory or Redis). Avoid DB.
- Exempt health checks and internal service accounts via allowlist.

---

## 8) Tuning guidance
- Start conservative. Observe p95/p99 latency and 429 rates.
- Increase `max_requests` or `burst` only when 429s are legitimate traffic.
- Keep auth lock window ≥ 15 minutes unless SSO dictates otherwise.

---

## 9) Compatibility and docs
- Public OpenAPI stays additive; document `429` responses where rate limits apply.
- Internal docs may include `x-internal: true` operations; rate limits still enforced at runtime.
