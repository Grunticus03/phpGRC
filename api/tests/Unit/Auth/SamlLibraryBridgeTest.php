<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Exceptions\Auth\SamlLibraryException;
use App\Models\IdpProvider;
use App\Services\Auth\SamlLibraryBridge;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\TestCase;

final class SamlLibraryBridgeTest extends TestCase
{
    public function test_process_response_wraps_library_exceptions(): void
    {
        config()->set('app.url', 'https://phpgrc.example');

        $bridge = app(SamlLibraryBridge::class);

        $provider = new IdpProvider;
        $provider->setAttribute('key', 'saml-test');

        $request = Request::create('/auth/saml/acs', 'POST', [
            'SAMLResponse' => 'invalid-response',
        ]);

        $this->expectException(SamlLibraryException::class);

        $bridge->processResponse(
            $provider,
            [
                'entity_id' => 'https://idp.example/entity',
                'sso_url' => 'https://idp.example/sso',
                'certificate' => $this->sampleCertificate(),
            ],
            $request,
            null
        );
    }

    public function test_generate_identity_provider_metadata_includes_certificate(): void
    {
        $bridge = app(SamlLibraryBridge::class);

        $metadata = $bridge->generateIdentityProviderMetadata([
            'entity_id' => 'https://idp.example/entity',
            'sso_url' => 'https://idp.example/sso',
            'certificate' => $this->sampleCertificate(),
        ], CarbonImmutable::create(2030, 1, 1));

        $document = simplexml_load_string($metadata);
        self::assertInstanceOf(\SimpleXMLElement::class, $document);
        $document->registerXPathNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');
        $document->registerXPathNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $entity = $document->xpath('/md:EntityDescriptor');
        self::assertNotFalse($entity);
        self::assertSame('https://idp.example/entity', (string) ($entity[0]['entityID'] ?? ''));

        $certNode = $document->xpath('/md:EntityDescriptor/md:IDPSSODescriptor/md:KeyDescriptor[@use="signing"]/ds:KeyInfo/ds:X509Data/ds:X509Certificate');
        self::assertNotFalse($certNode);
        self::assertNotEmpty($certNode);
        self::assertStringContainsString('MIICxjCCAa6gAwIBAgIUJ7X7YvXy5whh', (string) $certNode[0]);
    }

    public function test_parse_metadata_extracts_values(): void
    {
        $bridge = app(SamlLibraryBridge::class);

        $metadata = $bridge->generateIdentityProviderMetadata([
            'entity_id' => 'https://idp.example/entity',
            'sso_url' => 'https://idp.example/sso',
            'certificate' => $this->sampleCertificate(),
        ], CarbonImmutable::create(2030, 1, 1));

        $result = $bridge->parseMetadata($metadata);

        self::assertSame('https://idp.example/entity', $result['entity_id']);
        self::assertSame('https://idp.example/sso', $result['sso_url']);
        self::assertStringContainsString('BEGIN CERTIFICATE', $result['certificate']);
    }

    private function sampleCertificate(): string
    {
        return <<<'PEM'
-----BEGIN CERTIFICATE-----
MIICxjCCAa6gAwIBAgIUJ7X7YvXy5whhtjfiPgk41IrT9NAwDQYJKoZIhvcNAQEL
BQAwFTETMBEGA1UEAwwKc2FtbC10ZXN0MB4XDTI0MDEwMTAwMDAwMFoXDTM0MDEw
MTAwMDAwMFowFTETMBEGA1UEAwwKc2FtbC10ZXN0MIIBIjANBgkqhkiG9w0BAQEF
AAOCAQ8AMIIBCgKCAQEAr0pWQw0Hnq2ZlzfFbY3ybCmqa7e9DbKoTz3RvqFAn0Zf
IyO6jz5Dm48uoTWMukmMZ0P6E4ha3YJ4bLBPOfSmf/C4C5Qw9p+S5o5MWHbJkI7j
eWrjqh5ws8wX9AtUKgLw9SL98QtZVFBO3T8kA9OVdQ04cw/9ezEr6QO034QXkdpZ
5PGlTma63bplVOwUhbeGdnPL4489VJ5SACoQwQkn1vmpj6m7pKOGDWUy4KfUU8cX
nBASezPK5ghI1lpMUgUo/lhjggrB4/9lgYQtImHXQImiXoAhlmlpiG8Wp3xfpqgs
iY0QMhVNfy/7xKQXIDYiJlEUpP2Zjz4/v7K6HbqTMwIDAQABo1MwUTAdBgNVHQ4E
FgQUJDYw7w7wPwhjNzHNxog8Ppytg9kwHwYDVR0jBBgwFoAUJDYw7w7wPwhjNzHN
xog8Ppytg9kwDwYDVR0TAQH/BAUwAwEB/zANBgkqhkiG9w0BAQsFAAOCAQEAOYxQ
Gk4J4Pp8sVhQOWtmZ6vhj71R1z70dr50xj43Xj1H0w4WW+0lDuzHggzYM4G52g6l
2kBfnVBCcm/jRkDj1qGi6pQsKEd+bcfWZH7LvXsKTRZLdxDGjszT+2Xl9V7mWVPf
b0q8zKk0qJWQGFucvQKig9wAbHR0GmP2oRlOiuAIs61hp7d2kIs2cUJEsARQdILQ
efvHgTQgqYwZvh7gApS9Vz90suDWJ3+YkNPv6L+s7PJlIQMXM63iXU1BYf1YsKaA
X3h5xUEwsDPN9/5lCq3QxvxZ2O8xbrv6iH98sX8j5dhPj6l3SeSYyYqO6xbyicWG
ECdAPOTwv0u62Y8JEA==
-----END CERTIFICATE-----
PEM;
    }
}
