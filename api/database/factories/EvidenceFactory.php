<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Evidence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class EvidenceFactory extends Factory
{
    protected $model = Evidence::class;

    public function definition(): array
    {
        $bytes = random_bytes(64);
        $filename = 'file_'.$this->faker->unique()->lexify('????????').'.bin';

        return [
            // null -> auto id via model boot (ev_<ULID>)
            'id'          => null,
            'owner_id'    => User::factory(),
            'filename'    => $filename,
            'mime'        => 'application/octet-stream',
            'size_bytes'  => strlen($bytes),
            'sha256'      => hash('sha256', $bytes),
            'version'     => 1,
            'bytes'       => $bytes,
            'created_at'  => now(),
            'updated_at'  => now(),
        ];
    }

    public function forUser(User $user): self
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function named(string $filename, string $mime = 'application/octet-stream'): self
    {
        return $this->state(fn () => [
            'filename' => $filename,
            'mime'     => $mime,
        ]);
    }
}
