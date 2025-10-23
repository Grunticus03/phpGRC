# Microsoft Entra ID (OIDC) Setup for phpGRC

The steps below capture everything we needed to get Microsoft Entra talking to phpGRC with a working `/auth/callback` flow **and** a green Admin → Identity Provider health check.

1. **Create / configure the app registration**
   - Azure Portal → Entra ID → *App registrations* → *New registration* (or select the existing one).
   - Supported account types can be “Single tenant” or broader — your choice.

2. **Authentication (platform) settings**
   - Under **Authentication**, add a **Web** platform with redirect URI:
     ```
     https://<phpgrc-host>/auth/callback
     ```
   - In the Web platform configuration, ensure **ID tokens (used for implicit and hybrid flows)** is checked.
   - Leave the SPA / mobile platforms disabled; phpGRC works as a confidential web client.

3. **Client secret**
   - Under **Certificates & secrets**, create a client secret.
   - Copy the value into phpGRC’s Identity Provider configuration (`client_secret`).

4. **Delegated Graph permissions** (interactive login scopes)
   - API permissions → **Add a permission** → *Microsoft Graph* → *Delegated*:
     - `openid`
     - `profile`
     - `email`
     - `offline_access`
   - Click **Grant admin consent**.

5. **Application Graph permission** (required for the phpGRC health check)
   - Same API permissions screen → *Add a permission* → *Microsoft Graph* → *Application*:
     - `User.Read.All`
   - Grant admin consent.
   - With this application permission in place, the health check’s client-credentials probe succeeds even if you do not list `.default` in phpGRC.

6. **phpGRC configuration**
   - In Admin → Identity Providers → Microsoft Entra ID (or your OIDC provider), set:
     - Issuer: `https://login.microsoftonline.com/<tenant-id>/v2.0`
     - Scopes: `openid profile email offline_access` (add `.default` only if you want phpGRC to use it; it isn’t required once step 5 is complete).
     - Client ID: application (client) ID from Entra.
     - Client secret: value created in step 3.

7. **Smoke test**
   - Click **Test connection** in phpGRC. You should now see:
     - Discovery document details populated.
     - Token probe status `ok`.
   - From the login page, click the Microsoft Entra button. The browser will redirect to Microsoft, return via `/auth/callback`, and the user lands in phpGRC.

That’s it. These settings give you a working Entra OIDC integration plus a passing health check without any hand-waving.
