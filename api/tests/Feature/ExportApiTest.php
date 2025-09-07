<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ExportApiTest extends TestCase
{
    /** @test */
    public function legacy_create_accepts_valid_types_and_returns_202(): void
    {
        foreach (['csv', 'json', 'pdf'] as $type) {
            $res = $this->postJson('/api/exports', [
                'type'   => $type,
                'params' => ['foo' => 'bar'],
            ]);

            $res->assertStatus(202)
                ->assertJson([
                    'ok'    => true,
                    'jobId' => 'exp_stub_0001',
                    'type'  => $type,
                    'note'  => 'stub-only',
                ])
                ->assertJsonPath('params.foo', 'bar');
        }
    }

    /** @test */
    public function legacy_create_rejects_unsupported_type(): void
    {
        $this->postJson('/api/exports', ['type' => 'xml'])
            ->assertStatus(422)
            ->assertJson([
                'ok'   => false,
                'code' => 'EXPORT_TYPE_UNSUPPORTED',
                'note' => 'stub-only',
            ]);
    }

    /** @test */
    public function spec_create_type_accepts_valid_types_and_returns_202(): void
    {
        foreach (['csv', 'json', 'pdf'] as $type) {
            $res = $this->postJson("/api/exports/{$type}", [
                'params' => ['a' => 1],
            ]);

            $res->assertStatus(202)
                ->assertJson([
                    'ok'    => true,
                    'jobId' => 'exp_stub_0001',
                    'type'  => $type,
                    'note'  => 'stub-only',
                ])
                ->assertJsonPath('params.a', 1);
        }
    }

    /** @test */
    public function spec_create_type_defaults_params_to_empty_object(): void
    {
        $res = $this->postJson('/api/exports/json', []);

        $res->assertStatus(202)
            ->assertJson([
                'ok'    => true,
                'jobId' => 'exp_stub_0001',
                'type'  => 'json',
                'note'  => 'stub-only',
            ]);

        // Laravel decodes JSON objects to arrays in ->json().
        $this->assertSame([], $res->json('params'));
    }

    /** @test */
    public function create_type_validates_params_must_be_array(): void
    {
        $this->postJson('/api/exports/csv', ['params' => 'not-an-array'])
            ->assertStatus(422);
    }

    /** @test */
    public function status_returns_pending_with_ids_echoed(): void
    {
        $jobId = 'exp_test_123';
        $this->getJson("/api/exports/{$jobId}/status")
            ->assertOk()
            ->assertJson([
                'ok'       => true,
                'status'   => 'pending',
                'progress' => 0,
                'id'       => $jobId,
                'jobId'    => $jobId,
                'note'     => 'stub-only',
            ]);
    }

    /** @test */
    public function download_returns_404_not_ready(): void
    {
        $jobId = 'exp_test_404';
        $this->getJson("/api/exports/{$jobId}/download")
            ->assertStatus(404)
            ->assertJson([
                'ok'   => false,
                'code' => 'EXPORT_NOT_READY',
                'note' => 'stub-only',
                'jobId'=> $jobId,
            ]);
    }
}
