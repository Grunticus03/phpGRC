<?php

declare(strict_types=1);

namespace App\Support\Laravel;

use Illuminate\Bus\Queueable as LaravelQueueable;

/**
 * Psalm struggles with the dynamic typing inside Laravel's Queueable trait.
 * Centralise the suppressions so our jobs can keep strict signatures.
 *
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedPropertyAssignment
 * @psalm-suppress MixedMethodCall
 * @psalm-suppress MixedPropertyFetch
 * @psalm-suppress MixedFunctionCall
 * @psalm-suppress MixedArgumentTypeCoercion
 */
trait Queueable
{
    use LaravelQueueable;
}
