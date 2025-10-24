# SAML State Token Secrets

The SAML RelayState token is signed with an application secret that must be supplied via the **process environment**, not via a checked-in `.env` file.

## Environment Variables

| Variable | Purpose |
| --- | --- |
| `CORE_AUTH_SAML_STATE_SECRET` | Primary HMAC key (32 bytes, base64 or hex). |
| `CORE_AUTH_SAML_STATE_PREVIOUS_SECRET` | Optional secondary key used during rotations. |
| `CORE_AUTH_SAML_STATE_TTL_SECONDS` | (Optional) Token lifetime, defaults to 300 seconds. |
| `CORE_AUTH_SAML_STATE_SKEW_SECONDS` | (Optional) Allowed clock skew, defaults to 30 seconds. |
| `CORE_AUTH_SAML_STATE_ENFORCE_CLIENT_HASH` | (Optional) Whether to enforce IP/UA fingerprint matching (default `true`). |

## Supplying Secrets Without `.env`

Inject the variables directly into the PHP-FPM/Apache environment. Examples:

### PHP-FPM pool configuration
```
; /etc/php/8.3/fpm/pool.d/phpgrc.conf
env[CORE_AUTH_SAML_STATE_SECRET] = "base64:xxxxxxxxxxxxxxxxxxxx"
env[CORE_AUTH_SAML_STATE_PREVIOUS_SECRET] = "base64:yyyyyyyyyyyyyyyy"
```
Reload PHP-FPM after editing: `sudo systemctl reload php8.3-fpm`.

### Apache (if using mod_php)
```
SetEnv CORE_AUTH_SAML_STATE_SECRET base64:xxxxxxxxxxxxxxxxxxxx
SetEnv CORE_AUTH_SAML_STATE_PREVIOUS_SECRET base64:yyyyyyyyyyyyyyyy
```
Restart Apache to apply changes.

## Generating Secrets

Use a cryptographically secure generator, e.g.:
```
openssl rand -base64 32
```
Store the key securely (password manager/secrets vault). Do not commit it anywhere in git.

## Rotation Procedure

1. Generate a new secret.
2. Move the current secret into `CORE_AUTH_SAML_STATE_PREVIOUS_SECRET`.
3. Place the new secret in `CORE_AUTH_SAML_STATE_SECRET`.
4. Reload PHP-FPM.
5. After the token TTL window (default 5 minutes) has passed, clear `CORE_AUTH_SAML_STATE_PREVIOUS_SECRET`.

This allows both old and new tokens to validate during the rotation window.

## Health Check Integration

- Remote health previews and scheduled health checks now mint the same signed RelayState tokens as interactive logins. If the IdP rejects the token, inspect the decoded payload for `rid`/`pid` details.
- The legacy cache-based `SamlStateStore` fallback is retired; all RelayState handling requires the configured secrets above.
- Temporary `saml-debug.log` instrumentation has been removed. Operational logging continues via structured app logs (`logger()->info|warning`).
