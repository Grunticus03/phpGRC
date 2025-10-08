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

        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';

        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

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
}
