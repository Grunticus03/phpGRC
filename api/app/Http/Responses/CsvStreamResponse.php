<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvStreamResponse extends StreamedResponse
{
    #[\Override]
    public function prepare(Request $request): static
    {
        parent::prepare($request);
        // Force exact MIME without charset.
        $this->headers->set('Content-Type', 'text/csv', true);
        return $this;
    }
}

