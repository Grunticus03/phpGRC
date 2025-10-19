<?php

namespace LDAP {
    class Connection {}

    class Result {}
}

namespace {
    const LDAP_OPT_PROTOCOL_VERSION = 17;
    const LDAP_OPT_REFERRALS = 8;
    const LDAP_OPT_NETWORK_TIMEOUT = 20485;
    const LDAP_ESCAPE_FILTER = 1;
    const LDAP_ESCAPE_DN = 2;

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function ldap_connect(string $uri): \LDAP\Connection|false {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function ldap_set_option(\LDAP\Connection $connection, int $option, mixed $value): bool {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function ldap_start_tls(\LDAP\Connection $connection): bool {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function ldap_bind(\LDAP\Connection $connection, ?string $dn = null, ?string $password = null): bool {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function ldap_search(
        \LDAP\Connection $connection,
        string $baseDn,
        string $filter,
        array $attributes = [],
        int $attrsonly = 0,
        int $sizelimit = 0
    ): \LDAP\Result|false {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function ldap_read(
        \LDAP\Connection $connection,
        string $baseDn,
        string $filter,
        array $attributes = [],
        int $attrsonly = 0,
        int $sizelimit = 0
    ): \LDAP\Result|false {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function ldap_get_entries(\LDAP\Connection $connection, \LDAP\Result $result): array|false {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function ldap_unbind(\LDAP\Connection $connection): bool {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function ldap_escape(string $value, string $ignore = '', int $flags = 0): string {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function ldap_error(\LDAP\Connection $connection): string {}
}
