<?php

declare(strict_types=1);

namespace App\Services\Auth\Ldap;

/**
 * LDAP client backed by PHP's native LDAP extension.
 * Designed so tests can swap in fakes without relying on the extension.
 *
 * @SuppressWarnings("PMD.CyclomaticComplexity")
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PMD.TooManyMethods")
 */
final class NativeLdapClient implements LdapClientInterface
{
    private const USERNAME_PLACEHOLDER = '{{username}}';

    private const DIRECTORY_BROWSE_FILTER = '(objectClass=*)';

    /**
     * Subset of attributes requested during directory browsing to keep responses lightweight.
     */
    private const DIRECTORY_BROWSE_ATTRIBUTES = ['ou', 'cn', 'dc', 'objectClass'];

    private readonly LdapEntryNormalizer $normalizer;

    public function __construct(?LdapEntryNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new LdapEntryNormalizer;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    #[\Override]
    public function checkConnection(array $config): void
    {
        $connection = $this->connect($config);

        try {
            if (($config['bind_strategy'] ?? 'service') === 'service') {
                $bindDn = $config['bind_dn'] ?? null;
                $bindPassword = $config['bind_password'] ?? null;

                if (! is_string($bindDn) || ! is_string($bindPassword)) {
                    throw new LdapException('Service bind credentials must be strings.');
                }

                $this->bindService($connection, $bindDn, $bindPassword);
            }
        } finally {
            $this->close($connection);
        }
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{dn:string,attributes:array<string,list<string>>}
     */
    #[\Override]
    public function authenticate(array $config, string $username, string $password): array
    {
        $connection = $this->connect($config);

        try {
            return ($config['bind_strategy'] ?? 'service') === 'service'
                ? $this->authenticateWithServiceAccount($connection, $config, $username, $password)
                : $this->authenticateWithDirectBind($connection, $config, $username, $password);
        } finally {
            $this->close($connection);
        }
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{
     *   root: bool,
     *   base_dn: string|null,
     *   entries: list<array{
     *     dn: string,
     *     rdn: string,
     *     name: string,
     *     type: string,
     *     object_class: array<int,string>,
     *     has_children: bool
     *   }>,
     *   diagnostics?: array<string,mixed>
     * }
     */
    #[\Override]
    public function browse(array $config, ?string $baseDn = null): array
    {
        $connection = $this->connect($config);

        try {
            $strategy = $config['bind_strategy'] ?? 'service';
            if ($strategy !== 'service') {
                throw new LdapException('Directory browsing requires a service bind strategy.');
            }

            $bindDn = $config['bind_dn'] ?? null;
            $bindPassword = $config['bind_password'] ?? null;

            if (! is_string($bindDn) || ! is_string($bindPassword)) {
                throw new LdapException('Service bind credentials must be configured for directory browsing.');
            }

            $this->bindService($connection, $bindDn, $bindPassword);

            $trimmedBaseDn = $baseDn === null ? null : trim($baseDn);
            $shouldReadRoot = $trimmedBaseDn === null || $trimmedBaseDn === '';

            /** @var array{
             *   root: bool,
             *   base_dn: string|null,
             *   entries: list<array{
             *     dn: string,
             *     rdn: string,
             *     name: string,
             *     type: string,
             *     object_class: array<int,string>,
             *     has_children: bool
             *   }>,
             *   diagnostics: array<string,mixed>
             * } $result
             */
            $result = $shouldReadRoot
                ? $this->readRootDseContexts($connection)
                : $this->listChildEntries($connection, $this->requireBrowseBaseDn($trimmedBaseDn));

            $connectionDiag = $this->connectionDiagnostics($connection);
            if ($connectionDiag !== []) {
                $result['diagnostics']['connection'] = $connectionDiag;
            }

            return $result;
        } finally {
            $this->close($connection);
        }
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function connect(array $config): \LDAP\Connection
    {
        $connection = $this->openConnection($config);
        $this->applyConnectionOptions($connection, $config);
        $this->maybeStartTls($connection, $config);

        return $connection;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function openConnection(array $config): \LDAP\Connection
    {
        if (! extension_loaded('ldap')) {
            throw new LdapException('PHP LDAP extension is not installed.');
        }

        $hostRaw = $config['host'] ?? null;
        if (! is_string($hostRaw) || $hostRaw === '') {
            throw new LdapException('LDAP host must be a non-empty string.');
        }
        $host = $hostRaw;

        $port = 389;
        if (isset($config['port']) && is_int($config['port'])) {
            $port = $config['port'];
        }

        $useSsl = isset($config['use_ssl']) ? (bool) $config['use_ssl'] : false;
        $scheme = $useSsl ? 'ldaps' : 'ldap';
        $uri = sprintf('%s://%s:%d', $scheme, $host, $port);

        $connection = $this->withSuppressedWarnings(static fn (): \LDAP\Connection|false => ldap_connect($uri));
        if (! $connection instanceof \LDAP\Connection) {
            throw new LdapException('Unable to connect to LDAP host.');
        }

        return $connection;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function applyConnectionOptions(\LDAP\Connection $connection, array $config): void
    {
        $protocolOption = $this->intConstant('LDAP_OPT_PROTOCOL_VERSION');
        if ($protocolOption !== null) {
            $this->setOption($connection, $protocolOption, 3);
        }

        $referralOption = $this->intConstant('LDAP_OPT_REFERRALS');
        if ($referralOption !== null) {
            $this->setOption($connection, $referralOption, 0);
        }

        if (isset($config['timeout']) && is_int($config['timeout'])) {
            $timeoutOption = $this->intConstant('LDAP_OPT_NETWORK_TIMEOUT');
            if ($timeoutOption !== null) {
                $this->setOption($connection, $timeoutOption, $config['timeout']);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function maybeStartTls(\LDAP\Connection $connection, array $config): void
    {
        $startTlsRequested = isset($config['start_tls']) ? (bool) $config['start_tls'] : false;
        if (! $startTlsRequested) {
            return;
        }

        if (! function_exists('ldap_start_tls')) {
            throw new LdapException('LDAP StartTLS is not available.');
        }

        $result = (bool) $this->withSuppressedWarnings(static fn (): bool => ldap_start_tls($connection));
        if ($result !== true) {
            throw new LdapException('Failed to negotiate StartTLS.');
        }
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{dn:string,attributes:array<string,list<string>>}
     */
    private function authenticateWithServiceAccount(\LDAP\Connection $connection, array $config, string $username, string $password): array
    {
        $bindDn = $config['bind_dn'] ?? null;
        $bindPassword = $config['bind_password'] ?? null;

        if (! is_string($bindDn) || ! is_string($bindPassword)) {
            throw new LdapException('Service bind credentials must be strings.');
        }

        $this->bindService($connection, $bindDn, $bindPassword);

        $entry = $this->findUserEntry($connection, $config, $username);
        $userDn = $entry['dn'];

        $this->bindUser($connection, $userDn, $password);

        return [
            'dn' => $userDn,
            'attributes' => $entry['attributes'],
        ];
    }

    /**
     * @return array{
     *   root: true,
     *   base_dn: null,
     *   entries: list<array{
     *     dn: string,
     *     rdn: string,
     *     name: string,
     *     type: string,
     *     object_class: array<int,string>,
     *     has_children: bool
     *   }>,
     *   diagnostics: array<string,mixed>
     * }
     */
    private function readRootDseContexts(\LDAP\Connection $connection): array
    {
        /** @var mixed $result */
        $result = $this->withSuppressedWarnings(static fn () => ldap_read(
            $connection,
            '',
            self::DIRECTORY_BROWSE_FILTER,
            ['namingContexts'],
            0,
            0
        ));

        if ($result === false || ! $result instanceof \LDAP\Result) {
            throw new LdapException('Failed to query LDAP root DSE: '.$this->lastError($connection));
        }

        /** @var mixed $entries */
        $entries = $this->withSuppressedWarnings(static fn () => ldap_get_entries($connection, $result));
        if (! is_array($entries) || ($entries['count'] ?? 0) < 1) {
            throw new LdapException('LDAP server did not return any naming contexts.');
        }

        /** @var array<string,mixed>|null $entry */
        $entry = $entries[0] ?? null;
        $contexts = $this->extractAttributeValues($entry, 'namingcontexts');

        if ($contexts === []) {
            throw new LdapException('LDAP server did not return any naming contexts.');
        }

        $items = array_values(array_map(function (string $context): array {
            $rdn = $this->extractRdn($context);
            /** @var array<int,string> $emptyClasses */
            $emptyClasses = [];

            return [
                'dn' => $context,
                'rdn' => $rdn,
                'name' => $rdn,
                'type' => 'context',
                'object_class' => $emptyClasses,
                'has_children' => true,
            ];
        }, $contexts));

        $diagnostics = [
            'search' => [
                'requested_dn' => null,
                'filter' => self::DIRECTORY_BROWSE_FILTER,
                'scope' => 'base',
                'attributes' => ['namingContexts'],
                'returned' => count($items),
            ],
        ];

        $diagnosticMessage = $this->diagnosticMessage($connection);
        if ($diagnosticMessage !== null) {
            $diagnostics['diagnostic_message'] = $diagnosticMessage;
        }

        return [
            'root' => true,
            'base_dn' => null,
            'entries' => $items,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array{
     *   root: false,
     *   base_dn: string,
     *   entries: list<array{
     *     dn: string,
     *     rdn: string,
     *     name: string,
     *     type: string,
     *     object_class: array<int,string>,
     *     has_children: bool
     *   }>,
     *   diagnostics: array<string,mixed>
     * }
     */
    private function listChildEntries(\LDAP\Connection $connection, string $baseDn): array
    {
        $result = $this->executeBrowseQuery($connection, $baseDn);
        $entries = $this->hydrateBrowseEntries($connection, $result);
        $items = $this->mapBrowseEntries($entries);
        $diagnostics = $this->buildBrowseDiagnostics($connection, $baseDn, count($items));

        return [
            'root' => false,
            'base_dn' => $baseDn,
            'entries' => $items,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{dn:string,attributes:array<string,list<string>>}
     */
    private function authenticateWithDirectBind(\LDAP\Connection $connection, array $config, string $username, string $password): array
    {
        $userDn = $this->buildUserDn($config, $username);

        $this->bindUser($connection, $userDn, $password);

        $entry = $this->readEntry($connection, $userDn);

        return [
            'dn' => $userDn,
            'attributes' => $entry['attributes'],
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{dn:string,attributes:array<string,list<string>>}
     */
    private function findUserEntry(\LDAP\Connection $connection, array $config, string $username): array
    {
        $baseDnRaw = $config['base_dn'] ?? null;
        if (! is_string($baseDnRaw) || $baseDnRaw === '') {
            throw new LdapException('Base DN is required for LDAP searches.');
        }
        $baseDn = $baseDnRaw;
        $filter = $this->buildFilter($config, $username);

        /** @var mixed $result */
        $result = $this->withSuppressedWarnings(static fn () => ldap_search($connection, $baseDn, $filter, [], 0, 1));
        if ($result === false) {
            throw new LdapException('LDAP search failed: '.$this->lastError($connection));
        }

        if (! $result instanceof \LDAP\Result) {
            throw new LdapException('LDAP search did not return a valid result resource.');
        }

        return $this->firstEntry($connection, $result);
    }

    /**
     * @return array{dn:string,attributes:array<string,list<string>>}
     */
    private function readEntry(\LDAP\Connection $connection, string $dn): array
    {
        /** @var mixed $result */
        $result = $this->withSuppressedWarnings(static fn () => ldap_read($connection, $dn, '(objectClass=*)'));
        if ($result === false) {
            throw new LdapException('Failed to read LDAP entry: '.$this->lastError($connection));
        }

        if (! $result instanceof \LDAP\Result) {
            throw new LdapException('LDAP read did not return a valid result resource.');
        }

        return $this->firstEntry($connection, $result);
    }

    /**
     * @return array{dn:string,attributes:array<string,list<string>>}
     */
    private function firstEntry(\LDAP\Connection $connection, \LDAP\Result $result): array
    {
        /** @var array<int|string,mixed>|false $entries */
        $entries = $this->withSuppressedWarnings(static fn () => ldap_get_entries($connection, $result));
        if (! is_array($entries)) {
            throw new LdapException('LDAP response could not be decoded.');
        }

        $count = isset($entries['count']) && is_int($entries['count']) ? $entries['count'] : 0;
        if ($count < 1) {
            throw new LdapException('LDAP user not found.');
        }

        /** @var array<string,mixed> $entry */
        $entry = $entries[0];

        return $this->normalizer->normalize($entry);
    }

    private function bindService(\LDAP\Connection $connection, string $dn, string $password): void
    {
        if ($this->attemptBind($connection, $dn, $password)) {
            return;
        }

        throw new LdapException($this->formatErrorMessage('Service bind failed.', $connection, true));
    }

    private function bindUser(\LDAP\Connection $connection, string $dn, string $password): void
    {
        if ($this->attemptBind($connection, $dn, $password)) {
            return;
        }

        throw new LdapException($this->formatErrorMessage('Invalid LDAP credentials.', $connection, false));
    }

    private function attemptBind(\LDAP\Connection $connection, string $dn, string $password): bool
    {
        $callable = $dn === ''
            ? static fn (): bool => ldap_bind($connection)
            : static fn (): bool => ldap_bind($connection, $dn, $password);

        return $this->withSuppressedWarnings($callable) !== false;
    }

    private function formatErrorMessage(string $baseMessage, \LDAP\Connection $connection, bool $includeDetail): string
    {
        if (! $includeDetail) {
            return $baseMessage;
        }

        $diagnostics = $this->connectionDiagnostics($connection);
        /** @var array{code?: int, error?: string, diagnostic_message?: string} $diagnostics */
        /** @var array{code?: int, error?: string, diagnostic_message?: string} $diagnostics */
        /** @var list<string> $segments */
        $segments = [rtrim($baseMessage, '.')];

        $code = $diagnostics['code'] ?? null;
        if (is_int($code) && $code !== 0) {
            $segments[0] .= sprintf(' (code %d)', $code);
        }

        $detail = $diagnostics['error'] ?? null;
        if ($detail === null || $detail === '') {
            $detail = $this->lastError($connection);
        }
        if ($detail !== '') {
            $segments[] = $detail;
        }

        $diagMessage = $diagnostics['diagnostic_message'] ?? null;
        if (is_string($diagMessage) && $diagMessage !== '') {
            $segments[] = '['.$diagMessage.']';
        }

        return implode(' ', $segments);
    }

    private function setOption(\LDAP\Connection $connection, int $option, int $value): void
    {
        if (! function_exists('ldap_set_option')) {
            return;
        }

        $this->withSuppressedWarnings(static fn (): bool => ldap_set_option($connection, $option, $value));
    }

    private function close(\LDAP\Connection $connection): void
    {
        if (! function_exists('ldap_unbind')) {
            return;
        }

        $this->withSuppressedWarnings(static fn (): bool => ldap_unbind($connection));
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function buildFilter(array $config, string $username): string
    {
        $filterRaw = $config['user_filter'] ?? null;
        if (! is_string($filterRaw)) {
            throw new LdapException('User filter configuration missing.');
        }

        $filterTrimmed = trim($filterRaw);
        if ($filterTrimmed === '') {
            throw new LdapException('User filter configuration missing.');
        }

        return str_replace(self::USERNAME_PLACEHOLDER, $this->escapeForFilter($username), $filterTrimmed);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function buildUserDn(array $config, string $username): string
    {
        $templateRaw = $config['user_dn_template'] ?? null;
        if (! is_string($templateRaw)) {
            throw new LdapException('User DN template configuration missing.');
        }

        $templateTrimmed = trim($templateRaw);
        if ($templateTrimmed === '') {
            throw new LdapException('User DN template configuration missing.');
        }

        return str_replace(self::USERNAME_PLACEHOLDER, $this->escapeForDn($username), $templateTrimmed);
    }

    private function escapeForFilter(string $value): string
    {
        if (function_exists('ldap_escape')) {
            $flag = $this->intConstant('LDAP_ESCAPE_FILTER') ?? 0;

            return ldap_escape($value, '', $flag);
        }

        return str_replace(
            ['\\', '*', '(', ')', "\x00"],
            ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
            $value
        );
    }

    private function escapeForDn(string $value): string
    {
        if (function_exists('ldap_escape')) {
            $flag = $this->intConstant('LDAP_ESCAPE_DN') ?? 0;

            return ldap_escape($value, '', $flag);
        }

        return str_replace(
            ['\\', ',', '+', '"', '<', '>', ';', '=', '#'],
            ['\\5c', '\\2c', '\\2b', '\\22', '\\3c', '\\3e', '\\3b', '\\3d', '\\23'],
            $value
        );
    }

    /**
     * @param  array<string,mixed>|null  $entry
     * @return array<int,string>
     */
    private function extractAttributeValues(?array $entry, string $attribute): array
    {
        if ($entry === null) {
            return [];
        }

        /** @var mixed $values */
        $values = $entry[$attribute] ?? null;
        if (! is_array($values)) {
            return [];
        }

        $count = isset($values['count']) && is_int($values['count']) ? $values['count'] : 0;
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            /** @var mixed $value */
            $value = $values[$i] ?? null;
            if (is_string($value) && $value !== '') {
                $result[] = $value;
            }
        }

        return $result;
    }

    private function extractRdn(string $dn): string
    {
        $parts = explode(',', $dn, 2);
        $rdn = $parts[0];
        if ($rdn === '') {
            return trim($dn);
        }

        return trim($rdn);
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function resolveDisplayName(array $entry, string $fallback): string
    {
        foreach (['ou', 'cn', 'dc'] as $attribute) {
            $values = $this->extractAttributeValues($entry, $attribute);
            if ($values !== []) {
                return $values[0];
            }
        }

        return $fallback;
    }

    /**
     * @param  array<int,string>  $objectClasses
     */
    private function classifyEntryType(array $objectClasses): string
    {
        $lower = array_map(static fn (string $value): string => strtolower($value), $objectClasses);

        foreach (['organizationalunit' => 'organizationalUnit', 'container' => 'container', 'domain' => 'domain', 'dcobject' => 'domainComponent'] as $needle => $label) {
            if (in_array($needle, $lower, true)) {
                return $label;
            }
        }

        if (in_array('person', $lower, true) || in_array('inetorgperson', $lower, true)) {
            return 'person';
        }

        if ($objectClasses === []) {
            return 'entry';
        }

        return $objectClasses[0];
    }

    /**
     * @param  array<int,string>  $objectClasses
     */
    private function hasChildHint(array $objectClasses): bool
    {
        $lower = array_map(static fn (string $value): string => strtolower($value), $objectClasses);

        return array_intersect($lower, ['organizationalunit', 'container', 'domain', 'ou', 'dcobject', 'organization']) !== [];
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    /**
     * @param  array<string,mixed>  $entry
     * @return array{
     *   dn: string,
     *   rdn: string,
     *   name: string,
     *   type: string,
     *   object_class: array<int,string>,
     *   has_children: bool
     * }|null
     */
    private function mapBrowseEntry(array $entry): ?array
    {
        $dnRaw = $entry['dn'] ?? null;
        if (! is_string($dnRaw)) {
            return null;
        }

        $dnTrimmed = trim($dnRaw);
        if ($dnTrimmed === '') {
            return null;
        }

        $objectClasses = array_values($this->extractAttributeValues($entry, 'objectclass'));
        /** @var array<int,string> $objectClasses */
        $rdn = $this->extractRdn($dnTrimmed);
        $name = $this->resolveDisplayName($entry, $rdn);

        return [
            'dn' => $dnTrimmed,
            'rdn' => $rdn,
            'name' => $name,
            'type' => $this->classifyEntryType($objectClasses),
            'object_class' => $objectClasses,
            'has_children' => $this->hasChildHint($objectClasses),
        ];
    }

    private function executeBrowseQuery(\LDAP\Connection $connection, string $baseDn): \LDAP\Result
    {
        /** @var mixed $result */
        $result = $this->withSuppressedWarnings(static fn () => ldap_list(
            $connection,
            $baseDn,
            self::DIRECTORY_BROWSE_FILTER,
            self::DIRECTORY_BROWSE_ATTRIBUTES,
            0,
            0,
            30,
            0
        ));

        if ($result instanceof \LDAP\Result) {
            return $result;
        }

        $diagnostics = $this->connectionDiagnostics($connection);
        $failure = $this->buildBrowseFailure($connection, $diagnostics);

        throw new LdapException($failure['message'], $failure['code']);
    }

    /**
     * @param  array{code?: int, error?: string, diagnostic_message?: string}  $diagnostics
     * @return array{message: string, code: int}
     */
    private function buildBrowseFailure(
        \LDAP\Connection $connection,
        array $diagnostics
    ): array {
        $message = 'Failed to browse LDAP directory';
        $codeValue = $diagnostics['code'] ?? null;
        $code = is_int($codeValue) ? $codeValue : 0;

        if ($code !== 0) {
            $message .= sprintf(' (code %d)', $code);
        }

        $errorText = $diagnostics['error'] ?? null;
        if ($errorText === null || $errorText === '') {
            $errorText = $this->lastError($connection);
        }
        if ($errorText !== '') {
            $message .= ': '.$errorText;
        }

        $diagMessage = $diagnostics['diagnostic_message'] ?? null;
        if (is_string($diagMessage) && $diagMessage !== '') {
            $message .= ' ['.$diagMessage.']';
        }

        return [
            'message' => $message,
            'code' => $code,
        ];
    }

    /**
     * @return non-empty-string
     */
    private function requireBrowseBaseDn(?string $baseDn): string
    {
        if ($baseDn === null) {
            throw new LdapException('Base DN must be provided when browsing child entries.');
        }

        $trimmed = trim($baseDn);
        if ($trimmed === '') {
            throw new LdapException('Base DN must be provided when browsing child entries.');
        }

        return $trimmed;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function hydrateBrowseEntries(\LDAP\Connection $connection, \LDAP\Result $result): array
    {
        /** @var mixed $entries */
        $entries = $this->withSuppressedWarnings(static fn () => ldap_get_entries($connection, $result));
        if (! is_array($entries)) {
            throw new LdapException('LDAP browse request returned an unexpected result.');
        }

        return $entries;
    }

    /**
     * @param  array<int|string,mixed>  $entries
     * @return list<array{
     *   dn: string,
     *   rdn: string,
     *   name: string,
     *   type: string,
     *   object_class: array<int,string>,
     *   has_children: bool
     * }>
     */
    private function mapBrowseEntries(array $entries): array
    {
        $count = isset($entries['count']) && is_int($entries['count']) ? $entries['count'] : 0;
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            /** @var mixed $current */
            $current = $entries[$i] ?? null;
            if (! is_array($current)) {
                continue;
            }

            /** @var array<string,mixed> $currentEntry */
            $currentEntry = $current;
            $mapped = $this->mapBrowseEntry($currentEntry);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        return $items;
    }

    /**
     * @return array{
     *   search: array{
     *     requested_dn: string,
     *     filter: string,
     *     scope: string,
     *     attributes: list<string>,
     *     returned: int
     *   },
     *   base_entry: array{
     *     dn: string,
     *     attributes: array<string,list<string>>,
     *     attribute_count: int,
     *     error?: string
     *   },
     *   diagnostic_message?: string
     * }
     */
    private function buildBrowseDiagnostics(\LDAP\Connection $connection, string $baseDn, int $count): array
    {
        $diagnostics = [
            'search' => [
                'requested_dn' => $baseDn,
                'filter' => self::DIRECTORY_BROWSE_FILTER,
                'scope' => 'onelevel',
                'attributes' => self::DIRECTORY_BROWSE_ATTRIBUTES,
                'returned' => $count,
            ],
        ];

        $diagnostics['base_entry'] = $this->summarizeBaseEntry($connection, $baseDn);

        $diagnosticMessage = $this->diagnosticMessage($connection);
        if ($diagnosticMessage !== null) {
            $diagnostics['diagnostic_message'] = $diagnosticMessage;
        }

        return $diagnostics;
    }

    private function intConstant(string $name): ?int
    {
        if (! defined($name)) {
            return null;
        }

        /** @var mixed $value */
        $value = constant($name);

        return is_int($value) ? $value : null;
    }

    private function withSuppressedWarnings(callable $operation): mixed
    {
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private function lastError(\LDAP\Connection $connection): string
    {
        $error = $this->withSuppressedWarnings(static fn (): string => ldap_error($connection));
        if (! is_string($error)) {
            return '';
        }

        return trim($error);
    }

    private function diagnosticMessage(\LDAP\Connection $connection): ?string
    {
        $option = $this->intConstant('LDAP_OPT_DIAGNOSTIC_MESSAGE');
        if ($option === null || ! function_exists('ldap_get_option')) {
            return null;
        }

        /** @var string|null $diagnostic */
        $diagnostic = null;
        $result = $this->withSuppressedWarnings(static fn (): bool => ldap_get_option($connection, $option, $diagnostic));
        if ($result !== true) {
            return null;
        }

        if (! is_string($diagnostic)) {
            return null;
        }

        $diagnostic = trim($diagnostic);

        return $diagnostic === '' ? null : $diagnostic;
    }

    /**
     * @return array{code?: int, error?: string, diagnostic_message?: string}
     */
    private function connectionDiagnostics(\LDAP\Connection $connection): array
    {
        $details = [];

        if (function_exists('ldap_errno')) {
            /** @var int|false $errno */
            $errno = $this->withSuppressedWarnings(static fn () => ldap_errno($connection));
            if (is_int($errno)) {
                $details['code'] = $errno;
            }
        }

        $error = $this->lastError($connection);
        if ($error !== '') {
            $details['error'] = $error;
        }

        $diagnostic = $this->diagnosticMessage($connection);
        if ($diagnostic !== null && $diagnostic !== '') {
            $details['diagnostic_message'] = $diagnostic;
        }

        return $details;
    }

    /**
     * @return array{
     *   dn: string,
     *   attributes: array<string,list<string>>,
     *   attribute_count: int,
     *   error?: string
     * }
     */
    private function summarizeBaseEntry(\LDAP\Connection $connection, string $baseDn): array
    {
        try {
            $entry = $this->readEntry($connection, $baseDn);
        } catch (LdapException $e) {
            return [
                'dn' => $baseDn,
                'attributes' => [],
                'attribute_count' => 0,
                'error' => $e->getMessage(),
            ];
        }

        $attributes = [];
        foreach (self::DIRECTORY_BROWSE_ATTRIBUTES as $attribute) {
            $values = $entry['attributes'][$attribute] ?? null;
            if (is_array($values) && $values !== []) {
                $attributes[$attribute] = $values;
            }
        }

        return [
            'dn' => $entry['dn'],
            'attributes' => $attributes,
            'attribute_count' => count($entry['attributes']),
        ];
    }
}
