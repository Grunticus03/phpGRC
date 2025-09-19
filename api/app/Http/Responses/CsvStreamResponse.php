<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response that preserves "Content-Type: text/csv" without an auto-appended charset.
 */
final class CsvResponse extends Response
{
    #[\Override]
    public function prepare(Request $request): static
    {
        // Ensure exact header; prevent charset injection.
        $this->headers->set('Content-Type', 'text/csv');
        // Suppress charset handling inside parent::prepare by clearing charset.
        /** @psalm-suppress InaccessibleProperty */
        $this->charset = null;

        return $this;
    }
}

