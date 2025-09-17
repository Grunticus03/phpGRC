<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Phase 4: Export job record (scaffold).
 *
 * @property string $id
 * @property string $type
 * @property array<string,mixed>|null $params
 * @property string $status
 * @property int $progress
 * @property string|null $artifact_disk
 * @property string|null $artifact_path
 * @property string|null $artifact_mime
 * @property int|null $artifact_size
 * @property string|null $artifact_sha256
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property \Carbon\CarbonImmutable|null $failed_at
 * @property string|null $error_code
 * @property string|null $error_note
 */
final class Export extends Model
{
    protected $table = 'exports';

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    /** @var array<int, string> */
    protected $fillable = [
        'id',
        'type',
        'params',
        'status',
        'progress',
        'artifact_disk',
        'artifact_path',
        'artifact_mime',
        'artifact_size',
        'artifact_sha256',
        'created_at',
        'completed_at',
        'failed_at',
        'error_code',
        'error_note',
    ];

    /**
     * @phpstan-var array<string,string>
     * @psalm-var array<array-key, mixed>
     */
    protected $casts = [
        'params'        => 'array',
        'progress'      => 'integer',
        'artifact_size' => 'integer',
        'created_at'    => 'immutable_datetime',
        'completed_at'  => 'immutable_datetime',
        'failed_at'     => 'immutable_datetime',
    ];

    public static function newId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * @param array<string,mixed> $params
     */
    public static function createPending(string $type, array $params = []): self
    {
        return self::query()->create([
            'id'         => self::newId(),
            'type'       => $type,
            'params'     => $params,
            'status'     => 'pending',
            'progress'   => 0,
            'created_at' => now()->toImmutable(),
        ]);
    }

    public function markRunning(): void
    {
        $this->status = 'running';
        $this->progress = max((int) ($this->progress ?? 0), 10);
        $this->save();
    }

    public function markCompleted(): void
    {
        $this->status = 'completed';
        $this->progress = 100;
        $this->completed_at = now()->toImmutable();
        $this->save();
    }

    public function markFailed(string $code = 'INTERNAL_ERROR', string $note = ''): void
    {
        $this->status = 'failed';
        $this->progress = 0;
        $this->failed_at = now()->toImmutable();
        $this->error_code = $code;
        $this->error_note = $note;
        $this->save();
    }
}
