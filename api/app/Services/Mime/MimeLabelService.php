<?php

declare(strict_types=1);

namespace App\Services\Mime;

use App\Models\MimeLabel;
use Illuminate\Support\Facades\Schema;

final class MimeLabelService
{
    private const LIKE_ESCAPE = '!';

    private const TYPE_DEFAULTS = [
        'image' => 'Image',
        'audio' => 'Audio',
        'video' => 'Video',
        'text' => 'Text document',
        'application' => 'Application file',
        'font' => 'Font file',
        'model' => '3D model',
        'message' => 'Email message',
    ];

    /** @var array<string,string>|null */
    private ?array $exactCache = null;

    /** @var list<array{value:string,label:string}>|null */
    private ?array $prefixCache = null;

    /**
     * @param  array<int,string>  $mimes
     * @return array<string,string>
     */
    public function labelsFor(array $mimes): array
    {
        $result = [];
        $localCache = [];

        foreach ($mimes as $raw) {
            $key = strtolower(trim($raw));
            if ($key === '') {
                continue;
            }

            if (! array_key_exists($key, $localCache)) {
                $localCache[$key] = $this->labelFor($raw);
            }

            $result[$raw] = $localCache[$key];
        }

        return $result;
    }

    /**
     * @return list<array{match_type:'exact'|'prefix',value:string}>
     */
    public function matchesForLabel(string $label): array
    {
        $term = strtolower(trim($label));
        if ($term === '') {
            return [];
        }

        if (! Schema::hasTable('mime_labels')) {
            return [];
        }

        $escaped = $this->escapeForLike($term);

        $escapeChar = self::LIKE_ESCAPE;

        /** @var list<MimeLabel> $rows */
        $rows = MimeLabel::query()
            ->select(['value', 'match_type'])
            ->whereRaw("LOWER(label) LIKE ? ESCAPE '".$escapeChar."'", ['%'.$escaped.'%'])
            ->get();

        $matches = [];
        foreach ($rows as $row) {
            $matchType = strtolower($row->match_type);
            if ($matchType !== 'exact' && $matchType !== 'prefix') {
                continue;
            }

            $value = strtolower(trim($row->value));
            if ($value === '') {
                continue;
            }

            $matches[] = [
                'match_type' => $matchType === 'prefix' ? 'prefix' : 'exact',
                'value' => $value,
            ];
        }

        usort(
            $matches,
            /**
             * @param  array{match_type:'exact'|'prefix',value:string}  $a
             * @param  array{match_type:'exact'|'prefix',value:string}  $b
             */
            static fn (array $a, array $b): int => strlen($b['value']) <=> strlen($a['value'])
        );

        return $matches;
    }

    public function labelFor(string $rawMime): string
    {
        $normalized = strtolower(trim($rawMime));
        if ($normalized === '') {
            return 'Unknown';
        }

        $this->loadCaches();

        if (isset($this->exactCache[$normalized])) {
            return $this->exactCache[$normalized];
        }

        $prefixCache = $this->prefixCache ?? [];
        foreach ($prefixCache as $entry) {
            if (str_starts_with($normalized, $entry['value'])) {
                return $entry['label'];
            }
        }

        return $this->fallbackLabel($rawMime);
    }

    private function loadCaches(): void
    {
        if ($this->exactCache !== null && $this->prefixCache !== null) {
            return;
        }

        if (! Schema::hasTable('mime_labels')) {
            $this->exactCache = [];
            $this->prefixCache = [];

            return;
        }

        $exact = [];
        $prefix = [];

        /** @var list<MimeLabel> $rows */
        $rows = MimeLabel::query()->get(['value', 'match_type', 'label']);
        foreach ($rows as $row) {
            $matchType = strtolower($row->match_type);
            $value = strtolower(trim($row->value));
            if ($value === '') {
                continue;
            }

            $label = $row->label;

            if ($matchType === 'prefix') {
                $prefix[] = ['value' => $value, 'label' => $label];
            } else {
                $exact[$value] = $label;
            }
        }

        usort(
            $prefix,
            /**
             * @param  array{value:string,label:string}  $a
             * @param  array{value:string,label:string}  $b
             */
            static fn (array $a, array $b): int => strlen($b['value']) <=> strlen($a['value'])
        );

        $this->exactCache = $exact;
        $this->prefixCache = $prefix;
    }

    private function fallbackLabel(string $rawMime): string
    {
        $normalized = strtolower(trim($rawMime));
        if ($normalized === '') {
            return 'Unknown';
        }

        $parts = explode('/', $normalized);
        if (count($parts) !== 2) {
            return $rawMime;
        }

        [$type, $subtypeRaw] = $parts;
        $typeDefault = self::TYPE_DEFAULTS[$type] ?? null;

        $cleanedSubtype = preg_replace('/^vnd\./', '', $subtypeRaw) ?? $subtypeRaw;
        $cleanedSubtype = preg_replace('/\+xml$/', ' XML', $cleanedSubtype) ?? $cleanedSubtype;
        $cleanedSubtype = preg_replace('/\+json$/', ' JSON', $cleanedSubtype) ?? $cleanedSubtype;
        $cleanedSubtype = preg_replace('/\+zip$/', ' ZIP', $cleanedSubtype) ?? $cleanedSubtype;
        $cleanedSubtype = preg_replace('/\+octet-stream$/', ' binary', $cleanedSubtype) ?? $cleanedSubtype;
        $cleanedSubtype = preg_replace('/[._-]+/', ' ', $cleanedSubtype) ?? $cleanedSubtype;
        $cleanedSubtype = trim($cleanedSubtype);

        if ($cleanedSubtype === '') {
            return $typeDefault ?? $rawMime;
        }

        $subtypeLabel = $this->capitalizeWords($cleanedSubtype);

        if ($typeDefault !== null) {
            return $subtypeLabel.' '.strtolower($typeDefault);
        }

        return $subtypeLabel;
    }

    private function capitalizeWords(string $value): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $value) ?: [];
        $parts = array_filter($parts, static fn (string $part): bool => $part !== '');

        if ($parts === []) {
            return $value;
        }

        $parts = array_map(static fn (string $part): string => ucfirst($part), $parts);

        return implode(' ', $parts);
    }

    private function escapeForLike(string $value): string
    {
        $escape = self::LIKE_ESCAPE;

        return str_replace(
            [$escape, '%', '_'],
            [$escape.$escape, $escape.'%', $escape.'_'],
            $value
        );
    }
}
