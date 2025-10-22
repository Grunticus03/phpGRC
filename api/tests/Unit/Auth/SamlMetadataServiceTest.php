<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Services\Auth\SamlMetadataService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SamlMetadataServiceTest extends TestCase
{
    #[Test]
    public function parse_handles_signature_namespace_declared_on_entity_descriptor(): void
    {
        /** @var SamlMetadataService $service */
        $service = app(SamlMetadataService::class);

        $certificate = <<<'PEM'
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

        $metadataCertificate = preg_replace(
            ['#-----BEGIN CERTIFICATE-----#', '#-----END CERTIFICATE-----#', '#\\s+#'],
            '',
            $certificate
        );
        self::assertIsString($metadataCertificate);
        self::assertNotSame('', trim($metadataCertificate));

        $metadata = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="https://adfs.example.test/adfs/services/trust">
  <IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
    <KeyDescriptor use="signing">
      <ds:KeyInfo>
        <ds:X509Data>
          <ds:X509Certificate>{$metadataCertificate}</ds:X509Certificate>
        </ds:X509Data>
      </ds:KeyInfo>
    </KeyDescriptor>
    <SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://adfs.example.test/adfs/ls/"/>
  </IDPSSODescriptor>
</EntityDescriptor>
XML;

        $result = $service->parse($metadata);

        self::assertSame('https://adfs.example.test/adfs/services/trust', $result['entity_id']);
        self::assertSame('https://adfs.example.test/adfs/ls/', $result['sso_url']);
        self::assertSame($certificate, $result['certificate']);
    }
}
