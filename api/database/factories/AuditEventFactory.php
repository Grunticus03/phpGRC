<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class AuditEventFactory extends Factory
{
    protected $model = AuditEvent::class;

    public function definition(): array
    {
        $ts = CarbonImmutable::now('UTC');

        return [
            'id' => (string) Str::ulid(),
            'occurred_at' => $ts,
            'actor_id' => null,
            'action' => 'test.event',
            'category' => 'TEST',
            'entity_type' => 'test',
            'entity_id' => (string) Str::ulid(),
            'ip' => '127.0.0.1',
            'ua' => 'phpunit',
            'meta' => [],
            'created_at' => $ts,
        ];
    }
}
