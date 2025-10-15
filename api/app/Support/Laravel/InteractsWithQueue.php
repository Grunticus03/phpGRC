<?php

declare(strict_types=1);

namespace App\Support\Laravel;

use Illuminate\Queue\InteractsWithQueue as LaravelInteractsWithQueue;

/**
 * Wrap Laravel's InteractsWithQueue trait to suppress Psalm noise about mixed
 * queue job internals while keeping the behaviour unchanged.
 */
trait InteractsWithQueue
{
    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedArgumentTypeCoercion
     * @psalm-suppress MixedMethodCall
     * @psalm-suppress MixedOperand
     * @psalm-suppress MixedPropertyAssignment
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress InvalidPropertyAssignmentValue
     * @psalm-suppress PossiblyInvalidArgument
     * @psalm-suppress PossiblyInvalidCast
     * @psalm-suppress PossiblyNullPropertyFetch
     * @psalm-suppress PossiblyNullReference
     * @psalm-suppress NoInterfaceProperties
     */
    use LaravelInteractsWithQueue;
}
