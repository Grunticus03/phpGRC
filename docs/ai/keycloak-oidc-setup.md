# Keycloak OIDC Setup for phpGRC

Use this checklist to connect Keycloak to phpGRC via OpenID Connect for both interactive logins and the built-in health check.

## Prerequisites
- Working Keycloak realm reachable from the phpGRC host.
- Administrative access to both phpGRC and Keycloak.
- Optional but recommended: HTTPS in front of Keycloak to avoid browser mixed-content blocks.

## Configure the Keycloak Client
1. In Keycloak select the target realm and open **Clients → Create**.
2. Fill in:
   - **Client Type**: OpenID Connect.
   - **Client ID**: `phpgrc` (or your preferred identifier).
   - **Client Name**: `phpGRC`
   - **Client autnentication**: On
   - **Standard Flow**: Enable.
   - **Root URL**: `https://<phpgrc-host>`
3. Settings>Access settings:
    - **Valid redirect URIs**: `https://<phpgrc-host>/auth/callback`
4. Save, then open the **Credentials** tab
    - If you do not see a Credentials tab, ensure that Settings>Capability config>Client authentication is toggled 'On'
    - **Client Authenticator**: `Client Id and Secret`
        - Copy the Client Secret value for use in phpGRC.
5. Enable **Service Account Roles** Settings>Capability Config
    - Toggle *Service Accounts Enabled*
    - This allows phpGRC’s health check to call `client_credentials`.

## Add the Provider in phpGRC
1. Sign in to phpGRC as an admin and navigate to **Admin → Identity Providers**.
2. Choose **Add Provider**, set **Provider Type** to `OIDC`.
3. Click **Fetch & Autofill** to pull metadata. If the browser blocks the request, see the CSP notes below.
4. Fill in the basics:
   - **Issuer**: Realm base URL, e.g. `http://<keycloak-host>/realms/corp`.
   - **Client ID / Secret**: values from Keycloak.
   - **Scopes**: `openid profile email`
5. Save, then use **Test Connection**. A green result confirms metadata + client credentials are configured.

## CSP and Mixed Content Considerations
- phpGRC sends a strict CSP header. To allow access to the OIDC metadata URL, add your Keycloak host to `connect-src`, for example:
  ```apache
  Header always set Content-Security-Policy \
    "default-src 'self'; connect-src 'self' http://keycloak.example.com; ..."
  ```
- If phpGRC and Keycloak mix HTTP/HTTPS, modern browsers flag metadata requests as mixed content. Either:
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
