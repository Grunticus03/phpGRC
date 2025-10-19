<?php

declare(strict_types=1);

namespace App\Services\Auth\Ldap;

use RuntimeException;

/**
 * Domain exception thrown when LDAP operations fail.
 */
final class LdapException extends RuntimeException {}
