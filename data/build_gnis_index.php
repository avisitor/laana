<?php

/**
 * Rebuild data/name_lists/gnis_index.json from the cleaned
 * gnis_hawaii_places.json: each multi-word place name gets a record whose
 * alias is the spaceless form of its normalized name ("haeleeleridge" for
 * "Hāʻeleʻele Ridge"), so spaceless lookups can find spaced names. Single-word
 * names are skipped — their spaceless form equals the normalized name and the
 * main file already matches those.
 *
 * Run after any GNIS refresh (php -r load or NameListManager TTL refresh):
 *   php data/build_gnis_index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HawaiianSearch\CorpusScanner;
use HawaiianSearch\NameListRecords;

const SRC = __DIR__ . '/name_lists/gnis_hawaii_places.json';
const DST = __DIR__ . '/name_lists/gnis_index.json';

function fail(string $msg): never
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

$raw = file_get_contents(SRC);
if ($raw === false) {
    fail('cannot read ' . SRC);
}
$decoded = json_decode($raw, true);
if (!NameListRecords::isRecordArray($decoded)) {
    fail(SRC . ' is not a canonical record array');
}

$byVariant = [];
foreach ($decoded as $record) {
    $normalized = NameListRecords::normalizedKey($record);
    $spaceless = str_replace(' ', '', $normalized);
    if ($spaceless === '' || $spaceless === $normalized) {
        continue;
    }
    if (isset($byVariant[$spaceless])) {
        continue;
    }
    $byVariant[$spaceless] = NameListRecords::make(
        (string)$record['name'],
        [$spaceless],
        (string)($record['category'] ?? ''),
        "Variant of \"{$normalized}\"."
    );
}

$json = json_encode(array_values($byVariant), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fail('json_encode failed: ' . json_last_error_msg());
}
if (file_put_contents(DST, $json . PHP_EOL, LOCK_EX) === false) {
    fail('cannot write ' . DST);
}
printf("OK  gnis_index.json rebuilt: %d variants from %d GNIS records\n", count($byVariant), count($decoded));
