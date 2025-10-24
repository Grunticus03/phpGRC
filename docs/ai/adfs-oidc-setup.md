# AD FS OIDC Setup for phpGRC

Use this guide to connect Active Directory Federation Services to phpGRC using OpenID Connect. Steps apply to AD FS 2022 or later.

## Prerequisites
- Administrative access to the AD FS farm (Management console + PowerShell).
- phpGRC build with AD FS-friendly claim fallbacks and non-HTTPS OIDC support.
- Ability to update phpGRC CSP headers and restart PHP-FPM/web server.
- Recommend serving both phpGRC and AD FS over HTTPS; otherwise be ready to permit mixed content in your browser.

## 1. Create the Application Group
1. AD FS Management → *Application Groups* → **Add Application Group** → *Server application accessing a web API*.
2. Name it (e.g., `phpGRC`). Copy the generated **Client Identifier** (GUID).
3. Add redirect URI `https://<phpgrc-host>/auth/callback` (include the http variant for dev if needed).
4. Finish the wizard and copy the shared secret.

AD FS creates:
- **phpGRC – Server application** (OIDC client handling ID tokens).
- **phpGRC – Web API** (resource used for the health check’s client credentials probe).

## 2. Grant Client → Web API Permissions
```powershell
$server = Get-AdfsServerApplication -Name 'phpGRC - Server application'
$api    = Get-AdfsWebApiApplication -ApplicationGroupIdentifier $server.ApplicationGroupIdentifier
Grant-AdfsApplicationPermission -ClientRoleIdentifier $server.Identifier `
  -ServerRoleIdentifier $api.Identifier[0] -ScopeNames @('openid','profile','email')
```
If you see `MSIS7626`, the permission already exists.

## 3. Register Claim Descriptions (Server 2022+)
```powershell
Add-AdfsClaimDescription -Name 'OIDC Email' -ClaimType 'email' -ShortName 'oidc_email' `
  -IsOffered $true -IsAccepted $true -IsRequired $false
Set-AdfsClaimDescription -TargetShortName oidc_email -IsPublishedInIdToken $true -IsPublishedInAccessToken $true
```

## 4. Map LDAP Attributes to OIDC Claims
AD FS Management → Application Groups → `phpGRC` → **phpGRC – Server application** → **Edit Issuance Transform Rules…**

Add rules in this order:
1. *Send LDAP Attributes as Claims*: `E-Mail-Addresses` → Outgoing type **E-Mail Address**.
2. *Send LDAP Attributes as Claims*: include `Display-Name`, `Given-Name`, `Surname` → their matching outgoing types.
3. *Transform Incoming Claim*: `E-Mail Address` → Outgoing type `email`.
4. *Transform Incoming Claim*: `Display-Name` → Outgoing type `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/displayname`.
5. (Optional) Map `Given Name`/`Surname` to `given_name`/`family_name` for friendly display names.

These rules ensure phpGRC receives `email`, `displayname`, and (optionally) given/family name claims in the ID token.

## 5. Configure phpGRC
1. phpGRC → **Admin → Identity Providers → Add** → Driver `OIDC`.
2. Issuer: `https://<adfs-host>/adfs` (no trailing metadata segment).
3. Client ID & Secret: values copied from AD FS.
4. Scopes: `openid profile email` (add `offline_access` if needed).
5. Redirect URIs: leave default or specify `https://<phpgrc-host>/auth/callback`.
6. Click **Fetch & Autofill** (ensure the CSP allows the AD FS host) and save.

## 6. Update CSP / Handle Mixed Content
- Allow the AD FS host in phpGRC’s `connect-src`, e.g.:
  ```apache
  Header always set Content-Security-Policy \
    "default-src 'self'; connect-src 'self' https://adfs.example.com https://login.microsoftonline.com; …"
  ```
- If AD FS is HTTP-only while phpGRC is HTTPS, convert AD FS to HTTPS or temporarily allow mixed content (Edge: page shield → *Load unsafe scripts*, or `edge://settings/content/insecureContent`).

## 7. Validate
- **Test Connection** in phpGRC. Expect “OIDC metadata and client credentials validated.” If you see `401 unauthorized_client`, re-check the secret and `Grant-AdfsApplicationPermission` scopes.
- Perform an interactive login to ensure claims are mapped correctly.

## Troubleshooting
- **Missing email**: Confirm the LDAP → `email` mapping and claim description exist. phpGRC logs “OIDC email claim not found” with the claims list if the ID token lacks email.
- **Display name stuck as UPN**: Add display/given/surname transforms. phpGRC strips `DOMAIN\` when it falls back to `unique_name`/`upn`.
- **Health check warnings**: `401 unauthorized_client` indicates missing service account grant; `invalid_scope` usually means the Web API scopes weren’t granted.
- **CSP or mixed content errors**: Check DevTools for the exact blockage. Adjust headers or temporarily allow mixed content in the browser.
