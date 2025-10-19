<?php

declare(strict_types=1);

namespace App\Jobs\IntegrationBus;

use App\Integrations\Bus\IntegrationBusEnvelope;
use App\Services\IntegrationBus\IntegrationBusDispatcher;
use App\Support\Laravel\BusDispatchable;
use App\Support\Laravel\InteractsWithQueue;
use App\Support\Laravel\Queueable;
use App\Support\Laravel\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Primary Integration Bus worker job. Parses the envelope and forwards it to
 * the domain event dispatcher so modules can act on connector payloads.
 */
final class ProcessIntegrationBusMessage implements ShouldQueue
{
    use BusDispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly IntegrationBusEnvelope $envelope)
    {
        $this->onQueue('integration-bus');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(IntegrationBusEnvelope::fromArray($payload));
    }

    public function handle(IntegrationBusDispatcher $dispatcher): void
    {
        $dispatcher->dispatch($this->envelope);
    }
}
