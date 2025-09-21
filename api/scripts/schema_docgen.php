<?php
#!/usr/bin/env php
declare(strict_types=1);

/**
 * phpGRC Schema Doc Generator
 * Renders a deterministic Markdown snapshot of the current MySQL schema.
 * Output is compared to docs/db/SCHEMA.md in CI to detect drift.
 *
 * Usage (from /api): php scripts/schema_docgen.php > ../docs/db/schema.live.md
 */

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** @var \Illuminate\Database\Connection $conn */
$conn = DB::connection('mysql');
$db   = $conn->getDatabaseName();

$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

echo "# phpGRC Database Schema\n\n";
echo "Snapshot generated from migrations against **{$db}** as of {$now} (UTC).\n\n";
echo "- SQL dialect: MySQL 8.0+\n";
echo "- All times UTC.\n\n";
echo "---\n\n";
echo "## Tables\n\n";

// Skip framework/infra tables we do not document in SCHEMA.md
$skip = [
    'migrations',
    'failed_jobs',
    'jobs',
    'job_batches',
    'password_reset_tokens',
    'cache',
    'cache_locks',
    'sessions',
    'telescope_entries',
    'telescope_entries_tags',
    'telescope_monitoring',
    'personal_access_tokens',
];

/** @var array<int, string> $tables */
$tables = array_map(
    fn($r) => (string)$r->TABLE_NAME,
    $conn->select(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME ASC",
        [$db]
    )
);

// Filter skipped tables
$tables = array_values(array_filter($tables, fn(string $t) => !in_array($t, $skip, true)));

foreach ($tables as $table) {
    echo "### `{$table}`\n\n";

    $columns = $conn->select(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION ASC",
        [$db, $table]
    );

    echo "| Column | Type | Null | Default | Extra |\n";
    echo "|-------:|------|------|---------|-------|\n";
    foreach ($columns as $c) {
        $col = (string)$c->COLUMN_NAME;
        $typ = (string)$c->COLUMN_TYPE;
        $nul = ((string)$c->IS_NULLABLE) === 'YES' ? '✗' : '✓';

        // Normalize defaults: NULL → NULL, numeric → as-is, strings → quoted
        $defRaw = $c->COLUMN_DEFAULT;
        $def = 'NULL';
        if ($defRaw !== null) {
            if (is_numeric($defRaw)) {
                $def = (string)$defRaw;
            } elseif (is_string($defRaw)) {
                // CURRENT_TIMESTAMP and similar stay unquoted
                if (preg_match('/^(CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME)(\(\))?$/i', $defRaw)) {
                    $def = strtoupper($defRaw);
                } else {
                    $def = "'" . str_replace("'", "\\'", $defRaw) . "'";
                }
            }
        }
        $xtr = ($c->EXTRA !== null && trim((string)$c->EXTRA) !== '') ? (string)$c->EXTRA : '—';
        echo "| {$col} | {$typ} | {$nul} | {$def} | {$xtr} |\n";
    }
    echo "\n";

    // Primary key and indexes
    $indexes = $conn->select("SHOW INDEX FROM `{$table}`");
    if ($indexes !== []) {
        // Group by Key_name, then order by Non_unique asc, Seq_in_index asc
        $byKey = [];
        foreach ($indexes as $idx) {
            $key = (string)$idx->Key_name;
            $byKey[$key][] = $idx;
        }
        ksort($byKey, SORT_NATURAL);
        echo "**Indexes & Constraints**\n";
        foreach ($byKey as $key => $rows) {
            usort($rows, fn($a, $b) => (int)$a->Seq_in_index <=> (int)$b->Seq_in_index);
            $cols = array_map(fn($r) => (string)$r->Column_name, $rows);
            $unique = ((int)$rows[0]->Non_unique) === 0 ? 'UNIQUE ' : '';
            if ($key === 'PRIMARY') {
                echo "- `PRIMARY KEY (" . implode(', ', $cols) . ")`\n";
            } else {
                echo "- `{$unique}INDEX {$key} (" . implode(', ', $cols) . ")`\n";
            }
        }
        echo "\n";
    }

    // Foreign keys
    $fks = $conn->select(
        "SELECT k.CONSTRAINT_NAME AS name,
                k.COLUMN_NAME      AS col,
                k.REFERENCED_TABLE_NAME AS ref_table,
                k.REFERENCED_COLUMN_NAME AS ref_col,
                rc.UPDATE_RULE AS on_update,
                rc.DELETE_RULE AS on_delete
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
           ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
          AND rc.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
         WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ?
           AND k.REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
        [$db, $table]
    );
    if ($fks !== []) {
        $byFk = [];
        foreach ($fks as $fk) {
            $name = (string)$fk->name;
            if (!isset($byFk[$name])) {
                $byFk[$name] = [
                    'cols' => [],
                    'ref' => [(string)$fk->ref_table, (string)$fk->ref_col],
                    'on_update' => (string)$fk->on_update,
                    'on_delete' => (string)$fk->on_delete,
                ];
            }
            $byFk[$name]['cols'][] = (string)$fk->col;
        }
        foreach ($byFk as $name => $meta) {
            [$rt, $rc] = $meta['ref'];
            $cols = implode(', ', $meta['cols']);
            $upd  = strtoupper($meta['on_update']);
            $del  = strtoupper($meta['on_delete']);
            echo "- `FOREIGN KEY {$name} ({$cols}) REFERENCES {$rt}({$rc}) ON UPDATE {$upd} ON DELETE {$del}`\n";
        }
        echo "\n";
    }

    echo "---\n\n";
}
