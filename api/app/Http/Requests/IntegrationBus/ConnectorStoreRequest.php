<?php

declare(strict_types=1);

namespace App\Http\Requests\IntegrationBus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConnectorStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'required',
                'string',
                'min:3',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('integration_connectors', 'key'),
            ],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'kind' => [
                'required',
                'string',
                Rule::in([
                    'asset.discovery',
                    'asset.lifecycle',
                    'incident.event',
                    'vendor.profile',
                    'indicator.metric',
                    'cyber.metric',
                    'auth.provider',
                ]),
            ],
            'enabled' => ['sometimes', 'boolean'],
            'config' => ['required', 'array'],
            'meta' => ['sometimes', 'nullable', 'array'],
            'last_health_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @param  array|int|string|null  $key
     * @param  mixed  $default
     *
     * @phpstan-param array<string>|int|string|null $key
     *
     * @psalm-param array|int|string|null $key
     *
     * @return array<string,mixed>
     */
    #[\Override]
    public function validated($key = null, $default = null): array
    {
        /** @var array<string,mixed> $data */
        $data = parent::validated($key, $default);

        if (array_key_exists('enabled', $data)) {
            $data['enabled'] = (bool) $data['enabled'];
        }

        if (array_key_exists('config', $data) && is_array($data['config'])) {
            /** @var array<string,mixed> $config */
            $config = $data['config'];
            $data['config'] = $config;
        }

        if (array_key_exists('meta', $data)) {
            /** @var mixed $meta */
            $meta = $data['meta'];
            $data['meta'] = $meta === null ? null : (array) $meta;
        }

        return $data;
    }
}
