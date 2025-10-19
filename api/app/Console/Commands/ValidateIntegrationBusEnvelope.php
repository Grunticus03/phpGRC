<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Integrations\Bus\IntegrationBusValidator;
use Illuminate\Console\Command;

final class ValidateIntegrationBusEnvelope extends Command
{
    protected $signature = 'integration-bus:validate
        {envelope : Path to JSON file containing an Integration Bus envelope}
        {--headers= : Optional path to JSON file containing header key/value pairs}';

    protected $description = 'Validate Integration Bus envelopes against the contract and provenance header rules.';

    public function handle(IntegrationBusValidator $validator): int
    {
        $envelopeArg = $this->argument('envelope');
        /** @phpstan-ignore-next-line */
        if (! is_string($envelopeArg)) {
            $this->error('Envelope argument must be a single file path string.');

            return self::FAILURE;
        }

        $envelopePath = $envelopeArg;
        if ($envelopePath === '') {
            $this->error('Envelope argument must be a file path string.');

            return self::FAILURE;
        }
        if (! is_file($envelopePath)) {
            $this->error(sprintf('Envelope file not found: %s', $envelopePath));

            return self::FAILURE;
        }

        $envelopeData = $this->decodeJsonFile($envelopePath, 'envelope');
        if ($envelopeData === null) {
            return self::FAILURE;
        }
        if (! is_array($envelopeData)) {
            $this->error('Envelope JSON must decode to an object.');

            return self::FAILURE;
        }
        /** @var array<string,mixed> $envelope */
        $envelope = $envelopeData;

        $headers = [];
        $headerOption = $this->option('headers');
        $headerPath = is_string($headerOption) ? $headerOption : '';
        if ($headerPath !== '') {
            if (! is_file($headerPath)) {
                $this->error(sprintf('Headers file not found: %s', $headerPath));

                return self::FAILURE;
            }

            $headersData = $this->decodeJsonFile($headerPath, 'headers');
            if ($headersData === null) {
                return self::FAILURE;
            }
            if (! is_array($headersData)) {
                $this->error('Headers JSON must decode to an object.');

                return self::FAILURE;
            }
            /** @var array<string,mixed> $headers */
            $headers = $headersData;
        } else {
            $this->comment('No headers file provided; skipping header validation.');
        }

        $errors = $validator->validate($envelope, $headers);
        if ($errors !== []) {
            $this->error('Validation failed:');
            foreach ($errors as $error) {
                $this->line(sprintf(' - %s', $error));
            }

            return self::FAILURE;
        }

        $this->info('Integration Bus envelope validation passed.');

        return self::SUCCESS;
    }

    /**
     * @return mixed
     */
    private function decodeJsonFile(string $path, string $label)
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $this->error(sprintf('Unable to read %s file: %s', $label, $path));

            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (\JsonException $e) {
            $this->error(sprintf('Invalid JSON in %s file: %s', $label, $e->getMessage()));

            return null;
        }
    }
}
