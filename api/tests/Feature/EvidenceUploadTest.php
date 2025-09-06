<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class EvidenceUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prefer SQLite for tests if available; falls back to default connection.
        if (extension_loaded('pdo_sqlite')) {
            Config::set('database.default', 'sqlite');
            Config::set('database.connections.sqlite.database', database_path('database.sqlite'));
            if (! is_file(database_path('database.sqlite'))) {
                @touch(database_path('database.sqlite'));
            }
        }
    }

    public function test_upload_disabled_returns_400(): void
    {
        Config::set('core.evidence.enabled', false);

        $file = UploadedFile::fake()->createWithContent('small.txt', "hello\n", 'text/plain');

        $res = $this->postJson('/api/evidence', ['file' => $file]);

        $res->assertStatus(400)->assertJson([
            'ok' => false,
            'code' => 'EVIDENCE_NOT_ENABLED',
        ]);
    }

    public function test_upload_persists_and_returns_metadata(): void
    {
        Config::set('core.evidence.enabled', true);

        $content = "hello\n";
        $file = UploadedFile::fake()->createWithContent('small.txt', $content, 'text/plain');

        $res = $this->postJson('/api/evidence', ['file' => $file]);

        $res->assertCreated()
            ->assertJsonStructure(['ok','id','version','sha256','size','mime','name'])
            ->assertJson([
                'ok'   => true,
                'name' => 'small.txt',
                'size' => strlen($content),
            ]);

        $data = $res->json();
        $this->assertMatchesRegularExpression('/^ev_[0-9A-HJKMNP-TV-Z]{26}$/', $data['id']);

        $this->assertDatabaseHas('evidence', [
            'id'       => $data['id'],
            'filename' => 'small.txt',
            'version'  => 1,
        ]);
    }
}
