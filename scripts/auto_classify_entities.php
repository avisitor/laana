#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use HawaiianSearch\NameListManager;
use HawaiianSearch\WordCleanupStore;

$args = getopt('', ['file:', 'dry-run', 'help']);

if (isset($args['help'])) {
    echo "Usage: php auto_classify_entities.php --file=<override_file> [--dry-run]\n";
    echo "\n";
    echo "  --file      Override file name (in data/) or full path\n";
    echo "  --dry-run   Show what would be classified without writing\n";
    echo "  --help      Show this message\n";
    exit(0);
}

$fileName = $args['file'] ?? 'word_cleanup_overrides.json';
if (strpos($fileName, '/') === false && strpos($fileName, __DIR__) === false) {
    $filePath = dirname(__DIR__) . '/data/' . $fileName;
} else {
    $filePath = $fileName;
}

$dryRun = isset($args['dry-run']);

echo "Auto-Classify Entities\n";
echo "======================\n";
echo "Override file: {$filePath}\n";
echo "Dry run: " . ($dryRun ? 'yes' : 'no') . "\n\n";

// Neo4j request function (same as in word_cleanup.php)
function neo4jRequest(string $query, array $parameters = []): array
{
    $uri = getenv('NEO4J_URI') ?: 'http://localhost:7474';
    $username = getenv('NEO4J_USERNAME') ?: 'neo4j';
    $password = getenv('NEO4J_PASSWORD') ?: 'password';
    $url = rtrim(str_replace(['bolt://', ':7687'], ['http://', ':7474'], $uri), '/') . '/db/neo4j/tx/commit';

    $payload = [
        'statements' => [[
            'statement' => $query,
            'parameters' => empty($parameters) ? new stdClass() : $parameters,
        ]],
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $error !== '' || ($httpCode !== 200 && $httpCode !== 201)) {
        return ['error' => $error ?: "HTTP {$httpCode}"];
    }

    $decoded = json_decode($response, true);
    if (!$decoded || (isset($decoded['errors']) && !empty($decoded['errors']))) {
        return ['error' => json_encode($decoded['errors'] ?? 'Unknown')];
    }

    $result = $decoded['results'][0] ?? ['columns' => [], 'data' => []];
    $columns = $result['columns'] ?? [];
    $rows = [];
    foreach (($result['data'] ?? []) as $row) {
        $values = $row['row'] ?? [];
        $assoc = [];
        foreach ($columns as $i => $col) {
            $assoc[$col] = $values[$i] ?? null;
        }
        $rows[] = $assoc;
    }
    return ['columns' => $columns, 'rows' => $rows];
}

echo "Fetching entities from Neo4j...\n";
$entityResult = neo4jRequest(
    'MATCH (e:GraphEntity) '
    . 'OPTIONAL MATCH (e)-[r]-() '
    . 'RETURN e.id AS id, e.name AS name, labels(e) AS labels, count(r) AS mention_count '
    . 'ORDER BY mention_count DESC'
);

if (isset($entityResult['error'])) {
    echo "Error fetching entities: " . $entityResult['error'] . "\n";
    exit(1);
}

$entities = $entityResult['rows'] ?? [];
echo "  Found " . count($entities) . " entities\n\n";

// Load name lists
echo "Loading name lists...\n";
$manager = new NameListManager();

$ssaAll = $manager->loadSsaAllNames();
$ssaHawaii = $manager->loadSsaHawaiiNames();
$hawaiianNames = $manager->loadHawaiianGivenNames();
$gnisPlaces = $manager->loadGnisPlaceNames();
$hawaiianWords = $manager->loadHawaiianWordList();
$englishWords = $manager->loadEnglishWords();

echo "\nLoaded:\n";
echo "  SSA all names: " . count($ssaAll) . "\n";
echo "  SSA Hawaii: " . count($ssaHawaii) . "\n";
echo "  Hawaiian given names: " . count($hawaiianNames) . "\n";
echo "  GNIS places: " . count($gnisPlaces) . "\n";
echo "  Hawaiian words: " . count($hawaiianWords) . "\n";
echo "  English words: " . count($englishWords) . "\n\n";

// Load existing overrides to avoid overwriting manual work
$existingOverrides = WordCleanupStore::loadOverrides($filePath);
$existingKeys = [];
foreach ($existingOverrides as $entry) {
    $existingKeys[$entry['normalized']] = true;
}
echo "Existing overrides: " . count($existingKeys) . "\n\n";

// Classification helpers
function isName(string $normalized, array $ssaAll, array $ssaHawaii, array $hawaiianNames): bool
{
    return isset($ssaAll[$normalized])
        || isset($ssaHawaii[$normalized])
        || isset($hawaiianNames[$normalized]);
}

function isPlace(string $normalized, array $gnisPlaces): bool
{
    return isset($gnisPlaces[$normalized]);
}

function isHawaiianWord(string $normalized, array $hawaiianWords): bool
{
    return isset($hawaiianWords[$normalized]);
}

function isEnglishWord(string $normalized, array $englishWords): bool
{
    return isset($englishWords[$normalized]);
}

// Classify each entity
$includeCount = 0;
$stopwordCount = 0;
$skipCount = 0;
$newOverrides = [];

foreach ($entities as $entity) {
    $name = (string)($entity['name'] ?? '');
    $normalized = WordCleanupStore::normalizeWord($name);
    $labels = $entity['labels'] ?? [];

    if ($normalized === '') {
        $skipCount++;
        continue;
    }

    // Skip if already has an override entry (preserve manual work)
    if (isset($existingKeys[$normalized])) {
        $skipCount++;
        continue;
    }

    // Skip if entity has Neo4j label Person or Place (trust the graph)
    if (in_array('Person', $labels) || in_array('Place', $labels)) {
        $skipCount++;
        continue;
    }

    // Include if in any name list
    if (isName($normalized, $ssaAll, $ssaHawaii, $hawaiianNames)) {
        $newOverrides[] = [
            'word' => $name,
            'normalized' => $normalized,
            'action' => 'include',
            'category' => 'person-name',
        ];
        $includeCount++;
        continue;
    }

    // Include if in GNIS place names
    if (isPlace($normalized, $gnisPlaces)) {
        $newOverrides[] = [
            'word' => $name,
            'normalized' => $normalized,
            'action' => 'include',
            'category' => 'place',
        ];
        $includeCount++;
        continue;
    }

    // Stopword if Hawaiian dictionary word AND NOT a name
    if (isHawaiianWord($normalized, $hawaiianWords)) {
        $newOverrides[] = [
            'word' => $name,
            'normalized' => $normalized,
            'action' => 'stopword',
            'category' => 'hawaiian-word',
        ];
        $stopwordCount++;
        continue;
    }

    // Stopword if English word AND NOT a name
    if (isEnglishWord($normalized, $englishWords)) {
        $newOverrides[] = [
            'word' => $name,
            'normalized' => $normalized,
            'action' => 'stopword',
            'category' => 'english-word',
        ];
        $stopwordCount++;
        continue;
    }

    // Skip everything else (uncertain)
    $skipCount++;
}

echo "Classification results:\n";
echo "  Include (names/places): {$includeCount}\n";
echo "  Stopword (regular words): {$stopwordCount}\n";
echo "  Skip (existing/uncertain): {$skipCount}\n";
echo "  New overrides to write: " . count($newOverrides) . "\n\n";

if ($dryRun) {
    echo "Dry run — no changes written.\n";
    exit(0);
}

if (empty($newOverrides)) {
    echo "Nothing new to write.\n";
    exit(0);
}

// Merge new overrides with existing
$allOverrides = WordCleanupStore::loadOverrides($filePath);
foreach ($newOverrides as $override) {
    $allOverrides[$override['normalized']] = [
        'word' => $override['word'],
        'normalized' => $override['normalized'],
        'action' => $override['action'],
        'category' => $override['category'],
        'note' => '',
        'updated_at' => gmdate('c'),
    ];
}

WordCleanupStore::saveOverrides($allOverrides, $filePath);
echo "Wrote " . count($newOverrides) . " new overrides to {$filePath}\n";
