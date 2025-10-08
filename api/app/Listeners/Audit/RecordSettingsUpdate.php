<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\SettingsUpdated;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditCategories;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class RecordSettingsUpdate implements ShouldQueue
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SettingsUpdated $event): void
    {
        if (! config('core.audit.enabled', true)) {
            return;
        }
        if (! Schema::hasTable('audit_events')) {
            return;
        }

        /** @var array<int, array{key:string, old:mixed, new:mixed, action:string}> $changes */
        $changes = $event->changes;
        if ($changes === []) {
            return;
        }

        /** @var array<int, array{key:string, old:mixed, new:mixed, action:string}> $redacted */
        $redacted = $this->redactChanges($changes);

        $actorMeta = $this->resolveActorMeta($event->actorId, $event->context);

        foreach ($redacted as $index => $change) {
            $key = $change['key'];
            $entityId = $this->ensureNonEmpty($key);

            $changeType = $change['action'];

            $meta = array_merge(
                [
                    'source' => 'settings.apply',
                    'setting_key' => $key,
                    'setting_label' => $this->settingLabel($key),
                    'change_type' => $changeType,
                    'old_value' => $this->stringifyValue($change['old']),
                    'new_value' => $this->stringifyValue($change['new']),
                    'changes' => [$change],
                    'context' => Arr::except($event->context, ['ip', 'ua']),
                ],
                $actorMeta
            );

            try {
                /** @var mixed $ipRaw */
                $ipRaw = Arr::get($event->context, 'ip');
                $ip = is_string($ipRaw) && $ipRaw !== '' ? $ipRaw : null;
                /** @var mixed $uaRaw */
                $uaRaw = Arr::get($event->context, 'ua');
                $ua = is_string($uaRaw) && $uaRaw !== '' ? $uaRaw : null;

                $this->audit->log([
                    'actor_id' => $event->actorId,
                    'action' => 'setting.modified',
                    'category' => AuditCategories::SETTINGS,
                    'entity_type' => 'core.setting',
                    'entity_id' => $entityId,
                    'ip' => $ip,
                    'ua' => $ua,
                    'meta' => $meta,
                    'occurred_at' => $event->occurredAt,
                ]);
            } catch (\Throwable $e) {
                Log::warning('audit.write_failed', ['error' => $e->getMessage(), 'setting' => $key]);
            }
        }
    }

    /**
     * @param  array<int, array{key:string, old:mixed, new:mixed, action:string}>  $changes
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
                'key' => $key,
                'old' => $old,
                'new' => $new,
                'action' => $c['action'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,string>
     */
    private function resolveActorMeta(?int $actorId, array $context): array
    {
        $meta = [];

        /** @var mixed $ctxName */
        $ctxName = $context['actor_username'] ?? $context['actor_name'] ?? null;
        if (is_string($ctxName) && trim($ctxName) !== '') {
            $meta['actor_username'] = trim($ctxName);
        }

        /** @var mixed $ctxEmail */
        $ctxEmail = $context['actor_email'] ?? null;
        if (is_string($ctxEmail) && trim($ctxEmail) !== '') {
            $meta['actor_email'] = trim($ctxEmail);
        }

        if (($meta['actor_username'] ?? '') === '' || ($meta['actor_email'] ?? '') === '') {
            if (is_int($actorId)) {
                try {
                    /** @var User|null $actor */
                    $actor = User::query()->find($actorId, ['id', 'name', 'email']);
                    if ($actor instanceof User) {
                        /** @var mixed $nameAttr */
                        $nameAttr = $actor->getAttribute('name');
                        if (($meta['actor_username'] ?? '') === '' && is_string($nameAttr)) {
                            $name = trim($nameAttr);
                            if ($name !== '') {
                                $meta['actor_username'] = $name;
                            }
                        }
                        /** @var mixed $emailAttr */
                        $emailAttr = $actor->getAttribute('email');
                        if (($meta['actor_email'] ?? '') === '' && is_string($emailAttr)) {
                            $email = trim($emailAttr);
                            if ($email !== '') {
                                $meta['actor_email'] = $email;
                            }
                        }
                    }
                } catch (\Throwable) {
                    // ignore lookup failures
                }
            }
        }

        return $meta;
    }

    private function settingLabel(string $key): string
    {
        $trim = trim($key);
        if ($trim === '') {
            return 'setting';
        }

        if (str_starts_with($trim, 'core.')) {
            $trim = substr($trim, 5);
        }

        return $trim !== '' ? $trim : 'setting';
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\Throwable) {
                return '[unserializable]';
            }
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '[unserializable]';
    }

    /**
     * @return non-empty-string
     */
    private function ensureNonEmpty(string $value): string
    {
        $trim = trim($value);
        if ($trim !== '') {
            return $trim;
        }

        return (string) Str::ulid();
    }
}
