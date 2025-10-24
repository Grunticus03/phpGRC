# Keycloak OIDC Setup for phpGRC

Use this checklist to connect Keycloak to phpGRC via OpenID Connect for both interactive logins and the built-in health check.

## Prerequisites
- phpGRC build where generic OIDC issuers can be HTTP (current mainline).
- Working Keycloak realm reachable from the phpGRC host.
- Administrative access to both phpGRC and Keycloak.
- Optional but recommended: HTTPS in front of Keycloak to avoid browser mixed-content blocks.

## Configure the Keycloak Client
1. In Keycloak select the target realm and open **Clients → Create**.
2. Fill in:
   - **Client ID**: `phpgrc` (or your preferred identifier).
   - **Client Type**: OpenID Connect.
   - **Access Type**: Confidential.
   - **Standard Flow Enabled**: On.
3. Add redirect URI(s): `https://<phpgrc-host>/auth/callback` (add the HTTP variant if phpGRC runs without TLS in dev).
4. Under **Web Origins** add the phpGRC origin (`https://<phpgrc-host>`).
5. Save, then open the **Credentials** tab and copy the generated client secret.
6. Enable **Service Accounts** (Settings → toggle *Service Accounts Enabled* → Save). This allows phpGRC’s health check to call `client_credentials`.
7. If ID tokens lack an e-mail claim, add a mapper (**Client → Mappers → Create**):
   - Mapper Type: *User Property*.
   - Property: `email`.
   - Token Claim Name: `email`.
   - Include in ID Token: On.
8. Optionally add mappers for display name (`displayName`), given name, and family name so phpGRC shows friendly names instead of usernames.
9. No extra scope tweaks are required; the default `openid profile email` scopes work for both interactive and health check flows.

## Add the Provider in phpGRC
1. Sign in to phpGRC as an admin and navigate to **Admin → Identity Providers**.
2. Choose **Add Provider**, set **Provider Type** to `OIDC`.
3. Fill in the basics:
   - **Issuer**: Realm base URL, e.g. `http://172.16.0.40:8081/realms/corp`.
   - **Client ID / Secret**: values from Keycloak.
   - **Scopes**: `openid profile email` (add `offline_access` only if you want refresh tokens).
   - **Redirect URIs**: leave blank to use the phpGRC default or list explicit callbacks.
4. Click **Fetch & Autofill** to pull metadata. If the browser blocks the request, see the CSP notes below.
5. Adjust JIT provisioning if needed (defaults create users automatically).
6. Save, then use **Test Connection**. A green result confirms metadata + client credentials are configured.

## CSP and Mixed Content Considerations
- phpGRC sends a strict CSP header. Add your Keycloak host to `connect-src`, for example:
  ```apache
  Header always set Content-Security-Policy \
    "default-src 'self'; connect-src 'self' http://172.16.0.40:8081 https://login.microsoftonline.com; ..."
  ```
- If phpGRC runs over HTTPS while Keycloak is plain HTTP, modern browsers flag metadata requests as mixed content. Either:
  - Terminate TLS in front of Keycloak, or
  - Run phpGRC over HTTP during testing, or
  - Temporarily allow mixed content (Edge: shield icon → *Load unsafe scripts* or `edge://settings/content/insecureContent`).

## Common Health Check Errors
- **`401 unauthorized_client`**: Keycloak denied the `client_credentials` probe. Ensure *Service Accounts Enabled* is on and the copied client secret matches.
- **`Issuer must be a valid URL`**: Double-check the Issuer field; it should be the base realm URL, not the full metadata path.
- **Missing e-mail claim**: Confirm users have e-mail addresses and that an `email` mapper is enabled. phpGRC falls back to `preferred_username`, but an address is still required for provisioning.
- **Display name shows `DOMAIN\user`**: Add display/given/family name mappers. phpGRC strips the domain prefix when it falls back to `unique_name`.

## Verification steps
- Run **Test Connection** in phpGRC; expect “OIDC metadata and client credentials validated.”
- Log out and start a login via the new provider. After authenticating in Keycloak, you should land in phpGRC with the account created or matched according to your JIT settings.
