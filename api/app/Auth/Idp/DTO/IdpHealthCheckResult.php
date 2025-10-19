<?php

declare(strict_types=1);

namespace App\Auth\Idp\DTO;

use Carbon\CarbonImmutable;

/**
 * Value object describing the outcome of an IdP health check.
 */
final class IdpHealthCheckResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_ERROR = 'error';

    /**
     * @param  array<string,mixed>  $details
     */
    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $details,
        public readonly CarbonImmutable $checkedAt
    ) {}

    /**
     * Build a healthy result.
     *
     * @param  array<string,mixed>  $details
     */
    public static function healthy(string $message = 'Health check passed.', array $details = []): self
    {
        return new self(self::STATUS_OK, $message, $details, CarbonImmutable::now('UTC'));
    }

    /**
     * Build a warning result (non-fatal issues detected).
     *
     * @param  array<string,mixed>  $details
     */
    public static function warning(string $message, array $details = []): self
    {
        return new self(self::STATUS_WARNING, $message, $details, CarbonImmutable::now('UTC'));
    }

    /**
     * Build an error result.
     *
     * @param  array<string,mixed>  $details
     */
    public static function failed(string $message, array $details = []): self
    {
        return new self(self::STATUS_ERROR, $message, $details, CarbonImmutable::now('UTC'));
    }

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    /**
     * @return array{
     *   status: string,
     *   message: string,
     *   checked_at: string,
     *   details: array<string,mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'checked_at' => $this->checkedAt->toIso8601String(),
            'details' => $this->details,
        ];
    }
}
