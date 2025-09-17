<?php
declare(strict_types=1);

namespace App\Services\Setup;

use RuntimeException;

/**
 * Atomic writer for shared DB config file.
 * Follows CORE-001 atomic write protocol.
 */
final class ConfigFileWriter
{
    /**
     * @param array<string,mixed> $db
     */
    public function writeAtomic(array $db, string $targetPath): string
    {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create directory: ' . $dir);
            }
        }

        $tmp = $dir . '/.' . basename($targetPath) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $payload = "<?php\nreturn " . var_export([
            'driver'   => strtolower((string) $db['driver']),
            'host'     => (string) $db['host'],
            'port'     => (int) ($db['port'] ?? 3306),
            'database' => (string) $db['database'],
            'username' => (string) $db['username'],
            'password' => (string) ($db['password'] ?? ''),
            'charset'  => strtolower((string) ($db['charset'] ?? 'utf8mb4')),
            'collation'=> strtolower((string) ($db['collation'] ?? 'utf8mb4_unicode_ci')),
            'options'  => (array)  ($db['options'] ?? []),
        ], true) . ";\n";

        $fh = @fopen($tmp, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open temp file: ' . $tmp);
        }

        try {
            if (@fwrite($fh, $payload) === false) {
                throw new RuntimeException('Write failed');
            }
            if (!@fflush($fh)) {
                throw new RuntimeException('fflush failed');
            }
            if (!@fsync($fh)) {
                // fsync may not be available on all systems; ignore if not.
            }
        } finally {
            @fclose($fh);
        }

        if (!@chmod($tmp, 0640)) {
            // non-fatal
        }

        if (!@rename($tmp, $targetPath)) {
            @unlink($tmp);
            throw new RuntimeException('Atomic rename failed');
        }

        // fsync directory
        $dirh = @opendir($dir);
        if ($dirh) {
            @fsync($dirh);
            @closedir($dirh);
        }

        // Validation after write: require file parse
        $parsed = is_file($targetPath) ? (include $targetPath) : null;
        if (!is_array($parsed) || empty($parsed['driver']) || empty($parsed['host'])) {
            throw new RuntimeException('Post-write validation failed');
        }

        return $targetPath;
    }
}

