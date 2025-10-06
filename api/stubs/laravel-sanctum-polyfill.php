<?php

declare(strict_types=1);

namespace Laravel\Sanctum;

if (! trait_exists(HasApiTokens::class)) {
    trait HasApiTokens {}
}
