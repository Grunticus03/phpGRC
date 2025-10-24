# SAML Library Integration Notes

phpGRC now delegates all SAML protocol handling to [`onelogin/php-saml`](https://github.com/SAML-Toolkits/php-saml). This document captures the runtime expectations when operating the new bridge layer.

## Service Provider configuration

SAML-specific configuration lives in `config/saml.php`. The following keys must be populated (either directly or via the documented environment variables):

| Path | Description | Example env override |
| ---- | ----------- | -------------------- |
| `saml.sp.entityId` | SP Entity ID presented to IdPs. Defaults to `<APP_URL>/saml/sp`. | `SAML_SP_ENTITY_ID` |
| `saml.sp.assertionConsumerService.url` | ACS endpoint that receives SAML responses. Defaults to `<APP_URL>/auth/saml/acs`. | `SAML_SP_ACS_URL` |
| `saml.sp.metadataUrl` | Public metadata URL exposed by phpGRC. | `SAML_SP_METADATA_URL` |
| `saml.sp.x509cert` / `SAML_SP_CERTIFICATE` | Optional SP signing certificate. | `SAML_SP_CERTIFICATE` |
| `saml.sp.privateKey` / `SAML_SP_PRIVATE_KEY` | Optional SP signing key (inline or via `*_PATH`). | `SAML_SP_PRIVATE_KEY`, `SAML_SP_PRIVATE_KEY_PATH` |
| `saml.security.*` | Mirrors OneLogin security toggles (signing, encryption, requested authn context). | `SAML_SP_SIGN_REQUESTS`, `SAML_SP_WANT_ASSERTIONS_SIGNED`, etc. |

State token configuration continues to live under `core.auth.saml.state`. The bridge still relies on those values to issue and validate RelayState via `SamlStateTokenFactory`.

## Identity provider metadata

The bridge exposes helper methods used by controllers and tests:

- `SamlLibraryBridge::parseMetadata(string $xml)` extracts entity ID, SSO/SLO endpoints, and certificates from remote IdP metadata.
- `SamlLibraryBridge::generateIdentityProviderMetadata(array $config)` emits IdP metadata when operators choose to host their own.

These helpers replace the legacy `SamlMetadataService`. Any automation or tests should exercise the bridge rather than the removed service class.

## Error handling

All calls into the OneLogin toolkit are wrapped by the bridge. Failures raised by `OneLogin_Saml2_Error` or `OneLogin_Saml2_ValidationError` are converted into `App\Exceptions\Auth\SamlLibraryException` and surfaced to controllers/authenticators as domain-level validation errors.

## Testing guidance

- Feature tests that drive the redirect and ACS endpoints should continue to post `SAMLResponse` payloads. The bridge copies request inputs into the globals expected by the library before processing responses.
- Unit tests can mock `SamlLibraryFactory` to supply preconfigured `OneLogin_Saml2_Auth` doubles when exercising failure scenarios.

## Migration tips

1. Remove any direct usage of the bespoke `SamlAuthnRequestBuilder` or XML helpers—controllers should rely on `SamlLibraryBridge` instead.
2. Configure any custom certificates/keys through the new env variables. Posting PEM blobs through API payloads is no longer necessary.
3. For IdP imports, supply raw metadata XML; the bridge handles parsing and validation and stores normalized config fields for you.
