<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\IdpProvider;
use App\Models\User;
use App\Support\AuthTokenCookie;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OneLogin\Saml2\Utils;
use Tests\TestCase;

final class SamlLoginTest extends TestCase
{
    private const string IDP_CERT = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDFzCCAf+gAwIBAgIUVG2YnXl0zg++P+7xP0oQuk7nuXowDQYJKoZIhvcNAQEL
BQAwGzEZMBcGA1UEAwwQaWRwLXRlc3QuZXhhbXBsZTAeFw0yNTEwMjQwMzE5MzZa
Fw0yNjEwMjQwMzE5MzZaMBsxGTAXBgNVBAMMEGlkcC10ZXN0LmV4YW1wbGUwggEi
MA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDZLkQqy6JL6oqCz1JlODSmjOMh
1ORhOmYy73ODfg3yzVC45+/5Nww51K8c33T5Xwx9jsXWRydCGBBQ/waK9iLRvcFl
K0f/rhoZLn8xxQF8QiwItnBcskfbxIpdq9ekzGY4wDWgAwO05gYHTy7XdBOwvMF/
eubQXafsjbLaNrZSB2xAxhCFy78yQMBmAsLJ3vPXpFbVqyvg28YjQ2cibowrZMiO
xsdRjDixkfD95DdoXOvHcQz1La4qfGXpo88n3dGkbdFzBkYExZsi9Ch2RqunufKw
euIWp1cYXBGxJJnLc9zdisJkh/neA7HykCE+VtxyZzx9wDp5e3tmEBZGZYFJAgMB
AAGjUzBRMB0GA1UdDgQWBBR14q1crcKgXqqjcItp6BUH7J1XRDAfBgNVHSMEGDAW
gBR14q1crcKgXqqjcItp6BUH7J1XRDAPBgNVHRMBAf8EBTADAQH/MA0GCSqGSIb3
DQEBCwUAA4IBAQBDnX8739mpI9JzlqA++uXowzCgt4PKOz4l7PP1B3cpyuUP0iQK
u8DTuKGQqf0kNCsofI9Fmb5BtDHcmNRxgj5SA1sPV2sVeWuS6dH5ZCFpoBdGDK+5
D9C/xMFw7jeTTCdpwoG83GNCm0lAfGdxwlhjIabr+L45BaLjFneXzM4AeS/jiexc
5zySnofQVxbwO92qvOT5b9D7wc6GH4shTDdKwB75KQJ2axbEQ7/bTSWMbj/X1+DL
dsC80OqYEvgVIFpCwG9dv9sX4Lh/wHpk1xH4zN97oZ9jmcwgywa/mdnmJFrKVnp6
GADG6OOwusOmQt8I+GrnYDUf17c1DYivN0TN
-----END CERTIFICATE-----
PEM;

    private const string IDP_PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDZLkQqy6JL6oqC
z1JlODSmjOMh1ORhOmYy73ODfg3yzVC45+/5Nww51K8c33T5Xwx9jsXWRydCGBBQ
/waK9iLRvcFlK0f/rhoZLn8xxQF8QiwItnBcskfbxIpdq9ekzGY4wDWgAwO05gYH
Ty7XdBOwvMF/eubQXafsjbLaNrZSB2xAxhCFy78yQMBmAsLJ3vPXpFbVqyvg28Yj
Q2cibowrZMiOxsdRjDixkfD95DdoXOvHcQz1La4qfGXpo88n3dGkbdFzBkYExZsi
9Ch2RqunufKweuIWp1cYXBGxJJnLc9zdisJkh/neA7HykCE+VtxyZzx9wDp5e3tm
EBZGZYFJAgMBAAECggEABQXQkRt5MGpHM8lEWDpENXSIAuNKji5sk1GoXpUkEMZ2
JHXwvdcWaEv76h4HituLjWfSOXzByAHdzBG/KVLnRpSDNtTDwIJam38mH+o0zkE1
hX9lT9orq/B6sWSPrdcFp4WtIns6CGKoJwkfcIorCROvIe9KC+2ZPEZL/vH0UNH3
iSTy9igKczOiF+UYmhHcYZEczq2Ws5b3ciOHHG1togyZ7cgFCRm5kFZ0PYoxxOHe
dBZqMthZvomn7yypLxq9USLQuO2sQih5Y87lkkQjgwuQU+iaBkYk5b85yORsBeKY
Wa112+NQvsLA02LOs7HFmsshUlfrOdKFeW8VXs1hxQKBgQD59KZ49x1GEREBV9Kg
CDaQn3ZhpBhA/LDVSTRuBEu/vivfpBex+6L81csJ/oijbrg5hGX+9eOIU/9CDHxI
GZqLk7eYlmDd+Nq5mJaD5plsKAMSfnn07GZsOid0wHJ5+fZnYquh1ijvvJNUN3KG
xhjHaq8WTOZdOZr75y0P26/O+wKBgQDebrkM3tqCt1OsN9GUOyIXFbJCBfyHtyB2
fgLThCyygQApirMck/69u+hcKR2mtdyosrXET9HYjj5XWfxIxTJ7LZJh5Ge5thqW
gAOqjtMtDrSsYANqOvJK4RzDp8BTkDB0s7YMre00dLx/mbwhu3EnHq22zdVgav1i
9WsjJkotiwKBgQDRVkNB1fwPXWW3kTzWSGqibtqvZcXmT3st9dRSO4jROkz2TTCH
IG9dfxQ/94uqDKV/jlH52SdJWsfSIjDIFaFoOjuuMGtKHAvbGl8ccrmVamFAUOqE
5KPXClFXJ4H4hA8IgQurS3gXaACfrJxfIXNJOCEQ9TCNbRxO0krcGCpClwKBgQCa
VrWsSo3QGajDXM/dTNKwtetEiKba/KRX08PeRF5HVd9o88aoU8B0oofuOtFKNfJw
U+Bv4Pq4iqqTLesyCzKl6c8igbu/Tq5QM29Xz1aUcxnGabC060Xn0x+EW0fGM8yL
jY8KaTyoI0AQQfoaZddzz4O1zkj6aAJ9wdCfSRg7VQKBgFxY1WDnlySS5GKDKduY
KypnsJraR7BzJabXaYu3UULZBkUy7IgNXqtFvZg59XraCAeHt5jimQa2NqTeS8DR
zfoYj5f5FzPZ7dY4zxJ5iKj+9B8euhTNaZwjg9OUdLt+wrT5P4GwJrA0ktTjJKlf
O2HyNCg//1U3xps2FN7fGfyc
-----END PRIVATE KEY-----
PEM;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('core.auth.saml.state', [
            'secret' => 'base64:'.base64_encode(str_repeat('#', 32)),
            'previous_secret' => null,
            'ttl_seconds' => 300,
            'clock_skew_seconds' => 30,
            'enforce_client_hash' => true,
        ]);
    }

    public function test_redirect_endpoint_returns_authorize_url(): void
    {
        $this->actingAsAdmin();
        $provider = $this->createProvider();

        Config::set('saml.sp', array_merge(Config::get('saml.sp', []), [
            'entityId' => 'https://phpgrc.example.test/saml/sp',
            'assertionConsumerService' => array_merge(
                Config::get('saml.sp.assertionConsumerService', []),
                [
                    'url' => 'https://phpgrc.example.test/auth/saml/acs',
                ]
            ),
            'metadataUrl' => 'https://phpgrc.example.test/auth/saml/metadata',
        ]));

        Config::set('saml.security', array_merge(Config::get('saml.security', []), [
            'authnRequestsSigned' => false,
            'wantAssertionsSigned' => false,
            'wantAssertionsEncrypted' => false,
        ]));

        $response = $this->getJson('/auth/saml/redirect?provider='.$provider->id);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('redirect', fn ($value) => is_string($value) && str_starts_with($value, 'https://idp.example.test/sso'));

        $redirectUrl = $response->json('redirect');
        self::assertIsString($redirectUrl);

        $parts = parse_url($redirectUrl);
        parse_str($parts['query'] ?? '', $query);

        self::assertArrayHasKey('RelayState', $query);
        self::assertArrayHasKey('SAMLRequest', $query);

        $deflatedRequest = base64_decode((string) $query['SAMLRequest'], true);
        self::assertNotFalse($deflatedRequest);

        $xmlPayload = gzinflate($deflatedRequest);
        self::assertIsString($xmlPayload);

        $document = new \DOMDocument('1.0', 'UTF-8');
        self::assertTrue($document->loadXML($xmlPayload));
        $isPassive = $document->documentElement->getAttribute('IsPassive');
        self::assertTrue($isPassive === '' || $isPassive === 'false');

        $payload = $this->decodeRelayState((string) $query['RelayState']);
        self::assertSame('phpgrc.saml.state', $payload['iss']);
        self::assertArrayHasKey('dest', $payload);
        self::assertNull($payload['dest']);
        self::assertArrayHasKey('rid', $payload);
    }

    public function test_acs_endpoint_authenticates_and_redirects(): void
    {
        Config::set('app.url', 'http://Desktop');
        Config::set('session.secure', true);

        $provider = $this->createProvider();

        $acsUrl = url('/auth/saml/acs');
        $spEntityId = url('/saml/sp');
        $metadataUrl = url('/auth/saml/metadata');
        $acsHost = parse_url($acsUrl, PHP_URL_HOST) ?? 'localhost';

        Config::set('saml', [
            'strict' => false,
            'debug' => false,
            'sp' => [
                'entityId' => $spEntityId,
                'assertionConsumerService' => [
                    'url' => $acsUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'metadataUrl' => $metadataUrl,
            ],
            'security' => [
                'authnRequestsSigned' => false,
                'logoutRequestSigned' => false,
                'logoutResponseSigned' => false,
                'wantAssertionsSigned' => false,
                'wantAssertionsEncrypted' => false,
                'wantNameId' => true,
                'wantNameIdEncrypted' => false,
                'requestedAuthnContext' => null,
            ],
            'http' => [
                'timeout' => 20,
                'connect_timeout' => 5,
                'proxy' => null,
                'verify_ssl' => true,
            ],
            'metadata' => [
                'cache_store' => 'array',
                'cache_ttl' => 900,
                'refresh_grace' => 60,
                'timeout' => 5,
                'retries' => 2,
                'allow_insecure_ssl' => false,
            ],
        ]);

        Config::set('saml.sp', array_merge(Config::get('saml.sp', []), [
            'entityId' => $spEntityId,
            'assertionConsumerService' => array_merge(
                Config::get('saml.sp.assertionConsumerService', []),
                [
                    'url' => $acsUrl,
                ]
            ),
            'metadataUrl' => $metadataUrl,
        ]));

        Config::set('saml.security', array_merge(Config::get('saml.security', []), [
            'authnRequestsSigned' => false,
            'wantAssertionsSigned' => false,
            'wantAssertionsEncrypted' => false,
        ]));

        $redirect = $this->getJson('/auth/saml/redirect?provider='.$provider->id.'&return=/dashboard')->json();

        self::assertIsArray($redirect);
        $redirectUrl = $redirect['redirect'] ?? null;
        self::assertIsString($redirectUrl);

        $parts = parse_url($redirectUrl);
        self::assertIsArray($parts);

        parse_str($parts['query'] ?? '', $query);
        self::assertArrayHasKey('RelayState', $query);
        $relayState = (string) $query['RelayState'];

        $payload = $this->decodeRelayState($relayState);
        $requestId = $payload['rid'] ?? null;
        self::assertIsString($requestId);

        $samlResponse = $this->buildSignedResponse(
            $requestId,
            $acsUrl,
            $spEntityId,
            'user@example.test',
            'SAML User'
        );

        $response = $this->withServerVariables([
            'HTTP_HOST' => $acsHost,
            'SERVER_NAME' => $acsHost,
        ])->post('/auth/saml/acs', [
            'SAMLResponse' => base64_encode($samlResponse),
            'RelayState' => $relayState,
        ]);

        $response->assertRedirect('/auth/callback?saml=1&dest=%2Fdashboard');
        $response->assertCookie(AuthTokenCookie::name());

        $user = User::query()->where('email', 'user@example.test')->first();
        self::assertNotNull($user);
        self::assertSame('SAML User', $user->name);
    }

    private function createProvider(): IdpProvider
    {
        return IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'saml-test',
            'name' => 'SAML Test',
            'driver' => 'saml',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'entity_id' => 'https://idp.example.test/entity',
                'sso_url' => 'https://idp.example.test/sso',
                'certificate' => self::IDP_CERT,
            ],
        ]);
    }

    private function actingAsAdmin(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create();
        if (Schema::hasTable('role_user')) {
            $admin->roles()->sync(['role_admin']);
        }

        Sanctum::actingAs($admin);
    }

    private function buildSignedResponse(
        string $requestId,
        string $acsUrl,
        string $audience,
        string $email,
        string $displayName
    ): string {
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $notOnOrAfter = gmdate('Y-m-d\TH:i:s\Z', time() + 600);

        $responseId = '_'.str_replace('-', '', Str::uuid()->toString());
        $assertionId = '_'.str_replace('-', '', Str::uuid()->toString());

        $xml = <<<XML
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ID="{$responseId}" Version="2.0" IssueInstant="{$issueInstant}" Destination="{$acsUrl}" InResponseTo="{$requestId}">
  <saml:Issuer>https://idp.example.test/entity</saml:Issuer>
  <samlp:Status>
    <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success" />
  </samlp:Status>
  <saml:Assertion ID="{$assertionId}" IssueInstant="{$issueInstant}" Version="2.0">
    <saml:Issuer>https://idp.example.test/entity</saml:Issuer>
    <saml:Subject>
      <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">{$email}</saml:NameID>
      <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
        <saml:SubjectConfirmationData InResponseTo="{$requestId}" NotOnOrAfter="{$notOnOrAfter}" Recipient="{$acsUrl}" />
      </saml:SubjectConfirmation>
    </saml:Subject>
    <saml:Conditions NotBefore="{$issueInstant}" NotOnOrAfter="{$notOnOrAfter}">
      <saml:AudienceRestriction>
        <saml:Audience>{$audience}</saml:Audience>
      </saml:AudienceRestriction>
    </saml:Conditions>
    <saml:AttributeStatement>
      <saml:Attribute Name="email" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:uri" FriendlyName="email">
      <saml:AttributeValue xsi:type="xs:string">{$email}</saml:AttributeValue>
      </saml:Attribute>
      <saml:Attribute Name="displayName" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:uri" FriendlyName="displayName">
      <saml:AttributeValue xsi:type="xs:string">{$displayName}</saml:AttributeValue>
      </saml:Attribute>
    </saml:AttributeStatement>
    <saml:AuthnStatement AuthnInstant="{$issueInstant}">
      <saml:AuthnContext>
        <saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef>
      </saml:AuthnContext>
    </saml:AuthnStatement>
  </saml:Assertion>
</samlp:Response>
XML;

        return Utils::addSign($xml, self::IDP_PRIVATE_KEY, self::IDP_CERT);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeRelayState(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts, 'RelayState token must contain 3 segments.');

        $payloadJson = $this->base64UrlDecode($parts[1]);
        $payload = json_decode($payloadJson, true);
        self::assertIsArray($payload);

        return $payload;
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $decoded = base64_decode($padded, true);
        self::assertIsString($decoded, 'RelayState token payload is not valid base64url.');

        return $decoded;
    }
}
