<?php

declare(strict_types=1);

namespace App\Support\Laravel;

use Illuminate\Foundation\Validation\ValidatesRequests as LaravelValidatesRequests;

/**
 * Laravel's validation helpers interact with interface types that Psalm cannot
 * see the extended methods on; centralise the suppressions here.
 */
trait ValidatesRequests
{
    /**
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedArgumentTypeCoercion
     * @psalm-suppress UndefinedInterfaceMethod
     */
    use LaravelValidatesRequests;
}
