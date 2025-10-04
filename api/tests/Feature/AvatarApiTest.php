<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AvatarApiTest extends TestCase
{
    #[Test]
    public function accepts_webp_and_returns_202_with_echoed_metadata(): void
    {
        $file = UploadedFile::fake()->image('avatar.webp', 64, 64);

        $res = $this->post('/avatar', ['file' => $file]);

        $res->assertStatus(202)
            ->assertJson([
                'ok'   => false,
                'note' => 'stub-only',
            ])
            ->assertJsonPath('file.original_name', 'avatar.webp')
            ->assertJsonPath('file.format', 'webp')
            ->assertJsonPath('file.width', 64)
            ->assertJsonPath('file.height', 64);
    }

    #[Test]
    public function rejects_non_webp_extension_with_422(): void
    {
        $file = UploadedFile::fake()->image('avatar.png', 64, 64);

        $this->post('/avatar', ['file' => $file])
            ->assertStatus(422);
    }

    #[Test]
    public function oversize_dimensions_are_accepted_in_phase4_stub(): void
    {
        $file = UploadedFile::fake()->image('big.webp', 1024, 1024);

        $res = $this->post('/avatar', ['file' => $file]);

        $res->assertStatus(202)
            ->assertJsonPath('file.width', 1024)
            ->assertJsonPath('file.height', 1024);
    }

    #[Test]
    public function disabled_via_config_returns_400(): void
    {
        Config::set('core.avatars.enabled', false);

        $file = UploadedFile::fake()->image('avatar.webp', 64, 64);

        $this->post('/avatar', ['file' => $file])
            ->assertStatus(400)
            ->assertJson([
                'ok'   => false,
                'code' => 'AVATAR_NOT_ENABLED',
                'note' => 'stub-only',
            ]);
    }
}
