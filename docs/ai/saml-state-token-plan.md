# SAML RelayState Token Implementation Plan

## 1. Token Design & Configuration
- [x] Define token structure (JWT or equivalent) with required claims: `request_id`, `provider_id`, `provider_key`, `intended_path`, `issued_at`, optional `ip_hash`, `user_agent_hash`, `version`.
- [x] Update configuration (`config/core.php`) to add `core.auth.saml.state_secret`, `state_secret_previous`, `state_ttl_seconds`, `state_skew_seconds`, `state_enforce_client_hash`.
- [x] Document new environment variables in the admin docs (no `.env` file required). Note that the secrets must be injected via real process environment (e.g., PHP-FPM `env[]`, Apache `SetEnv`, systemd unit override) because the project intentionally avoids `.env` files.
- [x] Provide guidance for generating and rotating secrets: maintain a primary key (`state_secret`) and optional secondary key (`state_secret_previous`) for smooth rotation.
- [x] Decide on signing algorithm (e.g., HMAC-SHA256 with 32-byte random secret).

### Token Claim Sketch
- `iss` (issuer) — identify phpGRC (e.g., `phpgrc.saml.state`).
- `aud` (audience) — identifies the SP entity or application URL for sanity.
- `iat` (issued at) — UNIX timestamp, used with TTL.
- `exp` (optional) — explicit expiry to tighten verification window.
- Custom claims:
  - `rid` — SAML AuthnRequest ID.
  - `pid` — provider ULID.
  - `pkey` — provider key slug.
  - `dest` — intended path (optional, sanitized path-only value).
  - `ip` / `ua` — hashed fingerprint (optional; derive via HMAC with secret pepper).
  - `ver` — token schema version (start at `1`).

Signing algorithm: HMAC-SHA256 using `core.auth.saml.state_secret` (32-byte base64). Verification accepts current secret first, then `state_secret_previous` if present.

## 2. State Token Builder Service
- [x] Create service class (e.g., `App\Services\Auth\SamlStateTokenFactory`) responsible for token creation and validation.
- [x] Implement token creation: gather payload, sign with current secret, base64url encode.
- [x] Implement validation: signature verification (primary + rotated keys), TTL enforcement, optional IP/UA hash checks.
- [x] Expose methods: `issueToken(IdpProvider $provider, string $requestId, ?string $intendedPath, Request $request)` and `validateToken(string $token, Request $request): SamlStateDescriptor` (custom value object).
- [x] Add unit tests covering creation, validation, expiry, invalid signatures, hash mismatch.

## 3. Redirect Controller Integration
- [x] Update `SamlRedirectController` to use token factory instead of cache-based `SamlStateStore`.
- [x] Remove or repurpose `SamlStateStore`; either delete or convert into replay cache helper.
- [x] Ensure `RelayState` now carries the signed token (URL-safe string).
- [x] Maintain short-lived replay cache keyed by request_id (e.g., Redis) to block reuse; populate when issuing token.
- [x] Update redirect JSON response to include token metadata for API clients (embed token + context payload).

## 4. ACS Controller Integration
- [x] Update `SamlAssertionConsumerController` to validate token using factory; remove dependence on pulled cache payload.
- [x] On successful validation, record request_id as consumed in replay cache.
- [x] Handle validation failures with precise logging and user messages.
- [x] Ensure `request_id` from token matches SAML `InResponseTo` (existing check continues via authenticator payload).
- [x] Cleanup old instrumentation (debug helper) if no longer required.

## 5. Health Check & Driver Updates
- [x] Update `SamlIdpDriver::performRemoteHealthCheck` to generate RelayState token via new factory.
- [x] Ensure health check validates token TTL/sig correctly.
- [x] Update associated tests (`IdpProviderApiTest` etc.) for new behavior.

## 6. Test Suite Enhancements
- [x] Update feature test `SamlLoginTest` to expect RelayState token (decode & assert claims).
- [x] Add tests for replay rejection (posting same assertion twice).
- [x] Add integration test for health preview (`preview_health_for_saml`) to assert token format.
- [x] Ensure CI passes full suite (PHPUnit, PHPStan, Psalm, PHPMD, Pint).

## 7. Documentation & Communication
- [x] Update docs under `docs/ai` and admin guides describing new RelayState mechanism and required secrets.
- [x] Document operational steps: generating secrets, rotating keys, monitoring logs.
- [x] Update deployment checklist to include setting `core.auth.saml.state_secret` before rollout.

## 8. Deployment Strategy
- [x] Retire backward compatibility fallback: legacy cache-based state removed now that health check issues signed tokens.
- [x] Build and run targeted smoke tests after deployment (SAML login, health check).
- [x] saml-debug instrumentation removed after verifying behaviour; no dedicated log file remaining.
