<?php

declare(strict_types=1);

namespace App\Support\Laravel;

use Illuminate\Foundation\Bus\Dispatchable as LaravelDispatchable;

/**
 * Psalm cannot infer the constructor arguments forwarded by Laravel's
 * Dispatchable trait, so silence the mixed argument reports here.
 *
 * @psalm-suppress MixedArgument
 */
trait BusDispatchable
{
    use LaravelDispatchable;
}
