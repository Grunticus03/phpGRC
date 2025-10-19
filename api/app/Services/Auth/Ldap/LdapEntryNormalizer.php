<?php

declare(strict_types=1);

namespace App\Services\Auth\Ldap;

/**
 * Normalizes raw LDAP entries into a predictable structure.
 */
final class LdapEntryNormalizer
{
    /**
     * @param  array<string,mixed>  $entry
     * @return array{dn:string,attributes:array<string,list<string>>}
     */
    public function normalize(array $entry): array
    {
        $dn = $this->extractDistinguishedName($entry);
        $attributes = [];

        foreach ($entry as $key => $rawValue) {
            /** @var mixed $rawValue */
            if (! $this->shouldIncludeAttribute($key, $rawValue)) {
                continue;
            }

            if (! is_string($rawValue) && ! is_array($rawValue)) {
                continue;
            }

            $value = $rawValue;
            $normalizedKey = strtolower($key);
            $values = $this->normalizeAttributeValues($value);
            if ($values === []) {
                continue;
            }

            $attributes[$normalizedKey] = $values;
        }

        return [
            'dn' => $dn,
            'attributes' => $attributes,
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function extractDistinguishedName(array $entry): string
    {
        $dn = $entry['dn'] ?? null;
        if (! is_string($dn) || trim($dn) === '') {
            throw new LdapException('LDAP entry is missing a distinguished name.');
        }

        return trim($dn);
    }

    private function shouldIncludeAttribute(mixed $key, mixed $value): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $normalizedKey = strtolower($key);
        if (in_array($normalizedKey, ['dn', 'count'], true)) {
            return false;
        }

        return is_array($value) || is_string($value);
    }

    /**
     * @return list<string>
     */
    private function normalizeAttributeValues(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? [] : [$trimmed];
        }

        if (! is_array($value)) {
            return [];
        }

        $values = [];
        /** @var array<array-key, mixed> $value */
        foreach ($value as $attributeKey => $attributeValue) {
            if ($attributeKey === 'count') {
                continue;
            }

            if (! is_string($attributeValue) || $attributeValue === '') {
                continue;
            }

            $trimmed = trim($attributeValue);
            if ($trimmed === '') {
                continue;
            }

            $values[] = $trimmed;
        }

        return $values;
    }
}
