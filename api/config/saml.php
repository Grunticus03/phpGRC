<?php

declare(strict_types=1);

if (! function_exists('saml_config_bool')) {
    function saml_config_bool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return (bool) $value;
    }
}

if (! function_exists('saml_config_int')) {
    function saml_config_int(mixed $value, int $default): int
    {
        if ($value === null) {
            return $default;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $default;
            }

            if (is_numeric($trimmed)) {
                return (int) $trimmed;
            }
        }

        return $default;
    }
}

if (! function_exists('saml_config_string')) {
    function saml_config_string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$defaultEntityId = env('SAML_SP_ENTITY_ID', sprintf('%s/saml/sp', $appUrl));
$defaultAcsUrl = env('SAML_SP_ACS_URL', sprintf('%s/auth/saml/acs', $appUrl));
$defaultMetadataUrl = env('SAML_SP_METADATA_URL', sprintf('%s/auth/saml/metadata', $appUrl));
$defaultLogoutUrl = env('SAML_SP_SLO_URL');

$signAuthnRequests = saml_config_bool(env('SAML_SP_SIGN_REQUESTS', null), false);
$signLogoutRequests = saml_config_bool(env('SAML_SP_SIGN_LOGOUT_REQUESTS', null), $signAuthnRequests);
$signLogoutResponses = saml_config_bool(env('SAML_SP_SIGN_LOGOUT_RESPONSES', null), $signAuthnRequests);

return [
    'strict' => saml_config_bool(env('SAML_STRICT_MODE', null), true),
    'debug' => saml_config_bool(env('APP_DEBUG', null), false),

    'sp' => [
        'entityId' => saml_config_string($defaultEntityId) ?? sprintf('%s/saml/sp', $appUrl),
        'assertionConsumerService' => [
            'url' => saml_config_string($defaultAcsUrl) ?? sprintf('%s/auth/saml/acs', $appUrl),
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],
        'singleLogoutService' => [
            'url' => saml_config_string($defaultLogoutUrl),
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'metadataUrl' => saml_config_string($defaultMetadataUrl) ?? sprintf('%s/auth/saml/metadata', $appUrl),
        'NameIDFormat' => saml_config_string(env('SAML_SP_NAME_ID_FORMAT', null)) ?? 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
        'x509cert' => saml_config_string(env('SAML_SP_CERTIFICATE', null)),
        'x509certPath' => saml_config_string(env('SAML_SP_CERTIFICATE_PATH', null)),
        'privateKey' => saml_config_string(env('SAML_SP_PRIVATE_KEY', null)),
        'privateKeyPath' => saml_config_string(env('SAML_SP_PRIVATE_KEY_PATH', null)),
        'privateKeyPassphrase' => saml_config_string(env('SAML_SP_PRIVATE_KEY_PASSPHRASE', null)),
    ],

    'security' => [
        'requestedAuthnContext' => saml_config_string(env('SAML_REQUESTED_AUTHN_CONTEXT', null)),
        'requestedAuthnContextComparison' => saml_config_string(env('SAML_REQUESTED_AUTHN_CONTEXT_COMPARISON', null)) ?? 'exact',
        'signMetadata' => saml_config_bool(env('SAML_SP_SIGN_METADATA', null), false),
        'authnRequestsSigned' => $signAuthnRequests,
        'logoutRequestSigned' => $signLogoutRequests,
        'logoutResponseSigned' => $signLogoutResponses,
        'wantMessagesSigned' => saml_config_bool(env('SAML_SP_WANT_MESSAGES_SIGNED', null), false),
        'wantAssertionsSigned' => saml_config_bool(env('SAML_SP_WANT_ASSERTIONS_SIGNED', null), true),
        'wantAssertionsEncrypted' => saml_config_bool(env('SAML_SP_WANT_ASSERTIONS_ENCRYPTED', null), false),
        'wantNameId' => saml_config_bool(env('SAML_SP_WANT_NAME_ID', null), true),
        'wantNameIdEncrypted' => saml_config_bool(env('SAML_SP_WANT_NAME_ID_ENCRYPTED', null), false),
        'relaxDestinationValidation' => saml_config_bool(env('SAML_SP_RELAX_DESTINATION_VALIDATION', null), false),
        'lowercaseUrlencoding' => saml_config_bool(env('SAML_SP_LOWERCASE_URL_ENCODING', null), false),
    ],

    'http' => [
        'timeout' => saml_config_int(env('SAML_HTTP_TIMEOUT_SECONDS', null), 20),
        'connect_timeout' => saml_config_int(env('SAML_HTTP_CONNECT_TIMEOUT_SECONDS', null), 5),
        'proxy' => saml_config_string(env('SAML_HTTP_PROXY', null)),
        'verify_ssl' => saml_config_bool(env('SAML_HTTP_VERIFY_SSL', null), true),
    ],

    'metadata' => [
        'cache_store' => saml_config_string(env('SAML_METADATA_CACHE_STORE', null)) ?? 'array',
        'cache_ttl' => saml_config_int(env('SAML_METADATA_CACHE_TTL_SECONDS', null), 900),
        'refresh_grace' => saml_config_int(env('SAML_METADATA_REFRESH_GRACE_SECONDS', null), 60),
        'timeout' => saml_config_int(env('SAML_METADATA_HTTP_TIMEOUT_SECONDS', null), 5),
        'retries' => saml_config_int(env('SAML_METADATA_HTTP_RETRIES', null), 2),
        'allow_insecure_ssl' => saml_config_bool(env('SAML_METADATA_ALLOW_INSECURE_SSL', null), false),
    ],
];
