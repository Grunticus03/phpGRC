<?php
// Simple linter: ensure SCHEMA.md lists all tables created by migrations and their columns.
// Usage: php scripts/lint-schema-doc.php

declare(strict_types=1);

$root = dirname(__DIR__);
$migrationsDir = $root . '/api/database/migrations';
$schemaPath = $root . '/docs/db/DB-SCHEMA.md';

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "ERR: migrations dir not found: $migrationsDir\n");
    exit(2);
}
if (!is_file($schemaPath)) {
    fwrite(STDERR, "ERR: schema doc not found: $schemaPath\n");
    exit(2);
}

$migrationFiles = glob($migrationsDir . '/*.php') ?: [];
sort($migrationFiles);

$tables = []; // table => ['columns'=>set<string>, 'source'=>file]
foreach ($migrationFiles as $file) {
    $code = file_get_contents($file) ?: '';
    // capture Schema::create('table', function (Blueprint $table) { ... });
    if (preg_match_all("/Schema::create\\('\\s*([^'\\s]+)\\s*'\\s*,\\s*function\\s*\\(\\s*Blueprint\\s*\\$table\\s*\\)\\s*:\\s*void\\s*\\{(.*?)\\}\\);/s", $code, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $t = $m[1];
            $body = $m[2];
            $cols = [];

            // common column definitions: $table->type('name'
            if (preg_match_all("/\\$table->\\w+\\('\\s*([^'\\s]+)\\s*'\\s*(?:,\\s*[^)]*)?\\)/", $body, $cm)) {
                foreach ($cm[1] as $c) {
                    $cols[$c] = true;
                }
            }
            // morphs('tokenable') expands to tokenable_type + tokenable_id
            if (preg_match_all("/\\$table->morphs\\('\\s*([^'\\s]+)\\s*'\\)/", $body, $mm)) {
                foreach ($mm[1] as $base) {
                    $cols[$base . '_type'] = true;
                    $cols[$base . '_id'] = true;
                }
            }

            $tables[$t] = [
                'columns' => $cols,
                'source' => basename($file),
            ];
        }
    }
}

$schema = file_get_contents($schemaPath) ?: '';
$errors = [];

// find table sections as "### <table>"
$docTables = []; // table => set<columns>
if (preg_match_all('/^###\\s+([a-z0-9_]+)\\s*$/mi', $schema, $tm, PREG_SET_ORDER)) {
    $lines = preg_split('/\\R/', $schema);
    $lineCount = count($lines);
    foreach ($tm as $match) {
        $table = strtolower(trim($match[1]));
        // find the markdown table under this header
        $start = null;
        for ($i = 0; $i < $lineCount; $i++) {
            if (preg_match('/^###\\s+' . preg_quote($match[1], '/') . '\\s*$/i', (string)$lines[$i])) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) continue;
        $cols = [];
        for ($j = $start; $j < $lineCount; $j++) {
            $line = (string)$lines[$j];
            if (preg_match('/^###\\s+/', $line)) break; // next section
            if (preg_match('/^\\|\\s*([A-Za-z0-9_]+)\\s*\\|/', $line, $cm)) {
                $cols[strtolower($cm[1])] = true;
            }
        }
        $docTables[$table] = $cols;
    }
}

// check presence
foreach ($tables as $t => $meta) {
    if (!array_key_exists($t, $docTables)) {
        $errors[] = "Missing table section in DB-SCHEMA.md: {$t} (from {$meta['source']})";
        continue;
    }
    // columns
    $expected = array_map('strtolower', array_keys($meta['columns']));
    foreach ($expected as $c) {
        if ($c === 'timestamps' || $c === 'timestampsTz') continue; // not explicit
        if (!isset($docTables[$t][$c])) {
            $errors[] = "Missing column in DB-SCHEMA.md: {$t}.{$c}";
        }
    }
}

if ($errors) {
    fwrite(STDERR, "Schema doc linter failed:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "Schema doc linter OK.\n");
