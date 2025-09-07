<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

final class AuditApiTest extends TestCase
{
    /** @test */
    public function stub_happy_path_returns_items_and_metadata(): void
    {
        $res = $this->getJson('/api/audit');

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('note', 'stub-only')
            ->assertJsonStructure([
                'items' => [
                    ['id','occurred_at','actor_id','action','category','entity_type','entity_id','ip','ua','meta'],
                ],
                '_categories',
                '_retention_days',
                'nextCursor',
            ]);

        // Stub ships two events.
        $this->assertCount(2, $res->json('items'));
        $this->assertContains('AUTH', $res->json('_categories'));
        $this->assertIsInt($res->json('_retention_days'));
        $this->assertNull($res->json('nextCursor'));
    }

    /** @test */
    public function limit_within_bounds_returns_that_many_items(): void
    {
        // limit=1 → exactly 1 item from stub set
        $this->getJson('/api/audit?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'items');
    }

    /** @test */
    public function limit_below_min_is_422(): void
    {
        $res = $this->getJson('/api/audit?limit=0');

        $res->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertSame(
            ['The limit field must be between 1 and 100.'],
            Arr::get($res->json(), 'errors.limit')
        );
    }

    /** @test */
    public function limit_above_max_is_422(): void
    {
        $this->getJson('/api/audit?limit=101')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    /** @test */
    public function cursor_with_invalid_chars_is_422(): void
    {
        $this->getJson('/api/audit?cursor=bad$cursor!')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['cursor']]);
    }

    /** @test */
    public function cursor_valid_format_is_accepted_and_response_ok(): void
    {
        $cursor = $this->makeCursor('2025-09-05T12:05:00Z', 'ae_0002');

        $this->getJson('/api/audit?cursor=' . $cursor)
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    private function makeCursor(string $isoTs, string $id): string
    {
        $j = json_encode(['ts' => $isoTs, 'id' => $id], JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($j), '+/', '-_'), '=');
    }
}
