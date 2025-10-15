<?php

declare(strict_types=1);

namespace App\Support\Laravel;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests as LaravelAuthorizesRequests;

/**
 * AuthorizesRequests forwards arbitrary ability strings/params to the Gate.
 * Keep Psalm strict elsewhere by suppressing the mixed warnings here.
 */
trait AuthorizesRequests
{
    /**
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArgumentTypeCoercion
     * @psalm-suppress ArgumentTypeCoercion
     * @psalm-suppress RiskyTruthyFalsyComparison
     */
    use LaravelAuthorizesRequests;
}
