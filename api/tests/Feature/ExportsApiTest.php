<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExportsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Force stub-only path regardless of migrated tables or other tests.
        config(['core.exports.enabled' => false]);
    }

    #[Test]
    public function create_type_accepts_csv_json_pdf_and_echoes_params(): void
    {
        foreach (['csv', 'json', 'pdf'] as $type) {
            $this->postJson("/exports/{$type}", ['params' => ['foo' => 'bar']])
                ->assertStatus(202)
                ->assertJsonPath('ok', true)
                ->assertJsonPath('jobId', 'exp_stub_0001')
                ->assertJsonPath('type', $type)
                ->assertJsonPath('note', 'stub-only')
                ->assertJsonPath('params.foo', 'bar');
        }
    }

    #[Test]
    public function create_legacy_accepts_type_in_body_and_echoes_params(): void
    {
        $this->postJson('/exports', [
            'type'   => 'csv',
            'params' => ['a' => 1],
        ])
            ->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('jobId', 'exp_stub_0001')
            ->assertJsonPath('type', 'csv')
            ->assertJsonPath('note', 'stub-only')
            ->assertJsonPath('params.a', 1);
    }

    #[Test]
    public function spec_create_type_defaults_params_to_empty_object(): void
    {
        $res = $this->postJson('/exports/json', []);

        $res->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('jobId', 'exp_stub_0001')
            ->assertJsonPath('type', 'json')
            ->assertJsonPath('note', 'stub-only');

        $this->assertSame([], $res->json('params'));
    }

    #[Test]
    public function create_type_validates_params_must_be_array(): void
    {
        $this->postJson('/exports/csv', ['params' => 'not-an-array'])
            ->assertStatus(422);
    }

    #[Test]
    public function legacy_create_validates_params_must_be_array(): void
    {
        $this->postJson('/exports', ['type' => 'csv', 'params' => 'nope'])
            ->assertStatus(422);
    }

    #[Test]
    public function create_rejects_unsupported_type_with_422(): void
    {
        $this->postJson('/exports/xml', ['params' => []])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'EXPORT_TYPE_UNSUPPORTED')
            ->assertJsonPath('note', 'stub-only');

        $this->postJson('/exports', ['type' => 'xls'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'EXPORT_TYPE_UNSUPPORTED')
            ->assertJsonPath('note', 'stub-only');
    }

    #[Test]
    public function status_returns_pending_progress_zero(): void
    {
        $this->getJson('/exports/exp_stub_0001/status')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('progress', 0)
            ->assertJsonPath('id', 'exp_stub_0001')
            ->assertJsonPath('jobId', 'exp_stub_0001')
            ->assertJsonPath('note', 'stub-only');
    }

    #[Test]
    public function download_always_404_not_ready_in_phase_4(): void
    {
        $this->getJson('/exports/exp_stub_0001/download')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'EXPORT_NOT_READY')
            ->assertJsonPath('jobId', 'exp_stub_0001')
            ->assertJsonPath('note', 'stub-only');
    }
}
