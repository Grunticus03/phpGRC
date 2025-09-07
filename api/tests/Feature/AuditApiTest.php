<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AuditApiTest extends TestCase
{
    public function test_stub_fallback_when_table_missing(): void
    {
        // Force stub path
        Schema::shouldReceive('hasTable')->with('audit_events')->andReturn(false);

        $this->getJson('/api/audit')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'note' => 'stub-only',
            ])
            ->assertJsonStructure(['items','_categories','_retention_days']);
    }

    public function test_pagination_params_validated(): void
    {
        $this->getJson('/api/audit?limit=0')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_ok_when_no_cursor(): void
    {
        $this->getJson('/api/audit')
            ->assertOk()
            ->assertJsonStructure(['ok','items','_categories','_retention_days']);
    }
}
