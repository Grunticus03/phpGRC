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

        /** @var array<int, array{key:string, old:mixed, new:mixed, action:string}> $changes */
        $changes = $event->changes;

        /** @var array<int, array{key:string, old:mixed, new:mixed, action:string}> $redacted */
        $redacted = $this->redactChanges($changes);

        $payload = [
            'id'          => (string) Str::ulid(),
            'occurred_at' => $event->occurredAt,
            'actor_id'    => $event->actorId,
            'action'      => 'settings.update',
            'category'    => 'config',
            'entity_type' => 'core.settings',
            'entity_id'   => 'core',
            'ip'          => Arr::get($event->context, 'ip'),
            'ua'          => Arr::get($event->context, 'ua'),
            'meta'        => [
                'source'       => 'settings.apply',
                'changes'      => $redacted,
                'touched_keys' => array_values(array_unique(array_map(
                    /**
                     * @param array{key:string, old:mixed, new:mixed, action:string} $c
                     */
                    static function (array $c): string {
                        return $c['key'];
                    },
                    $changes
                ))),
                'context'      => Arr::except($event->context, ['ip', 'ua']),
            ],
            'created_at'  => now('UTC'),
        ];

        try {
            AuditEvent::query()->create($payload);
        } catch (\Throwable $e) {
            Log::warning('audit.write_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<int, array{key:string, old:mixed, new:mixed, action:string}> $changes
     * @return array<int, array{key:string, old:mixed, new:mixed, action:string}>
     */
    private function redactChanges(array $changes): array
    {
        /** @var list<non-empty-string> $patterns */
        $patterns = [
            '/password/i',
            '/secret/i',
            '/token/i',
            '/api[_-]?key/i',
            '/client[_-]?secret/i',
            '/credential/i',
        ];

        $looksSensitive = static function (string $key) use ($patterns): bool {
            foreach ($patterns as $re) {
                /** @var non-empty-string $re */
                if (preg_match($re, $key) === 1) {
                    return true;
                }
            }
            return false;
        };

        $mask = static function (mixed $v): string {
            return '[REDACTED]';
        };

        /** @var array<int, array{key:string, old:mixed, new:mixed, action:string}> $out */
        $out = [];
        foreach ($changes as $c) {
            /** @var array{key:string, old:mixed, new:mixed, action:string} $c */
            $key = $c['key'];

            /** @var mixed $old */
            $old = $c['old'];
            /** @var mixed $new */
            $new = $c['new'];

            if ($looksSensitive($key)) {
                $old = $mask($old);
                $new = $mask($new);
            }

            $out[] = [
                'key'    => $key,
                'old'    => $old,
                'new'    => $new,
                'action' => $c['action'],
            ];
        }
        return $out;
    }
}

