<?php

declare(strict_types=1);

namespace App\Support\Laravel;

use Illuminate\Foundation\Events\Dispatchable as LaravelEventDispatchable;

/**
 * Event dispatch helpers rely on mixed inputs; suppress Psalm's mixed argument
 * complaints without weakening our event definitions.
 */
trait EventDispatchable
{
    /**
     * @psalm-suppress MixedArgument
     */
    use LaravelEventDispatchable;
}
