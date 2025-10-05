<?php
#!/usr/bin/env php
declare(strict_types=1);

/**
 * Normalize a phpGRC schema markdown into a canonical text form for diffing.
 * - Keeps only table headers, column rows, and index/FK lines.
 * - Harmonizes case for types and CURRENT_* defaults.
 * - Drops narrative lines like "**Purpose:**" and extra columns beyond Default.
 *
 * Usage: php api/scripts/schema_normalize.php docs/db/DB-SCHEMA.md > /tmp/schema.norm
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php schema_normalize.php <DB-SCHEMA.md>\n");
    exit(1);
}

$path = $argv[1];
$txt = file_get_contents($path);
if ($txt === false) {
    fwrite(STDERR, "Cannot read $path\n");
    exit(1);
}

$lines = preg_split('/\R/', $txt);
$tables = [];
$cur = null;
$inTable = false;
$inIdx = false;

foreach ($lines as $line) {
    // Start of a table section: ### `table`
    if (preg_match('/^###\s+`([^`]+)`/', $line, $m)) {
        $cur = $m[1];
        $tables[$cur] = ['columns' => [], 'indexes' => []];
        $inTable = true;
        $inIdx = false;
        continue;
    }

    // End of a table block
    if ($inTable && preg_match('/^---\s*$/', trim($line))) {
        $cur = null;
        $inTable = false;
        $inIdx = false;
        continue;
    }

    if (!$inTable || $cur === null) {
        continue;
    }

    // Skip narrative lines
    if (preg_match('/^\*\*Purpose:\*\*/i', $line)) {
        continue;
    }

    // Transition to indexes section marker
    if (preg_match('/^\*\*Indexes\s*&\s*Constraints\*\*/i', $line)) {
        $inIdx = true;
        continue;
    }

    // Column rows in markdown tables
    if (!$inIdx && preg_match('/^\|/', $line)) {
        // Skip separator rows like |-----|
        if (preg_match('/^\|\s*-+/', $line)) {
            continue;
        }
        // Extract first four cells: Column | Type | Null | Default
        // Example input may have more cells; ignore the rest.
        $cells = array_map('trim', explode('|', trim($line, "| \t")));
        if (count($cells) < 4) {
            continue;
        }
        [$col, $type, $null, $default] = array_slice($cells, 0, 4);

        // Normalize
        $type = strtolower($type);
        $default = preg_match('/^current/i', $default) ? 'CURRENT_TIMESTAMP' : $default;

        $tables[$cur]['columns'][$col] = "{$col}|{$type}|{$null}|{$default}";
        continue;
    }

    // Index/FK lines
    if ($inIdx && preg_match('/^-\s*`(.+?)`/', $line, $m)) {
        $norm = strtolower($m[1]);
        // Remove duplicate spaces
        $norm = preg_replace('/\s+/', ' ', $norm);
        $tables[$cur]['indexes'][] = $norm;
        continue;
    }
}

// Emit canonical form
ksort($tables, SORT_NATURAL);
$out = [];
foreach ($tables as $t => $data) {
    $out[] = "TABLE {$t}";
    $out[] = "COLUMNS";
    ksort($data['columns'], SORT_NATURAL);
    foreach ($data['columns'] as $row) {
        $out[] = $row;
    }
    $out[] = "INDEXES";
    sort($data['indexes'], SORT_NATURAL);
    foreach ($data['indexes'] as $idx) {
        $out[] = $idx;
    }
    $out[] = "---";
}

echo implode("\n", $out) . "\n";
