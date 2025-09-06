<?php

declare(strict_types=1);

/**
 * Database configuration for phpGRC (Laravel 11).
 * Prefers shared config at /opt/phpgrc/shared/config.php.
 * Falls back to .env, then to SQLite.
 */

$shared = [];
$sharedPath = '/opt/phpgrc/shared/config.php';
if (is_file($sharedPath)) {
    /** @var mixed $loaded */
    $loaded = require $sharedPath;
    if (is_array($loaded)) {
        $shared = $loaded;
    }
}

$dbc = $shared['db'] ?? [];

$defaultDriver = $dbc['driver'] ?? env('DB_CONNECTION', 'sqlite');

return [

    'default' => $defaultDriver,

    'connections' => [

        'sqlite' => [
            'driver'                  => 'sqlite',
            'url'                     => env('DATABASE_URL'),
            'database'                => $dbc['sqlite_path'] ?? database_path('database.sqlite'),
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ],

        'mysql' => [
            'driver'         => 'mysql',
            'url'            => env('DATABASE_URL'),
            'host'           => $dbc['host'] ?? env('DB_HOST', '127.0.0.1'),
            'port'           => (string) ($dbc['port'] ?? env('DB_PORT', '3306')),
            'database'       => $dbc['name'] ?? env('DB_DATABASE', 'phpgrc'),
            'username'       => $dbc['user'] ?? env('DB_USERNAME', 'phpgrc'),
            'password'       => $dbc['pass'] ?? env('DB_PASSWORD', ''),
            'unix_socket'    => $dbc['socket'] ?? env('DB_SOCKET'),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_0900_ai_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => null,
            'options'        => extension_loaded('pdo_mysql') ? array_filter([
                \PDO::MYSQL_ATTR_SSL_CA => $dbc['ssl_ca'] ?? null,
            ]) : [],
        ],

        'pgsql' => [
            'driver'         => 'pgsql',
            'url'            => env('DATABASE_URL'),
            'host'           => $dbc['pg_host'] ?? env('PG_HOST', '127.0.0.1'),
            'port'           => (string) ($dbc['pg_port'] ?? env('PG_PORT', '5432')),
            'database'       => $dbc['pg_db'] ?? env('PG_DATABASE', 'phpgrc'),
            'username'       => $dbc['pg_user'] ?? env('PG_USERNAME', 'phpgrc'),
            'password'       => $dbc['pg_pass'] ?? env('PG_PASSWORD', ''),
            'charset'        => 'utf8',
            'prefix'         => '',
            'prefix_indexes' => true,
            'search_path'    => 'public',
            'sslmode'        => 'prefer',
        ],

    ],

    'migrations' => 'migrations',

    // Redis left default; can be configured via config/cache.php if needed.
];
