<?php

declare(strict_types=1);

namespace App\Support\Laravel;

use Illuminate\Queue\SerializesModels as LaravelSerializesModels;

/**
 * SerializesModels manipulates arbitrary model payloads which Psalm flags as
 * mixed assignments. Suppress those centrally.
 */
trait SerializesModels
{
    /**
     * @psalm-suppress MixedAssignment
     */
    use LaravelSerializesModels;
}
