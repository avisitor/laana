<?php

/**
 * Validate that every JSON file in data/name_lists uses the canonical record
 * schema (see HawaiianSearch\NameListRecords): a JSON array of records whose
 * keys are a canonical-order subset of name/aliases/category/context/island/
 * birth_year/death_year, with empty values omitted and non-empty typed values.
 *
 * Run after any generator touches the name lists:
 *   php data/validate_name_lists.php
 * Exit 0 = all valid; exit 1 = at least one violation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HawaiianSearch\NameListRecords;

const SRC_DIR = __DIR__ . '/name_lists';

function fail(string $file, string $msg): void
{
    fwrite(STDERR, "FAIL {$file}: {$msg}\n");
}

$violations = 0;
$files = glob(SRC_DIR . '/*.json');
if ($files === false || $files === []) {
    fwrite(STDERR, "FAIL: no JSON files found in " . SRC_DIR . "\n");
    exit(1);
}
sort($files);

foreach ($files as $path) {
    $file = basename($path);
    $raw = file_get_contents($path);
    if ($raw === false) {
        fail($file, 'cannot read');
        $violations++;
        continue;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === [] || !array_is_list($decoded)) {
        fail($file, 'top level must be a non-empty JSON array of records');
        $violations++;
        continue;
    }

    $errors = 0;
    $keys = [];
    foreach ($decoded as $i => $record) {
        if (!is_array($record) || $record === [] || array_is_list($record)) {
            fail($file, "record {$i}: not a JSON object");
            $errors++;
            continue;
        }
        $recordKeys = array_keys($record);
        $canonical = array_values(array_intersect(NameListRecords::FIELDS, $recordKeys));
        if ($recordKeys !== $canonical) {
            fail($file, "record {$i}: keys [" . implode(',', $recordKeys) . '] not a canonical-order subset of [' . implode(',', NameListRecords::FIELDS) . ']');
            $errors++;
        }
        if (!isset($record['name']) || !is_string($record['name']) || $record['name'] === '') {
            fail($file, "record {$i}: name must be a non-empty string");
            $errors++;
        }
        if (isset($record['aliases'])
            && (!is_array($record['aliases']) || !array_is_list($record['aliases'])
                || (static function (array $a): bool {
                    foreach ($a as $alias) {
                        if (!is_string($alias) || $alias === '') {
                            return true;
                        }
                    }
                    return false;
                })($record['aliases']))) {
            fail($file, "record {$i}: aliases must be a list of non-empty strings");
            $errors++;
        }
        foreach (['category', 'context', 'island'] as $field) {
            if (isset($record[$field]) && (!is_string($record[$field]) || trim($record[$field]) === '')) {
                fail($file, "record {$i}: {$field} must be a non-empty string when present");
                $errors++;
            }
        }
        foreach (['birth_year', 'death_year'] as $field) {
            if (isset($record[$field]) && !is_int($record[$field])) {
                fail($file, "record {$i}: {$field} must be an integer when present");
                $errors++;
            }
        }
        $key = NameListRecords::normalizedKey($record);
        if ($key === '') {
            fail($file, "record {$i}: no usable normalized key");
            $errors++;
        } elseif (isset($keys[$key])) {
            // Names may legitimately repeat across records (e.g. two distinct
            // places sharing a name), so duplicates warn instead of failing.
            fwrite(STDERR, "WARN {$file}: duplicate normalized key \"{$key}\" (records {$keys[$key]} and {$i})\n");
        } else {
            $keys[$key] = $i;
        }
    }

    if ($errors > 0) {
        $violations += $errors;
        continue;
    }
    printf("OK  %-40s %7d records\n", $file, count($decoded));
}

if ($violations > 0) {
    fwrite(STDERR, "FAILED: {$violations} violation(s)\n");
    exit(1);
}
echo "All name lists conform to the canonical record schema.\n";
