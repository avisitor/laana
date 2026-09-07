<?php

/**
 * One-off transformer: normalize every JSON file in data/name_lists to the
 * historical_figures.json schema (name/aliases/category/context/island/
 * birth_year/death_year) and write copies into data/name_lists/consistent/.
 * Source files are never modified.
 *
 * Schema conventions:
 *   - Every entry is an object with "name" plus any fields that carry a value,
 *     in canonical field order.
 *   - Fields that would be null, "" (empty string) or [] (empty array) are
 *     OMITTED entirely (requested 2026-09-06; previously written as null/"").
 *   - output: json_encode(JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), no trailing newline
 */

declare(strict_types=1);

const FIELDS = ['name', 'aliases', 'category', 'context', 'island', 'birth_year', 'death_year'];
const SRC_DIR = '/var/www/html/noiiolelo/data/name_lists';
const DST_DIR = SRC_DIR . '/consistent';

/**
 * Files already in the model schema: entries pass through unchanged (then get
 * null/empty fields stripped like everything else).
 */
const PASSTHROUGH = [
    'historical_figures.json',
    'literary_figures.json',
    'rain.json',
    'rainbows.json',
    'weapons.json',
    'winds.json',
];

/**
 * Map-shaped sources and their transformation.
 * Each transform receives the decoded source and returns the consistent entry.
 */
function transforms(): array
{
    return [
        // {lowercase name: total occurrence count} -> count preserved in context
        'ssa_all_names.json' => fn($v, $k) => [
            'name' => $k, 'aliases' => [], 'category' => 'Personal Name',
            'context' => "SSA total occurrences: {$v}.", 'island' => '',
            'birth_year' => null, 'death_year' => null,
        ],
        // NOTE: NameListManager writes the identical array to both files.
        'ssa_hawaii_names.json' => fn($v, $k) => [
            'name' => $k, 'aliases' => [], 'category' => 'Personal Name',
            'context' => "SSA total occurrences: {$v}.", 'island' => '',
            'birth_year' => null, 'death_year' => null,
        ],
        // {word: true} set of English words
        'english_words.json' => fn($v, $k) => [
            'name' => $k, 'aliases' => [], 'category' => 'English Word',
            'context' => '', 'island' => '',
            'birth_year' => null, 'death_year' => null,
        ],
        // {normalized: display} -> display name, normalized form kept as alias
        'hawaiian_given_names.json' => fn($v, $k) => [
            'name' => $v, 'aliases' => [$k], 'category' => 'Hawaiian Given Name',
            'context' => '', 'island' => '',
            'birth_year' => null, 'death_year' => null,
        ],
        'hawaiian_wordlist.json' => fn($v, $k) => [
            'name' => $v, 'aliases' => [$k], 'category' => 'Hawaiian Word',
            'context' => '', 'island' => '',
            'birth_year' => null, 'death_year' => null,
        ],
        // {normalized: {name, feature_class}}
        'gnis_hawaii_places.json' => fn($v, $k) => [
            'name' => $v['name'], 'aliases' => [$k], 'category' => $v['feature_class'],
            'context' => '', 'island' => '',
            'birth_year' => null, 'death_year' => null,
        ],
        'hawaiian_place_names_supplement.json' => fn($v, $k) => [
            'name' => $v['name'], 'aliases' => [$k], 'category' => $v['feature_class'],
            'context' => '', 'island' => '',
            'birth_year' => null, 'death_year' => null,
        ],
        // {normalized: {name, feature_class?, island?, moku?, ahupuaa?}}
        'hawaiian_place_names.json' => function ($v, $k) {
            $parts = [];
            if (!empty($v['ahupuaa'])) {
                $parts[] = "Ahupuaʻa: {$v['ahupuaa']}";
            }
            if (!empty($v['moku'])) {
                $parts[] = "Moku: {$v['moku']}";
            }
            return [
                'name' => $v['name'],
                'aliases' => [$k],
                'category' => $v['feature_class'] ?? '',
                'context' => $parts === [] ? '' : implode('; ', $parts) . '.',
                'island' => $v['island'] ?? '',
                'birth_year' => null,
                'death_year' => null,
            ];
        },
        // {variant: {name, feature_class, from}} -> provenance preserved in context
        'gnis_index.json' => fn($v, $k) => [
            'name' => $v['name'], 'aliases' => [$k], 'category' => $v['feature_class'],
            'context' => "Variant of \"{$v['from']}\".", 'island' => '',
            'birth_year' => null, 'death_year' => null,
        ],
        // flat array of country strings
        'world_countries.json' => fn($v) => [
            'name' => $v, 'aliases' => [], 'category' => 'Country',
            'context' => '', 'island' => '',
            'birth_year' => null, 'death_year' => null,
        ],
    ];
}

function fail(string $msg): never
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function loadSource(string $file): array
{
    $path = SRC_DIR . '/' . $file;
    $raw = file_get_contents($path);
    if ($raw === false) {
        fail("cannot read {$path}");
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fail("{$file} did not decode to an array");
    }
    return $decoded;
}

function encode(array $rows): string
{
    $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fail('json_encode failed: ' . json_last_error_msg());
    }
    return $json;
}

/** Rebuild an entry in canonical field order, omitting null/""/[] fields. */
function buildEntry(array $partial): array
{
    $entry = [];
    foreach (FIELDS as $field) {
        if (!array_key_exists($field, $partial)) {
            fail('transform output missing field ' . $field);
        }
        $value = $partial[$field];
        if ($value === null || $value === '' || $value === []) {
            continue;
        }
        // PHP casts numeric-string array keys to int; force strings back.
        if ($field === 'name') {
            if (!is_string($value) && !is_int($value)) {
                fail('unexpected name type: ' . get_debug_type($value));
            }
            $value = (string)$value;
        } elseif ($field === 'aliases') {
            $value = array_map(static fn($alias): string => (string)$alias, $value);
        }
        $entry[$field] = $value;
    }
    if (!isset($entry['name']) || !is_string($entry['name']) || $entry['name'] === '') {
        fail('entry has no usable name: ' . json_encode($partial));
    }
    return $entry;
}

if (!is_dir(DST_DIR) && !mkdir(DST_DIR, 0775, true)) {
    fail('cannot create ' . DST_DIR);
}

// ---- Pass 1: already-conforming files -> identity transform ----
$identity = fn($row) => $row;

// ---- All files: transform (or identity), strip empty fields, encode ----
foreach (transforms() as $file => $fn) {
    $source = loadSource($file);
    $rows = [];
    if (array_is_list($source)) {
        foreach ($source as $value) {
            $rows[] = buildEntry($fn($value, null));
        }
    } else {
        foreach ($source as $key => $value) {
            $rows[] = buildEntry($fn($value, $key));
        }
    }
    file_put_contents(DST_DIR . '/' . $file, encode($rows), LOCK_EX);
    printf("OK  %-40s transformed  %7d entries\n", $file, count($rows));
}

// ---- Pass 1 files use the identity transform, same stripping/encoding ----
foreach (PASSTHROUGH as $file) {
    $source = loadSource($file);
    $rows = [];
    foreach ($source as $row) {
        if (!is_array($row)) {
            fail("{$file} contains a non-object entry");
        }
        if (array_keys($row) !== FIELDS) {
            fail("{$file} entry keys are [" . implode(',', array_keys($row)) . "], expected [" . implode(',', FIELDS) . ']');
        }
        $rows[] = buildEntry($identity($row));
    }
    file_put_contents(DST_DIR . '/' . $file, encode($rows), LOCK_EX);
    printf("OK  %-40s passthrough   %7d entries\n", $file, count($rows));
}

echo "Done.\n";
