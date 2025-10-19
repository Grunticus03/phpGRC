<?php

declare(strict_types=1);

namespace App\Services\Auth\Ldap;

/**
 * LDAP client backed by PHP's native LDAP extension.
 * Designed so tests can swap in fakes without relying on the extension.
 *
 * @SuppressWarnings("PMD.CyclomaticComplexity")
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 */
final class NativeLdapClient implements LdapClientInterface
{
    private const USERNAME_PLACEHOLDER = '{{username}}';

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
        if (defined('LDAP_OPT_PROTOCOL_VERSION')) {
            $protocolOption = (int) constant('LDAP_OPT_PROTOCOL_VERSION');
            $this->setOption($connection, $protocolOption, 3);
        }

        if (defined('LDAP_OPT_REFERRALS')) {
            $referralOption = (int) constant('LDAP_OPT_REFERRALS');
            $this->setOption($connection, $referralOption, 0);
        }

        if (isset($config['timeout']) && is_int($config['timeout']) && defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            $timeoutOption = (int) constant('LDAP_OPT_NETWORK_TIMEOUT');
            $this->setOption($connection, $timeoutOption, $config['timeout']);
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

        $detail = $this->lastError($connection);
        if ($detail === '') {
            return $baseMessage;
        }

        return sprintf('%s %s', rtrim($baseMessage, '.'), $detail);
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
            $flag = defined('LDAP_ESCAPE_FILTER') ? (int) constant('LDAP_ESCAPE_FILTER') : 0;

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
            $flag = defined('LDAP_ESCAPE_DN') ? (int) constant('LDAP_ESCAPE_DN') : 0;

            return ldap_escape($value, '', $flag);
        }

        return str_replace(
            ['\\', ',', '+', '"', '<', '>', ';', '=', '#'],
            ['\\5c', '\\2c', '\\2b', '\\22', '\\3c', '\\3e', '\\3b', '\\3d', '\\23'],
            $value
        );
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
}
