<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\SettingsUpdated;
use App\Models\AuditEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class RecordSettingsUpdate implements ShouldQueue
{
    public function handle(SettingsUpdated $event): void
    {
        if (!config('core.audit.enabled', true)) {
            return;
        }
        if (!Schema::hasTable('audit_events')) {
            return;
        }

        $safeChanges = $this->redactChanges($event->changes);

        $payload = [
            'id'          => (string) Str::ulid(), // 26-char ULID fits column
            'occurred_at' => $event->occurredAt,
            'actor_id'    => $event->actorId,
            'action'      => 'settings.update',
            'category'    => 'SETTINGS',
            'entity_type' => 'core.settings',
            'entity_id'   => 'core',
            'ip'          => Arr::get($event->context, 'ip'),
            'ua'          => Arr::get($event->context, 'ua'),
            'meta'        => [
                'source'       => 'settings.apply',
                'changes'      => $safeChanges,
                'touched_keys' => array_values(array_unique(array_map(
                    static fn (array $c): string => $c['key'],
                    $safeChanges
                ))),
                'context'      => Arr::except($event->context, ['ip', 'ua']),
            ],
            'created_at'  => now('UTC'),
        ];

        try {
            AuditEvent::query()->create($payload);
        } catch (\Throwable $e) {
            // Never fail the API due to audit sink issues.
            Log::warning('audit.write_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<int, array{key:string, old:mixed, new:mixed, action:string}> $changes
     * @return array<int, array{key:string, old:mixed, new:mixed, action:string}>
     */
    private function redactChanges(array $changes): array
    {
        /** @var array<int, array{key:string, old:mixed, new:mixed, action:string}> $out */
        $out = [];
        foreach ($changes as $c) {
            $key = $c['key'];
            $out[] = [
                'key'    => $key,
                'old'    => $this->maybeRedact($key, $c['old']),
                'new'    => $this->maybeRedact($key, $c['new']),
                'action' => $c['action'],
            ];
        }
        return $out;
    }

    private function maybeRedact(string $key, mixed $value): mixed
    {
        $k = strtolower($key);
        $sensitive = str_contains($k, 'password')
            || str_contains($k, 'secret')
            || str_contains($k, 'token')
            || str_contains($k, 'api_key')
            || str_contains($k, 'client_secret')
            || str_contains($k, 'private_key');

        if (!$sensitive) {
            return $value;
        }

        // Preserve type hints minimally while hiding contents.
        if (is_array($value)) {
            return '[REDACTED]';
        }
        if (is_bool($value)) {
            return '[REDACTED]';
        }
        if (is_int($value) || is_float($value)) {
            return '[REDACTED]';
        }
        if ($value === null) {
            return null;
        }
        return '***';
    }
}

