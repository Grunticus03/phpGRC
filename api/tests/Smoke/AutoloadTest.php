<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function test_composer_autoload_exists(): void
    {
        $this->assertFileExists(__DIR__.'/../../vendor/autoload.php');
        $this->assertTrue(class_exists(\Composer\Autoload\ClassLoader::class));
    }
}
