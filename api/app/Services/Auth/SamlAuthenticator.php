<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Auth\SamlAuthenticatorContract;
use App\Models\IdpProvider;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Concerns\ResolvesJitAssignments;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;
use SimpleXMLElement;

/**
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 */
final class SamlAuthenticator implements SamlAuthenticatorContract
{
    use ResolvesJitAssignments;

    private const ASSERTION_NS = 'urn:oasis:names:tc:SAML:2.0:assertion';

    private const PROTOCOL_NS = 'urn:oasis:names:tc:SAML:2.0:protocol';

    private const CLOCK_SKEW_SECONDS = 120;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly SamlServiceProviderConfigResolver $spConfig
    ) {}

    /**
     * @param  array<string,mixed>  $input
     *
     * @SuppressWarnings("PMD.NPathComplexity")
     * @SuppressWarnings("PMD.ExcessiveMethodLength")
     * @SuppressWarnings("PMD.StaticAccess")
     * @SuppressWarnings("PMD.ElseExpression")
     */
    #[\Override]
    public function authenticate(IdpProvider $provider, array $input, Request $request): User
    {
        $driver = strtolower($provider->driver);
        if ($driver !== 'saml') {
            throw ValidationException::withMessages([
                'provider' => ['Provider driver does not support SAML flows.'],
            ])->status(422);
        }

        /** @var array<string,mixed> $config */
        $config = (array) $provider->getAttribute('config');

        $encodedResponse = $input['SAMLResponse'] ?? $input['saml_response'] ?? null;
        if (! is_string($encodedResponse) || trim($encodedResponse) === '') {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['SAMLResponse is required.'],
            ])->status(422);
        }

        $expectedRequestId = null;
        if (isset($input['request_id']) && is_string($input['request_id'])) {
            $expectedRequestId = trim($input['request_id']);
        }

        $sp = $this->spConfig->resolve();
        $spEntityId = $sp['entity_id'];
        $acsUrl = $sp['acs_url'];

        try {
            $xmlResponse = $this->decodeResponse($encodedResponse);
            $certificate = $config['certificate'] ?? null;
            if (! is_string($certificate) || trim($certificate) === '') {
                throw new RuntimeException('SAML provider certificate is not configured.');
            }

            $this->validateSignature($xmlResponse, $certificate);

            $simple = $this->loadSimpleXml($xmlResponse);
            $assertion = $this->extractAssertion($simple);

            $responseIssuer = $this->stringValue($simple->xpath('saml:Issuer')[0] ?? null);
            $assertionIssuer = $this->stringValue($assertion->xpath('saml:Issuer')[0] ?? null);

            $this->validateResponseAttributes($simple, $expectedRequestId, $acsUrl);
            $this->validateConditions($assertion, $spEntityId, $acsUrl, $expectedRequestId);

            $claims = $this->extractClaims($assertion);
            if ($responseIssuer !== null && $responseIssuer !== '') {
                $this->storeClaim($claims, 'response.issuer', $responseIssuer);
            }
            if ($assertionIssuer !== null && $assertionIssuer !== '') {
                $this->storeClaim($claims, 'assertion.issuer', $assertionIssuer);
            }

            $jit = $this->resolveJitSettings($config);

            $email = $this->extractEmail($claims);
            if ($email === '') {
                throw ValidationException::withMessages([
                    'email' => ['SAML response missing email attribute.'],
                ])->status(422);
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->first();

            if (! $user instanceof User) {
                if (! $jit['create_users']) {
                    throw ValidationException::withMessages([
                        'email' => ['User does not exist and automatic provisioning is disabled.'],
                    ])->status(422);
                }

                $user = User::create([
                    'name' => $this->resolveName($claims, $email),
                    'email' => $email,
                    'password' => Hash::make(Str::uuid()->toString()),
                ]);
            } else {
                $this->updateUserName($user, $claims);
            }

            $roles = $this->resolveRoles($jit, fn (string $claim): mixed => $this->claimValue($claims, $claim));
            if ($roles !== []) {
                $existingRoleIds = Role::query()
                    ->whereIn('id', $roles)
                    ->pluck('id')
                    ->all();

                if ($existingRoleIds !== []) {
                    /** @var list<string> $existingRoleIds */
                    $user->roles()->syncWithoutDetaching($existingRoleIds);
                }
            }

            $this->logSuccess($provider, $user, $request, $claims);

            return $user;
        } catch (ValidationException $e) {
            $this->logFailure($provider, $request, $config, $e->errors());
            throw $e;
        } catch (RuntimeException $e) {
            $this->logFailure($provider, $request, $config, ['message' => [$e->getMessage()]]);

            throw ValidationException::withMessages([
                'SAMLResponse' => [$e->getMessage()],
            ])->status(401);
        } catch (\Throwable $e) {
            $this->logFailure($provider, $request, $config, ['message' => [$e->getMessage()]]);

            throw ValidationException::withMessages([
                'SAMLResponse' => ['Unexpected error while processing SAML response.'],
            ])->status(401);
        }
    }

    private function decodeResponse(string $encoded): DOMDocument
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || trim($decoded) === '') {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['Unable to decode SAMLResponse payload.'],
            ])->status(422);
        }

        $decoded = trim($decoded);
        if ($decoded === '') {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['Unable to decode SAMLResponse payload.'],
            ])->status(422);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($decoded, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($loaded !== true) {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['Unable to parse SAMLResponse XML document.'],
            ])->status(422);
        }

        return $document;
    }

    private function loadSimpleXml(DOMDocument $document): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_import_dom($document);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Failed to create SimpleXMLElement from SAML response.');
        }

        $xml->registerXPathNamespace('samlp', self::PROTOCOL_NS);
        $xml->registerXPathNamespace('saml', self::ASSERTION_NS);

        return $xml;
    }

    /**
     * @SuppressWarnings("PMD.NPathComplexity")
     * @SuppressWarnings("PMD.StaticAccess")
     */
    private function validateSignature(DOMDocument $document, string $certificate): void
    {
        $certificate = trim($certificate);
        if ($certificate === '') {
            throw new RuntimeException('SAML IdP certificate is empty.');
        }

        /** @var DOMElement $rootElement */
        $rootElement = $document->documentElement;
        $verificationTargets = [$rootElement];

        $assertions = $document->getElementsByTagNameNS(self::ASSERTION_NS, 'Assertion');
        if ($assertions->length > 0) {
            $assertion = $assertions->item(0);
            if ($assertion instanceof DOMElement) {
                $verificationTargets[] = $assertion;
            }
        }

        $lastException = null;

        foreach ($verificationTargets as $target) {
            $dsig = new XMLSecurityDSig;
            /** @psalm-suppress InvalidArgument */
            /** @phpstan-ignore-next-line */
            $signature = $dsig->locateSignature($target);
            if (! $signature instanceof DOMElement) {
                continue;
            }

            $dsig->canonicalizeSignedInfo();
            $key = $dsig->locateKey();
            if (! $key instanceof XMLSecurityKey) {
                $lastException = new RuntimeException('Unable to locate signing key in SAML response.');

                continue;
            }

            XMLSecEnc::staticLocateKeyInfo($key, $signature);

            $key->loadKey($certificate, false, true);

            try {
                if ($dsig->verify($key) === 1) {
                    return;
                }
            } catch (\Throwable $e) {
                $lastException = $e;

                continue;
            }

            $lastException = new RuntimeException('SAML signature verification failed.');
        }

        if ($lastException instanceof \Throwable) {
            throw $lastException;
        }

        throw new RuntimeException('SAML response missing XML signature.');
    }

    private function extractAssertion(SimpleXMLElement $xml): SimpleXMLElement
    {
        $assertions = $xml->xpath('./saml:Assertion');
        if (! is_array($assertions) || $assertions === []) {
            throw new RuntimeException('SAML response missing Assertion element.');
        }

        /** @var non-empty-array<SimpleXMLElement> $assertions */
        $assertion = $assertions[0];
        $assertion->registerXPathNamespace('saml', self::ASSERTION_NS);

        return $assertion;
    }

    private function validateResponseAttributes(SimpleXMLElement $xml, ?string $expectedRequestId, string $acsUrl): void
    {
        $destination = $this->stringValue($xml['Destination'] ?? null);
        if ($destination !== null && $destination !== '') {
            if ($this->normalizeUrl($destination) !== $this->normalizeUrl($acsUrl)) {
                throw ValidationException::withMessages([
                    'SAMLResponse' => ['AssertionConsumerServiceURL mismatch in SAML response.'],
                ])->status(401);
            }
        }

        if ($expectedRequestId !== null && $expectedRequestId !== '') {
            $inResponseTo = $this->stringValue($xml['InResponseTo'] ?? null);
            if ($inResponseTo !== null && $inResponseTo !== '' && $inResponseTo !== $expectedRequestId) {
                throw ValidationException::withMessages([
                    'SAMLResponse' => ['InResponseTo mismatch in SAML response.'],
                ])->status(401);
            }
        }
    }

    /**
     * @SuppressWarnings("PMD.NPathComplexity")
     * @SuppressWarnings("PMD.StaticAccess")
     */
    private function validateConditions(
        SimpleXMLElement $assertion,
        string $spEntityId,
        string $acsUrl,
        ?string $expectedRequestId
    ): void {
        $conditions = $assertion->xpath('./saml:Conditions');
        if (! is_array($conditions) || $conditions === []) {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['SAML assertion missing Conditions element.'],
            ])->status(401);
        }

        /** @var non-empty-array<SimpleXMLElement> $conditions */
        $conditionsNode = $conditions[0];

        $now = CarbonImmutable::now('UTC');

        $notBefore = $this->stringValue($conditionsNode['NotBefore'] ?? null);
        if ($notBefore !== null && $notBefore !== '') {
            $notBeforeTime = $this->parseSamlTime($notBefore);
            if ($notBeforeTime !== null && $now->lt($notBeforeTime->subSeconds(self::CLOCK_SKEW_SECONDS))) {
                throw ValidationException::withMessages([
                    'SAMLResponse' => ['SAML assertion not yet valid.'],
                ])->status(401);
            }
        }

        $notOnOrAfter = $this->stringValue($conditionsNode['NotOnOrAfter'] ?? null);
        if ($notOnOrAfter !== null && $notOnOrAfter !== '') {
            $notOnOrAfterTime = $this->parseSamlTime($notOnOrAfter);
            if ($notOnOrAfterTime !== null && $now->gte($notOnOrAfterTime->addSeconds(self::CLOCK_SKEW_SECONDS))) {
                throw ValidationException::withMessages([
                    'SAMLResponse' => ['SAML assertion has expired.'],
                ])->status(401);
            }
        }

        $audiences = $conditionsNode->xpath('./saml:AudienceRestriction/saml:Audience');
        if (! is_array($audiences) || $audiences === []) {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['SAML assertion missing AudienceRestriction.'],
            ])->status(401);
        }

        $audienceMatch = false;
        foreach ($audiences as $audienceNode) {
            $audience = $this->stringValue($audienceNode);
            if ($audience !== null && $this->normalizeAudience($audience) === $this->normalizeAudience($spEntityId)) {
                $audienceMatch = true;
                break;
            }
        }

        if (! $audienceMatch) {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['SAML assertion audience does not match service provider.'],
            ])->status(401);
        }

        $confirmationData = $assertion->xpath('./saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData');
        if (is_array($confirmationData) && $confirmationData !== []) {
            /** @var non-empty-array<SimpleXMLElement> $confirmationData */
            $subjectData = $confirmationData[0];

            $recipient = $this->stringValue($subjectData['Recipient'] ?? null);
            if ($recipient !== null && $recipient !== '' && $this->normalizeUrl($recipient) !== $this->normalizeUrl($acsUrl)) {
                throw ValidationException::withMessages([
                    'SAMLResponse' => ['SubjectConfirmation recipient does not match ACS URL.'],
                ])->status(401);
            }

            $subjectNotOnOrAfter = $this->stringValue($subjectData['NotOnOrAfter'] ?? null);
            if ($subjectNotOnOrAfter !== null && $subjectNotOnOrAfter !== '') {
                $subjectExpiry = $this->parseSamlTime($subjectNotOnOrAfter);
                if ($subjectExpiry !== null && $now->gte($subjectExpiry->addSeconds(self::CLOCK_SKEW_SECONDS))) {
                    throw ValidationException::withMessages([
                        'SAMLResponse' => ['Subject confirmation has expired.'],
                    ])->status(401);
                }
            }

            if ($expectedRequestId !== null && $expectedRequestId !== '') {
                $subjectResponseTo = $this->stringValue($subjectData['InResponseTo'] ?? null);
                if ($subjectResponseTo !== null && $subjectResponseTo !== '' && $subjectResponseTo !== $expectedRequestId) {
                    throw ValidationException::withMessages([
                        'SAMLResponse' => ['Subject confirmation request mismatch.'],
                    ])->status(401);
                }
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * @return array<string,mixed>
     *
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    private function extractClaims(SimpleXMLElement $assertion): array
    {
        $claims = [];

        $nameId = $assertion->xpath('./saml:Subject/saml:NameID');
        if ($nameId !== false && $nameId !== []) {
            $nameIdValue = $this->stringValue($nameId[0] ?? null);
            if ($nameIdValue !== null) {
                $this->storeClaim($claims, 'subject.name_id', $nameIdValue);
            }
        }

        $attributeNodes = $assertion->xpath('./saml:AttributeStatement/saml:Attribute');
        if (is_array($attributeNodes)) {
            foreach ($attributeNodes as $attribute) {
                /** @var SimpleXMLElement $attribute */
                $name = $this->stringValue($attribute['Name'] ?? null);
                $friendlyName = $this->stringValue($attribute['FriendlyName'] ?? null);

                $values = $attribute->xpath('./saml:AttributeValue');
                if (! is_array($values) || $values === []) {
                    continue;
                }

                foreach ($values as $valueNode) {
                    /** @var SimpleXMLElement $valueNode */
                    $valueRaw = $this->stringValue($valueNode);
                    if (! is_string($valueRaw)) {
                        continue;
                    }

                    $value = trim($valueRaw);
                    if ($value === '') {
                        continue;
                    }

                    if ($name !== null && $name !== '') {
                        $this->storeClaim($claims, $name, $value);
                    }

                    if ($friendlyName !== null && $friendlyName !== '') {
                        $this->storeClaim($claims, $friendlyName, $value);
                    }
                }
            }
        }

        return $claims;
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function extractEmail(array $claims): string
    {
        $candidateKeys = [
            'email',
            'mail',
            'emailaddress',
            'user.email',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
            'subject.name_id',
        ];

        foreach ($candidateKeys as $key) {
            $candidate = $this->claimValue($claims, $key);
            if (! is_string($candidate)) {
                continue;
            }

            $email = trim($candidate);
            if ($email !== '' && str_contains($email, '@')) {
                return mb_strtolower($email);
            }
        }

        try {
            $this->logger->notice('SAML email attribute not found in assertion.', [
                'available_claims' => array_keys($claims),
            ]);
        } catch (\Throwable $e) {
            // Logging should never block authentication.
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    /**
     * @param  array<string,mixed>  $claims
     *
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    private function resolveName(array $claims, string $fallbackEmail): string
    {
        $displayName = $this->firstNonEmptyClaim($claims, [
            'displayname',
            'cn',
            'name',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/displayname',
        ]);
        if ($displayName !== null) {
            return $displayName;
        }

        $given = $this->firstNonEmptyClaim($claims, ['givenname', 'given_name']);
        $surname = $this->firstNonEmptyClaim($claims, ['sn', 'surname', 'family_name']);

        if ($given !== null && $surname !== null) {
            return $given.' '.$surname;
        }

        if ($given !== null) {
            return $given;
        }

        if ($surname !== null) {
            return $surname;
        }

        $principal = $this->firstNonEmptyClaim($claims, ['subject.name_id']);
        if ($principal !== null) {
            return $this->normalizePrincipal($principal);
        }

        return $fallbackEmail;
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function updateUserName(User $user, array $claims): void
    {
        $resolved = trim($this->resolveName($claims, $user->email));
        $currentName = trim($user->name);

        if ($resolved === '' || strcasecmp($resolved, trim($user->email)) === 0) {
            return;
        }

        if (strcasecmp($resolved, $currentName) === 0) {
            return;
        }

        $user->name = $resolved;
        $user->save();
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function claimValue(array $claims, string $key): mixed
    {
        $candidates = [];
        $trimmed = trim($key);
        if ($trimmed === '') {
            return null;
        }

        $candidates[] = $trimmed;
        $lower = mb_strtolower($trimmed);
        if ($lower !== $trimmed) {
            $candidates[] = $lower;
        }

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $claims)) {
                return $claims[$candidate];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function storeClaim(array &$claims, string $key, string $value): void
    {
        $normalizedKey = trim($key);
        if ($normalizedKey === '') {
            return;
        }

        $this->appendClaimValue($claims, $normalizedKey, $value);

        $lower = mb_strtolower($normalizedKey);
        if ($lower !== $normalizedKey) {
            $this->appendClaimValue($claims, $lower, $value);
        }
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function appendClaimValue(array &$claims, string $key, string $value): void
    {
        if (! array_key_exists($key, $claims)) {
            $claims[$key] = $value;

            return;
        }

        if (is_string($claims[$key])) {
            if (strcasecmp($claims[$key], $value) === 0) {
                return;
            }

            $claims[$key] = [$claims[$key], $value];

            return;
        }

        if (is_array($claims[$key])) {
            $values = $claims[$key];
            $values[] = $value;
            $stringValues = array_values(array_filter(
                $values,
                static fn ($item): bool => is_string($item)
            ));
            /** @var list<string> $unique */
            $unique = array_values(array_unique($stringValues));
            $claims[$key] = $unique;
        }
    }

    /**
     * @param  array<string,mixed>  $claims
     * @param  list<string>  $keys
     */
    private function firstNonEmptyClaim(array $claims, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->claimValue($claims, $key);
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function logSuccess(IdpProvider $provider, User $user, Request $request, array $claims): void
    {
        $entityId = trim($provider->id);
        if ($entityId === '') {
            $entityId = trim($provider->key);
        }
        if ($entityId === '') {
            $entityId = 'idp.provider';
        }

        $this->audit->log([
            'actor_id' => $user->id,
            'action' => 'auth.saml.login',
            'category' => 'AUTH',
            'entity_type' => 'idp.provider',
            'entity_id' => $entityId,
            'ip' => $request->ip(),
            'ua' => $request->userAgent(),
            'meta' => [
                'provider_key' => $provider->key,
                'issuer' => $claims['response.issuer'] ?? $claims['assertion.issuer'] ?? null,
                'subject' => $claims['subject.name_id'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<array-key,mixed>  $details
     */
    private function logFailure(IdpProvider $provider, Request $request, array $config, array $details): void
    {
        try {
            $this->logger->warning('SAML authentication failure.', [
                'provider' => $provider->key,
                'issuer' => $config['entity_id'] ?? null,
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'details' => $details,
            ]);
        } catch (\Throwable $e) {
            // never block on logging.
        }
    }

    private function parseSamlTime(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeAudience(string $audience): string
    {
        return rtrim(trim($audience), '/');
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof SimpleXMLElement) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizePrincipal(string $principal): string
    {
        $trimmed = trim($principal);
        if (! str_contains($trimmed, '\\')) {
            return $trimmed;
        }

        $parts = explode('\\', $trimmed);
        /** @var string|false $last */
        $last = end($parts);

        if ($last === false || $last === '') {
            return $trimmed;
        }

        return $last;
    }
}
