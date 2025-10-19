<?php

declare(strict_types=1);

namespace App\Services\Auth\Ldap;

/**
 * Abstraction over LDAP operations so we can swap implementations and
 * provide deterministic fakes during automated tests.
 */
interface LdapClientInterface
{
    /**
     * Validate connectivity using the provided LDAP configuration.
     *
     * @param  array<string,mixed>  $config
     *
     * @throws LdapException if the connection or bind fails
     */
    public function checkConnection(array $config): void;

    /**
     * Attempt to authenticate a user and return the resolved entry metadata.
     *
     * @param  array<string,mixed>  $config  Normalized provider configuration.
     * @return array{dn:string,attributes:array<string,list<string>>}
     *
     * @throws LdapException when authentication fails or the user cannot be resolved
     */
    public function authenticate(array $config, string $username, string $password): array;
}
