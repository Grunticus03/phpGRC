<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed CSV response with exact "text/csv" Content-Type.
 * Also exposes getContent() for tests.
 */
final class CsvStreamResponse extends StreamedResponse
{
    /**
     * @param callable():void|null $callback
     * @param int $status
     * @param array<string,string> $headers
     */
    public function __construct(callable $callback = null, int $status = 200, array $headers = [])
    {
        parent::__construct($callback, $status, $headers);
        /** @psalm-suppress InaccessibleProperty */
        $this->content = '';
    }

    #[\Override]
    public function prepare(Request $request): static
    {
        $this->headers->set('Content-Type', 'text/csv');
        /** @psalm-suppress InaccessibleProperty */
        $this->charset = null;
        return parent::prepare($request);
    }

    #[\Override]
    public function getContent(): string
    {
        if (!is_callable($this->callback)) {
            return '';
        }

        ob_start();
        ($this->callback)();
        $out = ob_get_contents();
        ob_end_clean();

        return is_string($out) ? $out : '';
    }
}

