# Auto-Classify Entities Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Programmatically classify Neo4j entities as stopwords or includes using name lists and word dictionaries, with support for multiple override files.

**Architecture:** A `NameListManager` class downloads/caches name lists from SSA, Wiktionary, GNIS, and Hawaiian word lists. A CLI script queries Neo4j for all entities, cross-references them against these lists, and writes results to a configurable override file. The word_cleanup UI gets a dropdown to switch between override files.

**Tech Stack:** PHP 8.1+, Guzzle (already in project), no new dependencies.

---

### Task 1: Add `listOverrideFiles()` and `createNewFile()` to WordCleanupStore

**Files:**
- Modify: `providers/Elasticsearch/src/WordCleanupStore.php`

**Step 1: Add listOverrideFiles() method**

Add after `getSummary()` (around line 159):

```php
public static function listOverrideFiles(): array
{
    $dataDir = dirname(self::DEFAULT_OVERRIDES_FILE);
    $files = [];
    $pattern = $dataDir . '/word_cleanup_overrides*.json';

    foreach (glob($pattern) as $path) {
        $files[] = [
            'filename' => basename($path),
            'path' => $path,
            'modified' => filemtime($path),
        ];
    }

    usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

    return $files;
}
```

**Step 2: Add createNewFile() method**

Add after `listOverrideFiles()`:

```php
public static function createNewFile(string $filename): string
{
    $dataDir = dirname(self::DEFAULT_OVERRIDES_FILE);
    if (!preg_match('/^word_cleanup_overrides/', $filename)) {
        $filename = 'word_cleanup_overrides_' . $filename;
    }
    if (!str_ends_with($filename, '.json')) {
        $filename .= '.json';
    }

    $path = $dataDir . '/' . $filename;

    if (file_exists($path)) {
        throw new \RuntimeException("File already exists: {$filename}");
    }

    self::saveOverrides([], $path);

    return $path;
}
```

**Step 3: Verify syntax**

Run: `php -l providers/Elasticsearch/src/WordCleanupStore.php`
Expected: No syntax errors

---

### Task 2: Create NameListManager class

**Files:**
- Create: `providers/Elasticsearch/src/NameListManager.php`

**Step 1: Create the class with cache dir setup**

```php
<?php

namespace HawaiianSearch;

use GuzzleHttp\Client;

class NameListManager
{
    private const CACHE_DIR = __DIR__ . '/../../../data/name_lists';
    private const CACHE_TTL_DAYS = 30;

    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);

        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0775, true);
        }
    }
```

**Step 2: Add download/caching infrastructure**

```php
    private function cachePath(string $name): string
    {
        return self::CACHE_DIR . '/' . $name;
    }

    private function isStale(string $name): bool
    {
        $path = $this->cachePath($name);
        if (!file_exists($path)) {
            return true;
        }
        $age = time() - filemtime($path);
        return $age > (self::CACHE_TTL_DAYS * 86400);
    }

    private function download(string $url, string $cacheName): string
    {
        $path = $this->cachePath($cacheName);
        $response = $this->http->get($url);
        file_put_contents($path, $response->getBody()->getContents());
        return $path;
    }
```

**Step 3: Add SSA name list loaders**

```php
    public function loadSsaAllNames(): array
    {
        if ($this->isStale('ssa_all_names.json')) {
            $this->downloadSsaNames();
        }
        return $this->loadJson('ssa_all_names.json');
    }

    public function loadSsaHawaiiNames(): array
    {
        if ($this->isStale('ssa_hawaii_names.json')) {
            $this->downloadSsaNames();
        }
        return $this->loadJson('ssa_hawaii_names.json');
    }

    private function downloadSsaNames(): void
    {
        echo "Downloading SSA names data...\n";

        $zipPath = $this->cachePath('names.zip');
        $this->http->get('https://www.ssa.gov/oact/babynames/names.zip', [
            ['save_to' => $zipPath]
        ]);

        $tmpDir = $this->cachePath('ssa_tmp');
        if (is_dir($tmpDir)) {
            $this->recursiveRemove($tmpDir);
        }
        mkdir($tmpDir, 0775, true);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) === true) {
            $zip->extractTo($tmpDir);
            $zip->close();
        }

        $allNames = [];
        $hawaiiNames = [];

        $files = glob($tmpDir . '/yob*.txt');
        foreach ($files as $file) {
            $year = (int)basename($file, '.yob.txt');
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $parts = explode(',', $line);
                if (count($parts) < 3) continue;
                $name = $parts[0];
                $normalized = CorpusScanner::normalizeWord($name);
                if ($normalized === '') continue;
                if (!isset($allNames[$normalized])) {
                    $allNames[$normalized] = ['name' => $name, 'count' => 0];
                }
                $allNames[$normalized]['count'] += (int)$parts[2];
            }
        }

        $this->saveJson('ssa_all_names.json', $allNames);
        $this->recursiveRemove($tmpDir);
        @unlink($zipPath);

        echo "  SSA all names: " . count($allNames) . " entries\n";
    }
```

**Step 4: Add Hawaiian given names loader**

```php
    public function loadHawaiianGivenNames(): array
    {
        if ($this->isStale('hawaiian_given_names.json')) {
            $this->downloadHawaiianGivenNames();
        }
        return $this->loadJson('hawaiian_given_names.json');
    }

    private function downloadHawaiianGivenNames(): void
    {
        echo "Downloading Hawaiian given names from Wiktionary...\n";
        $url = 'https://en.wiktionary.org/wiki/Appendix:Hawaiian_given_names?action=raw';
        $response = $this->http->get($url);
        $wikitext = $response->getBody()->getContents();

        $names = [];
        if (preg_match_all('/^\|\s*\[\[([^\]|]+)(?:\|[^\]]+)?\]\]/m', $wikitext, $matches)) {
            foreach ($matches[1] as $name) {
                $normalized = CorpusScanner::normalizeWord($name);
                if ($normalized !== '') {
                    $names[$normalized] = ['name' => $name, 'source' => 'wiktionary'];
                }
            }
        }

        $this->saveJson('hawaiian_given_names.json', $names);
        echo "  Hawaiian given names: " . count($names) . " entries\n";
    }
```

**Step 5: Add GNIS place names loader**

```php
    public function loadGnisPlaceNames(): array
    {
        if ($this->isStale('gnis_hawaii_places.json')) {
            $this->downloadGnisPlaces();
        }
        return $this->loadJson('gnis_hawaii_places.json');
    }

    private function downloadGnisPlaces(): void
    {
        echo "Downloading GNIS Hawaii place names...\n";
        $url = 'https://geodata.hawaii.gov/arcgis/rest/services/HistoricCultural/MapServer/2/query?where=1%3D1&outFields=NAME,FEATURE_CLASS&f=json&resultRecordCount=10000';
        $response = $this->http->get($url);
        $data = json_decode($response->getBody()->getContents(), true);

        $places = [];
        if (isset($data['features'])) {
            foreach ($data['features'] as $feature) {
                $name = $feature['attributes']['NAME'] ?? '';
                $normalized = CorpusScanner::normalizeWord($name);
                if ($normalized !== '') {
                    $places[$normalized] = [
                        'name' => $name,
                        'feature_class' => $feature['attributes']['FEATURE_CLASS'] ?? '',
                    ];
                }
            }
        }

        $this->saveJson('gnis_hawaii_places.json', $places);
        echo "  GNIS places: " . count($places) . " entries\n";
    }
```

**Step 6: Add Hawaiian word list loader and English word loader**

```php
    public function loadHawaiianWordList(): array
    {
        if ($this->isStale('hawaiian_wordlist.json')) {
            $this->downloadHawaiianWordList();
        }
        return $this->loadJson('hawaiian_wordlist.json');
    }

    private function downloadHawaiianWordList(): void
    {
        echo "Downloading Hawaiian word list from GitHub...\n";
        $url = 'https://raw.githubusercontent.com/MitchTalmadge/Hawaiian-Word-List/master/hawaiian-words.csv';
        $response = $this->http->get($url);
        $csv = $response->getBody()->getContents();
        $lines = explode("\n", $csv);

        $words = [];
        foreach ($lines as $line) {
            $parts = str_getcsv($line);
            $word = trim($parts[0] ?? '');
            $normalized = CorpusScanner::normalizeWord($word);
            if ($normalized !== '') {
                $words[$normalized] = ['word' => $word];
            }
        }

        $this->saveJson('hawaiian_wordlist.json', $words);
        echo "  Hawaiian word list: " . count($words) . " entries\n";
    }

    public function loadEnglishWords(): array
    {
        if ($this->isStale('english_words.json')) {
            $this->downloadEnglishWords();
        }
        return $this->loadJson('english_words.json');
    }

    private function downloadEnglishWords(): void
    {
        echo "Downloading English word list...\n";
        $url = 'https://raw.githubusercontent.com/dwyl/english-words/master/words_alpha.txt';
        $response = $this->http->get($url);
        $text = $response->getBody()->getContents();
        $lines = explode("\n", $text);

        $words = [];
        foreach ($lines as $line) {
            $word = trim($line);
            $normalized = CorpusScanner::normalizeWord($word);
            if ($normalized !== '' && mb_strlen($normalized) > 2) {
                $words[$normalized] = ['word' => $word];
            }
        }

        $this->saveJson('english_words.json', $words);
        echo "  English words: " . count($words) . " entries\n";
    }
```

**Step 7: Add JSON helpers and recursiveRemove**

```php
    private function saveJson(string $name, array $data): void
    {
        file_put_contents(
            $this->cachePath($name),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function loadJson(string $name): array
    {
        $path = $this->cachePath($name);
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        return json_decode($raw, true) ?: [];
    }

    private function recursiveRemove(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
```

**Step 8: Verify syntax**

Run: `php -l providers/Elasticsearch/src/NameListManager.php`
Expected: No syntax errors

**Step 9: Commit**

```bash
git add providers/Elasticsearch/src/NameListManager.php
git commit -m "feat: add NameListManager for downloading and caching name lists"
```

---

### Task 3: Create the auto-classify CLI script

**Files:**
- Create: `scripts/auto_classify_entities.php`

**Step 1: Create the script with argument parsing and Neo4j query**

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../env-loader.php';
require_once __DIR__ . '/../vendor/autoload.php';

use HawaiianSearch\NameListManager;
use HawaiianSearch\WordCleanupStore;

$args = getopt(['file:', 'dry-run', 'help']);

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
```

**Step 2: Add Neo4j query function and entity fetching**

```php
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
    if (!$data || isset($decoded['errors']) && !empty($decoded['errors'])) {
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
```

**Step 3: Add name list loading and classification**

```php
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

$existingOverrides = WordCleanupStore::loadOverrides($filePath);
$existingKeys = [];
foreach ($existingOverrides as $entry) {
    $existingKeys[$entry['normalized']] = true;
}

echo "Existing overrides: " . count($existingKeys) . "\n\n";

function isName(array $normalized, array $ssaAll, array $ssaHawaii, array $hawaiianNames): bool
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
```

**Step 4: Add classification loop and output**

```php
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

    if (isset($existingKeys[$normalized])) {
        $skipCount++;
        continue;
    }

    if (in_array('Person', $labels) || in_array('Place', $labels)) {
        $skipCount++;
        continue;
    }

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
```

**Step 5: Verify syntax**

Run: `php -l scripts/auto_classify_entities.php`
Expected: No syntax errors

**Step 6: Commit**

```bash
git add scripts/auto_classify_entities.php
git commit -m "feat: add auto_classify_entities.php CLI script"
```

---

### Task 4: Add override file selector to word_cleanup.php UI

**Files:**
- Modify: `ops/word_cleanup.php` (lines 32, 385, and add dropdown logic)

**Step 1: Read current file param and resolve path**

Replace line 32 (`$overrideFile = WordCleanupStore::getOverridesFilePath();`) with:

```php
$availableFiles = WordCleanupStore::listOverrideFiles();
$selectedFile = (string)($_GET['file'] ?? basename(WordCleanupStore::getOverridesFilePath()));
$overrideFile = dirname(WordCleanupStore::getOverridesFilePath()) . '/' . $selectedFile;

if (!file_exists($overrideFile)) {
    $overrideFile = WordCleanupStore::getOverridesFilePath();
    $selectedFile = basename($overrideFile);
}
```

**Step 2: Add "new file" creation handler**

Add after the POST handler block (around line 266), before the `if (isset($_GET['saved']))` block:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_file') {
    $newFilename = trim((string)($_POST['new_filename'] ?? ''));
    if ($newFilename !== '') {
        try {
            $newPath = WordCleanupStore::createNewFile($newFilename);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?file=' . basename($newPath) . '&created=1');
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
```

**Step 3: Add file selector dropdown in the hero section**

Replace line 385 (`<p class="muted">Overrides file: <code><?php echo h($overrideFile); ?></code></p>`) with:

```php
<p class="muted">Overrides file:
  <form method="get" style="display:inline">
    <select name="file" onchange="this.form.submit()" style="display:inline; width:auto; padding:4px 8px; border-radius:6px; font:inherit;">
      <?php foreach ($availableFiles as $f): ?>
        <option value="<?php echo h($f['filename']); ?>"<?php echo $f['filename'] === $selectedFile ? ' selected' : ''; ?>>
          <?php echo h($f['filename']); ?> (<?php echo date('Y-m-d H:i', $f['modified']); ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit">Switch</button></noscript>
  </form>
  <form method="post" style="display:inline; margin-left:8px;">
    <input type="hidden" name="action" value="create_file">
    <input type="text" name="new_filename" placeholder="new filename" style="display:inline; width:180px; padding:4px 8px; border-radius:6px; font:inherit;">
    <button type="submit" class="ghost" style="padding:4px 10px; font-size:0.85rem;">Create</button>
  </form>
</p>
```

**Step 4: Add success notice for file creation**

Add after the `$error` notice block (around line 397):

```php
<?php if (isset($_GET['created'])): ?>
  <div class="notice success">Created new override file.</div>
<?php endif; ?>
```

**Step 5: Verify syntax**

Run: `php -l ops/word_cleanup.php`
Expected: No syntax errors

**Step 6: Commit**

```bash
git add ops/word_cleanup.php
git commit -m "feat: add override file selector dropdown to word_cleanup UI"
```

---

### Task 5: Add English word list download fix (SSA zip handling)

The SSA download in NameListManager uses Guzzle's `save_to` option which may need the `stream` option. Fix the downloadSsaNames method to handle this correctly.

**Files:**
- Modify: `providers/Elasticsearch/src/NameListManager.php`

**Step 1: Fix SSA download to use stream**

Replace the downloadSsaNames Guzzle call with:

```php
private function downloadSsaNames(): void
{
    echo "Downloading SSA names data...\n";

    $zipPath = $this->cachePath('names.zip');
    $response = $this->http->get('https://www.ssa.gov/oact/babynames/names.zip', [
        'stream' => true,
    ]);
    $body = $response->getBody();
    $content = '';
    while (!$body->eof()) {
        $content .= $body->read(8192);
    }
    file_put_contents($zipPath, $content);

    $tmpDir = $this->cachePath('ssa_tmp');
    if (is_dir($tmpDir)) {
        $this->recursiveRemove($tmpDir);
    }
    mkdir($tmpDir, 0775, true);

    $zip = new \ZipArchive();
    if ($zip->open($zipPath) === true) {
        $zip->extractTo($tmpDir);
        $zip->close();
    }

    $allNames = [];

    $files = glob($tmpDir . '/yob*.txt');
    foreach ($files as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = explode(',', $line);
            if (count($parts) < 3) continue;
            $name = $parts[0];
            $normalized = CorpusScanner::normalizeWord($name);
            if ($normalized === '') continue;
            if (!isset($allNames[$normalized])) {
                $allNames[$normalized] = ['name' => $name, 'count' => 0];
            }
            $allNames[$normalized]['count'] += (int)$parts[2];
        }
    }

    $this->saveJson('ssa_all_names.json', $allNames);
    $this->saveJson('ssa_hawaii_names.json', $allNames); // same dataset for now
    $this->recursiveRemove($tmpDir);
    @unlink($zipPath);

    echo "  SSA names: " . count($allNames) . " entries\n";
}
```

**Step 2: Verify syntax**

Run: `php -l providers/Elasticsearch/src/NameListManager.php`
Expected: No syntax errors

**Step 3: Commit**

```bash
git add providers/Elasticsearch/src/NameListManager.php
git commit -m "fix: correct SSA zip download handling in NameListManager"
```

---

### Task 6: Test the full workflow end-to-end

**Step 1: Dry run the script**

Run: `php scripts/auto_classify_entities.php --file=word_cleanup_overrides_test.json --dry-run`
Expected: Script downloads lists, fetches Neo4j entities, shows classification results without writing.

**Step 2: Run for real**

Run: `php scripts/auto_classify_entities.php --file=word_cleanup_overrides_test.json`
Expected: Script writes overrides to `data/word_cleanup_overrides_test.json`.

**Step 3: Verify the override file**

Run: `php -r "echo json_encode(array_slice(json_decode(file_get_contents('data/word_cleanup_overrides_test.json'), true)['entries'], 0, 5), JSON_PRETTY_PRINT);"`
Expected: Shows first 5 entries with word, normalized, action, category fields.

**Step 4: Open UI with test file**

Navigate to `ops/word_cleanup.php?file=word_cleanup_overrides_test.json`
Expected: Dropdown shows test file selected, entities display with override statuses.

**Step 5: Final commit**

```bash
git add data/name_lists/ .gitignore
git commit -m "chore: add name list cache dir and gitignore"
```
