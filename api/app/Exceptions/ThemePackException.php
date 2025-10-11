<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ThemePackException extends \RuntimeException
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(
        public readonly string $apiCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
