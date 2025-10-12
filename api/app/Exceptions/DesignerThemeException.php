<?php

declare(strict_types=1);

namespace App\Exceptions;

final class DesignerThemeException extends \RuntimeException
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(
        public readonly string $apiCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $context = []
    ) {
        parent::__construct($message, $status);
    }
}
