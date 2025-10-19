<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->ensureWritableStoragePaths();

        $connection = $this->resolveTestConnection();
        $useSqlite = $connection === 'sqlite';

        if ($useSqlite) {
            $this->configureInMemorySqlite();
        }

        parent::setUp();

        if ($useSqlite) {
            config()->set('database.default', 'sqlite');
            config()->set('database.connections.sqlite.database', ':memory:');
        }

        // No global middleware tweaks yet.
    }

    private function ensureWritableStoragePaths(): void
    {
        $basePath = dirname(__DIR__);
        $paths = [
            $basePath.'/storage/framework/cache/data',
            $basePath.'/storage/framework/testing/disks/local',
        ];

        foreach ($paths as $path) {
            if (is_dir($path) && (! is_writable($path) || self::hasUnwritableDescendants($path))) {
                $suffix = '_unwritable_'.date('Ymd_His').'_'.self::randomSuffixToken();
                @rename($path, $path.$suffix);
            }

            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    private static function randomSuffixToken(): string
    {
        try {
            return bin2hex(random_bytes(2));
        } catch (\Throwable) {
            return (string) mt_rand(1000, 9999);
        }
    }

    private static function hasUnwritableDescendants(string $path): bool
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\Throwable) {
            return true;
        }

        foreach ($iterator as $item) {
            if (! is_writable($item->getPathname())) {
                return true;
            }
        }

        return false;
    }

    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function resolveTestConnection(): string
    {
        $candidates = [
            getenv('DB_CONNECTION') ?: null,
            $_SERVER['DB_CONNECTION'] ?? null,
            $_ENV['DB_CONNECTION'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return strtolower($candidate);
            }
        }

        return 'sqlite';
    }

    private function configureInMemorySqlite(): void
    {
        $settings = [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ];

        foreach ($settings as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
