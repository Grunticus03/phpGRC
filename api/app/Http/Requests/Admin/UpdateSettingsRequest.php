<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 * @SuppressWarnings("PHPMD.ElseExpression")
 */
final class UpdateSettingsRequest extends FormRequest
{
    #[\Override]
    protected function prepareForValidation(): void
    {
        /** @var array<string,mixed>|null $core */
        $core = $this->input('core');

        if (is_array($core)) {
            /** @var array<string,mixed> $merge */
            $merge = [];

            foreach (['rbac', 'audit', 'evidence', 'avatars', 'metrics', 'ui', 'auth'] as $section) {
                if (array_key_exists($section, $core) && is_array($core[$section])) {
                    /** @var array<string,mixed> $sectionVal */
                    $sectionVal = $core[$section];
                    $merge[$section] = $sectionVal;
                }
            }

            if (array_key_exists('apply', $core)) {
                /** @var mixed $applyVal */
                $applyVal = $core['apply'];
                $applyNorm = is_bool($applyVal) ? $applyVal
                    : (is_int($applyVal) ? $applyVal === 1
                    : (is_string($applyVal) ? in_array(strtolower($applyVal), ['1', 'true', 'on', 'yes'], true) : null));
                if ($applyNorm !== null) {
                    $merge['apply'] = $applyNorm;
                }
            }

            if ($merge !== []) {
                $this->merge($merge);
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        /** @var list<string> $allowedMime */
        $allowedMime = [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'image/webp',
            'text/plain',
        ];

        return [
            'rbac' => ['sometimes', 'array'],
            'rbac.enabled' => ['sometimes', 'boolean'],
            'rbac.require_auth' => ['sometimes', 'boolean'],
            'rbac.roles' => ['sometimes', 'array', 'min:1'],
            'rbac.roles.*' => ['string', 'min:2', 'max:64'],
            'rbac.user_search' => ['sometimes', 'array'],
            'rbac.user_search.default_per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],

            'audit' => ['sometimes', 'array'],
            'audit.enabled' => ['sometimes', 'boolean'],
            'audit.retention_days' => ['sometimes', 'integer', 'min:1', 'max:730'],

            'evidence' => ['sometimes', 'array'],
            'evidence.enabled' => ['sometimes', 'boolean'],
            'evidence.max_mb' => ['sometimes', 'integer', 'min:1', 'max:4096'],
            'evidence.allowed_mime' => ['sometimes', 'array', 'min:1'],
            'evidence.allowed_mime.*' => ['string', 'in:'.implode(',', $allowedMime)],
            'evidence.blob_storage_path' => ['sometimes', 'string', 'max:4096'],

            'avatars' => ['sometimes', 'array'],
            'avatars.enabled' => ['sometimes', 'boolean'],
            'avatars.size_px' => ['sometimes', 'integer', 'in:128'],
            'avatars.format' => ['sometimes', 'string', 'in:webp'],

            'ui' => ['sometimes', 'array'],
            'ui.time_format' => ['sometimes', 'string', 'in:ISO_8601,LOCAL,RELATIVE'],

            // Metrics (DB-backed)
            'metrics' => ['sometimes', 'array'],
            'metrics.cache_ttl_seconds' => ['sometimes', 'integer', 'min:0'],     // 0 = disable
            'metrics.rbac_denies' => ['sometimes', 'array'],
            'metrics.rbac_denies.window_days' => ['sometimes', 'integer', 'min:7', 'max:365'],

            'auth' => ['sometimes', 'array'],
            'auth.saml' => ['sometimes', 'array'],
            'auth.saml.sp' => ['sometimes', 'array'],
            'auth.saml.sp.sign_authn_requests' => ['sometimes', 'boolean'],
            'auth.saml.sp.want_assertions_signed' => ['sometimes', 'boolean'],
            'auth.saml.sp.want_assertions_encrypted' => ['sometimes', 'boolean'],
            'auth.saml.sp.certificate' => ['sometimes', 'string'],
            'auth.saml.sp.private_key' => ['sometimes', 'string'],
            'auth.saml.sp.private_key_path' => ['sometimes', 'string'],
            'auth.saml.sp.private_key_passphrase' => ['sometimes', 'string'],

            'apply' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string,string> */
    #[\Override]
    public function messages(): array
    {
        return [
            'rbac.roles.min' => 'At least one role must be provided.',
            'rbac.roles.*.min' => 'Role names must be at least :min characters.',
            'rbac.roles.*.max' => 'Role names may not be greater than :max characters.',
            'rbac.user_search.default_per_page.min' => 'Per-page must be at least :min.',
            'rbac.user_search.default_per_page.max' => 'Per-page may not exceed :max.',
            'audit.retention_days.min' => 'Retention must be at least :min day.',
            'audit.retention_days.max' => 'Retention may not exceed :max days.',
            'evidence.max_mb.min' => 'Maximum size must be at least :min MB.',
            'evidence.max_mb.max' => 'Maximum size must be at most :max MB.',
            'evidence.allowed_mime.*.in' => 'One or more MIME types are not allowed.',
            'evidence.blob_storage_path.max' => 'Blob storage path may not exceed :max characters.',
            'avatars.size_px.in' => 'Avatar size must be 128px.',
            'avatars.format.in' => 'Avatar format must be WEBP.',
        ];
    }

    #[\Override]
    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string,list<string>> $flat */
        $flat = $validator->errors()->toArray();

        /**
         * @psalm-param array<string, list<string>> $src
         *
         * @psalm-return array<string, mixed>
         */
        $nest = static function (array $src): array {
            /** @var array<string,mixed> $out */
            $out = [];

            /** @var list<string> $messages */
            foreach ($src as $key => $messages) {
                /** @var string $key */
                $key = (string) $key;

                /** @var list<string> $errsList */
                $errsList = [];
                foreach ($messages as $message) {
                    $errsList[] = $message;
                }

                /** @var list<string> $parts */
                $parts = explode('.', $key);

                /** @var array<string,mixed> $ref */
                $ref = &$out;
                foreach ($parts as $i => $p) {
                    if ($i === count($parts) - 1) {
                        $ref[$p] = $errsList;
                    } else {
                        if (! isset($ref[$p]) || ! is_array($ref[$p])) {
                            $ref[$p] = [];
                        }
                        /** @var array<string,mixed> $child */
                        $child = &$ref[$p];
                        $ref = &$child;
                        unset($child);
                    }
                }
                unset($ref);
            }

            return $out;
        };

        /**
         * Collapse numeric-index children (int or numeric-string keys) into a list of strings.
         *
         * @psalm-param array<string,mixed> $node
         *
         * @psalm-return array<string,mixed>
         */
        $collapse = static function (array $node) use (&$collapse): array {
            /** @var array<string,mixed> $out */
            $out = [];

            /** @var mixed $v */
            foreach ($node as $k => $v) {
                /** @var string $k */
                if (is_array($v)) {
                    /** @var array<int|string,mixed> $vArr */
                    $vArr = $v;

                    /** @var list<int|string> $keys */
                    $keys = array_keys($vArr);

                    $allNumish = $keys !== [] && array_reduce(
                        $keys,
                        static fn (bool $acc, $kk): bool => $acc && (is_int($kk) || ctype_digit((string) $kk)),
                        true
                    );

                    if ($allNumish) {
                        /** @var list<string> $merged */
                        $merged = [];
                        /** @var mixed $vv */
                        foreach ($vArr as $vv) {
                            if (is_array($vv)) {
                                /** @var array<int|string,mixed> $vvArr */
                                $vvArr = $vv;
                                /** @var mixed $msg */
                                foreach ($vvArr as $msg) {
                                    if (is_string($msg)) {
                                        $merged[] = $msg;
                                    }
                                }
                            } elseif (is_string($vv)) {
                                $merged[] = $vv;
                            }
                        }
                        $out[$k] = $merged;
                    } else {
                        /** @var array<array-key,mixed> $assoc */
                        $assoc = $vArr;
                        /** @var array<string,mixed> $rec */
                        $rec = $collapse($assoc);
                        $out[$k] = $rec;
                    }
                } else {
                    if (is_string($v)) {
                        $out[$k] = $v;
                    } elseif (is_int($v) || is_float($v) || is_bool($v) || $v === null) {
                        $out[$k] = (string) $v;
                    } else {
                        $out[$k] = '';
                    }
                }
            }

            return $out;
        };

        $grouped = $collapse($nest($flat));

        $payload = [
            'ok' => false,
            'code' => 'VALIDATION_FAILED',
            'message' => 'Validation failed.',
            'errors' => $grouped,
        ];

        throw new HttpResponseException(response()->json($payload, 422));
    }
}
