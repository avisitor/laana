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
        $this->http = new Client(['timeout' => 60, 'connect_timeout' => 10]);
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0775, true);
        }
    }

    // ---------------------------------------------------------------
    // Cache helpers
    // ---------------------------------------------------------------

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
        try {
            $response = $this->http->get($url);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new \RuntimeException("Failed to download {$url}: " . $e->getMessage(), 0, $e);
        }
        file_put_contents($path, $response->getBody()->getContents());
        return $path;
    }

    private function saveJson(string $name, array $data): void
    {
        $path = $this->cachePath($name);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Failed to encode JSON for {$name}");
        }
        file_put_contents($path, $json . PHP_EOL, LOCK_EX);
    }

    private function loadJson(string $name): array
    {
        $path = $this->cachePath($name);
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Load a name list written in the canonical record schema
     * (see NameListRecords) as a normalized-key membership set.
     */
    private function loadRecordsAsSet(string $name): array
    {
        $decoded = $this->loadJson($name);
        if ($decoded === []) {
            return [];
        }
        if (!NameListRecords::isRecordArray($decoded)) {
            \Avisitor\Monolog\Logger::logError(
                "Name list {$name} does not use the canonical record schema"
            );
            return [];
        }
        return NameListRecords::recordsToSet($decoded);
    }

    private function recursiveRemove(string $dir): void
    {
        $realDir = realpath($dir);
        if ($realDir === false) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $realPath = $item->getRealPath();
            if ($realPath === false || !str_starts_with($realPath, $realDir)) {
                continue;
            }
            if ($item->isDir()) {
                rmdir($realPath);
            } else {
                unlink($realPath);
            }
        }
        rmdir($realDir);
    }

    // ---------------------------------------------------------------
    // SSA names
    // ---------------------------------------------------------------

    public function loadSsaAllNames(): array
    {
        if ($this->isStale('ssa_all_names.json')) {
            $this->downloadSsaNames();
        }
        return $this->loadRecordsAsSet('ssa_all_names.json');
    }

    private function downloadSsaNames(): void
    {
        $zipPath = $this->download('https://www.ssa.gov/oact/babynames/names.zip', 'ssa_names.zip');

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            @unlink($zipPath);
            throw new \RuntimeException('Failed to open SSA names ZIP');
        }

        $extractDir = $this->cachePath('ssa_names_extracted');
        if (is_dir($extractDir)) {
            $this->recursiveRemove($extractDir);
        }
        mkdir($extractDir, 0775, true);
        $zip->extractTo($extractDir);
        $zip->close();

        $names = [];
        $files = glob($extractDir . '/yob*.txt');
        foreach ($files as $file) {
            $handle = fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $parts = str_getcsv($line);
                if (count($parts) < 3) {
                    continue;
                }
                $name = trim($parts[0]);
                $count = (int)$parts[2];
                $normalized = CorpusScanner::normalizeWord($name);
                if ($normalized === '') {
                    continue;
                }
                if (!isset($names[$normalized])) {
                    $names[$normalized] = 0;
                }
                $names[$normalized] += $count;
            }
            fclose($handle);
        }

        $this->recursiveRemove($extractDir);
        @unlink($zipPath);

        $records = [];
        foreach ($names as $normalized => $count) {
            $records[] = NameListRecords::make(
                $normalized,
                [$normalized],
                'Personal Name',
                "SSA total occurrences: {$count}."
            );
        }
        if ($records === []) {
            throw new \RuntimeException('SSA names download yielded no records');
        }

        $this->saveJson('ssa_all_names.json', $records);
    }

    // ---------------------------------------------------------------
    // Hawaiian given names
    // ---------------------------------------------------------------

    public function loadHawaiianGivenNames(): array
    {
        if ($this->isStale('hawaiian_given_names.json')) {
            $this->downloadHawaiianGivenNames();
        }
        return $this->loadRecordsAsSet('hawaiian_given_names.json');
    }

    private function downloadHawaiianGivenNames(): void
    {
        $url = 'https://en.wiktionary.org/wiki/Appendix:Hawaiian_given_names?action=raw';
        $response = $this->http->get($url, [
            'headers' => ['User-Agent' => 'Noiiolelo-name-list-fetcher/1.0 (Hawaiian corpus search; +https://github.com/avisitor)'],
        ]);
        $wikitext = $response->getBody()->getContents();

        $names = [];
        foreach (explode("\n", $wikitext) as $line) {
            if (preg_match('/^\|\|\s*\[\[([^\]|]+)/', $line, $m)) {
                $name = trim($m[1]);
                if ($name !== '') {
                    $normalized = CorpusScanner::normalizeWord($name);
                    if ($normalized !== '') {
                        $names[$normalized] = $name;
                    }
                }
            }
        }

        $records = [];
        foreach ($names as $normalized => $name) {
            $records[] = NameListRecords::make($name, [$normalized], 'Hawaiian Given Name');
        }
        if ($records === []) {
            throw new \RuntimeException('Hawaiian given names download yielded no records');
        }

        $this->saveJson('hawaiian_given_names.json', $records);
    }

    // ---------------------------------------------------------------
    // GNIS Hawaii places
    // ---------------------------------------------------------------

    public function loadGnisPlaceNames(): array
    {
        if ($this->isStale('gnis_hawaii_places.json')) {
            $this->downloadGnisPlaces();
        }
        return $this->loadRecordsAsSet('gnis_hawaii_places.json');
    }

    /**
     * Strip GNIS designational suffixes that are not part of a place's name.
     * Returns [clean name, adjusted category].
     */
    private function cleanGnisName(string $name, string $featureClass): array
    {
        $category = $featureClass;
        for ($i = 0; $i < 4; $i++) {
            $before = $name;
            if (preg_match('/\s+Hawaiian Home Land$/i', $name)) {
                if ($category === 'Civil') {
                    $category = 'Hawaiian Home Land';
                }
                $name = rtrim((string)preg_replace('/\s+Hawaiian Home Land$/i', '', $name));
            }
            $name = rtrim((string)preg_replace('/\s+Census Designated Place$/i', '', $name));
            $name = rtrim((string)preg_replace('/\s*\((historical|not official)\)$/i', '', $name));
            if ($name === $before) {
                break;
            }
        }
        return [$name, $category];
    }

    private function downloadGnisPlaces(): void
    {
        $base = 'https://geodata.hawaii.gov/arcgis/rest/services/HistoricCultural/MapServer/2/query'
            . '?where=1%3D1&outFields=feature_name%2Cfeature_class&returnGeometry=false'
            . '&f=json&resultRecordCount=5000';

        $byKey = [];
        $offset = 0;
        do {
            $response = $this->http->get($base . '&resultOffset=' . $offset);
            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data) || !isset($data['features'])) {
                throw new \RuntimeException("Invalid GNIS API response at offset {$offset}");
            }
            $features = $data['features'];
            foreach ($features as $feature) {
                $attrs = $feature['attributes'] ?? [];
                $name = trim($attrs['feature_name'] ?? '');
                $featureClass = trim($attrs['feature_class'] ?? '');
                if ($name === '') {
                    continue;
                }
                [$cleanName, $category] = $this->cleanGnisName($name, $featureClass);
                if ($cleanName === '') {
                    $cleanName = $name;
                    $category = $featureClass;
                }
                $key = CorpusScanner::normalizeWord($cleanName);
                if ($key === '') {
                    continue;
                }
                if (isset($byKey[$key])) {
                    // Same normalized name: a Census entry loses to a real
                    // feature class (e.g. "X Census Designated Place" vs "X
                    // Populated Place"); otherwise keep the first.
                    if (($byKey[$key]['category'] ?? '') === 'Census' && $category !== 'Census') {
                        $byKey[$key] = NameListRecords::make($cleanName, [], $category);
                    }
                    continue;
                }
                $byKey[$key] = NameListRecords::make($cleanName, [], $category);
            }
            $offset += count($features);
        } while (count($features) > 0 && ($data['exceededTransferLimit'] ?? false) === true);

        if ($byKey === []) {
            throw new \RuntimeException('GNIS places download yielded no records');
        }

        $this->saveJson('gnis_hawaii_places.json', array_values($byKey));
    }

    // ---------------------------------------------------------------
    // Hawaiian word list
    // ---------------------------------------------------------------

    public function loadHawaiianWordList(): array
    {
        if ($this->isStale('hawaiian_wordlist.json')) {
            $this->downloadHawaiianWordList();
        }
        return $this->loadRecordsAsSet('hawaiian_wordlist.json');
    }

    private function downloadHawaiianWordList(): void
    {
        $url = 'https://raw.githubusercontent.com/MitchTalmadge/Hawaiian-Word-List/master/hawaiian-words.csv';
        $response = $this->http->get($url);
        $csv = $response->getBody()->getContents();

        $words = [];
        foreach (explode("\n", $csv) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = str_getcsv($line);
            $word = trim($parts[0] ?? '');
            if ($word === '') {
                continue;
            }
            $normalized = CorpusScanner::normalizeWord($word);
            if ($normalized !== '') {
                $words[$normalized] = $word;
            }
        }

        $records = [];
        foreach ($words as $normalized => $word) {
            $records[] = NameListRecords::make($word, [$normalized], 'Hawaiian Word');
        }
        if ($records === []) {
            throw new \RuntimeException('Hawaiian word list download yielded no records');
        }

        $this->saveJson('hawaiian_wordlist.json', $records);
    }

    // ---------------------------------------------------------------
    // English words
    // ---------------------------------------------------------------

    public function loadEnglishWords(): array
    {
        if ($this->isStale('english_words.json')) {
            $this->downloadEnglishWords();
        }
        return $this->loadRecordsAsSet('english_words.json');
    }

    private function downloadEnglishWords(): void
    {
        $url = 'https://raw.githubusercontent.com/dwyl/english-words/master/words_alpha.txt';
        $response = $this->http->get($url);
        $text = $response->getBody()->getContents();

        $words = [];
        foreach (explode("\n", $text) as $line) {
            $word = strtolower(trim($line));
            if ($word === '' || strlen($word) <= 2) {
                continue;
            }
            $normalized = CorpusScanner::normalizeWord($word);
            if ($normalized !== '') {
                $words[$normalized] = true;
            }
        }

        $records = [];
        foreach (array_keys($words) as $normalized) {
            $records[] = NameListRecords::make($normalized, [$normalized], 'English Word');
        }
        if ($records === []) {
            throw new \RuntimeException('English words download yielded no records');
        }

        $this->saveJson('english_words.json', $records);
    }
}
