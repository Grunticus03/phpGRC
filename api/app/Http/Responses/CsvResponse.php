<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response that keeps "Content-Type: text/csv" without appending a charset.
 */
final class CsvResponse extends Response
{
    #[\Override]
    public function prepare(Request $request): static
    {
        $this->headers->set('Content-Type', 'text/csv');
        /** @psalm-suppress InaccessibleProperty */
        $this->charset = null; // prevent "; charset=UTF-8"
        return $this;
    }
}

