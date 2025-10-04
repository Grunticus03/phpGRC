<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class BreakGlassApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure container can resolve the dependency used by controller/middleware.
        $this->app->make(AuditLogger::class);
    }

    #[Test]
    public function returns_404_when_disabled(): void
    {
        Config::set('core.auth.break_glass.enabled', false);

        $this->postJson('/auth/break-glass', [])
            ->assertStatus(404)
            ->assertJson([
                'error' => 'BREAK_GLASS_DISABLED',
            ]);
    }

    #[Test]
    public function returns_202_stub_when_enabled(): void
    {
        Config::set('core.auth.break_glass.enabled', true);

        $this->postJson('/auth/break-glass', [])
            ->assertStatus(202)
            ->assertJson([
                'accepted' => true,
            ]);
    }
}
