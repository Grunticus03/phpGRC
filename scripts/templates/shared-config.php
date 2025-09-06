<?php

declare(strict_types=1);

/**
 * Copy this to /opt/phpgrc/shared/config.php on the server.
 * Permissions: chown deploy:www-data; chmod 0640
 */
return [
    'db' => [
        'driver' => 'mysql',          // 'mysql' or 'sqlite'
        'host'   => '127.0.0.1',
        'port'   => 3306,
        'name'   => 'phpgrc',
        'user'   => 'phpgrc',
        'pass'   => 'CHANGE_ME',
        // Optional:
        // 'socket' => '/var/run/mysqld/mysqld.sock',
        // 'ssl_ca' => '/path/to/ca.pem',
        // If driver='sqlite':
        // 'sqlite_path' => '/var/www/phpgrc/shared/database.sqlite',
    ],
];
