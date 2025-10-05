<?php

declare(strict_types=1);

namespace App\Database;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Custom builder to JSON-encode `meta` for bulk insert methods.
 *
 * @extends EloquentBuilder<AuditEvent>
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class AuditEventBuilder extends EloquentBuilder
{
    public function __construct(QueryBuilder $query)
    {
        parent::__construct($query);
    }

    /**
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     */
    public function insert(array $values): bool
    {
        return parent::insert($this->encodeMeta($values));
    }

    /**
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     */
    public function insertOrIgnore(array $values): int
    {
        return parent::insertOrIgnore($this->encodeMeta($values));
    }

    /**
     * @param array<string,mixed> $values
     * @return mixed
     */
    public function insertGetId(array $values, ?string $sequence = null)
    {
        /** @var array<string,mixed> $row */
        $row = $this->encodeMeta($values);
        return parent::insertGetId($row, $sequence);
    }

    /**
     * @param array<int,array<string,mixed>>|array<string,mixed> $values
     * @return array<int,array<string,mixed>>|array<string,mixed>
     */
    private function encodeMeta(array $values): array
    {
        if ($values === []) {
            return $values;
        }

        if ($this->isAssoc($values)) {
            /** @var array<string,mixed> $values */
            return $this->encodeRow($values);
        }

        $out = [];
        /** @var array<int,array<string,mixed>> $values */
        foreach ($values as $row) {
            $out[] = $this->encodeRow($row);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeRow(array $row): array
    {
        if (array_key_exists('meta', $row)) {
            /** @var mixed $meta */
            $meta = $row['meta'];
            if ($meta === null) {
                $row['meta'] = null;
            } elseif (is_string($meta)) {
                // assume already JSON
                $row['meta'] = $meta;
            } elseif (is_array($meta) || is_object($meta) || is_scalar($meta)) {
                $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $row['meta'] = ($json !== false) ? $json : 'null';
            } else {
                $row['meta'] = 'null';
            }
        }
        return $row;
    }

    /**
     * @param array<mixed> $arr
     */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

