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

        $this->saveJson('ssa_all_names.json', $names);
        $this->saveJson('ssa_hawaii_names.json', $names);
    }

    // ---------------------------------------------------------------
    // Hawaiian given names
    // ---------------------------------------------------------------

    public function loadHawaiianGivenNames(): array
    {
        if ($this->isStale('hawaiian_given_names.json')) {
            $this->downloadHawaiianGivenNames();
        }
        return $this->loadJson('hawaiian_given_names.json');
    }

    private function downloadHawaiianGivenNames(): void
    {
        $url = 'https://en.wiktionary.org/wiki/Appendix:Hawaiian_given_names?action=raw';
        $response = $this->http->get($url);
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

        $this->saveJson('hawaiian_given_names.json', $names);
    }

    // ---------------------------------------------------------------
    // GNIS Hawaii places
    // ---------------------------------------------------------------

    public function loadGnisPlaceNames(): array
    {
        if ($this->isStale('gnis_hawaii_places.json')) {
            $this->downloadGnisPlaces();
        }
        return $this->loadJson('gnis_hawaii_places.json');
    }

    private function downloadGnisPlaces(): void
    {
        $url = 'https://geodata.hawaii.gov/arcgis/rest/services/HistoricCultural/MapServer/2/query'
            . '?where=1%3D1&outFields=NAME,FEATURE_CLASS&f=json&resultRecordCount=10000';
        $response = $this->http->get($url);
        $data = json_decode($response->getBody()->getContents(), true);
        if (!is_array($data) || !isset($data['features'])) {
            throw new \RuntimeException('Invalid GNIS API response');
        }
        $payload = $data;

        $places = [];
        if (isset($payload['features']) && is_array($payload['features'])) {
            foreach ($payload['features'] as $feature) {
                $attrs = $feature['attributes'] ?? [];
                $name = trim($attrs['NAME'] ?? '');
                $featureClass = trim($attrs['FEATURE_CLASS'] ?? '');
                if ($name === '') {
                    continue;
                }
                $normalized = CorpusScanner::normalizeWord($name);
                if ($normalized !== '') {
                    $places[$normalized] = [
                        'name' => $name,
                        'feature_class' => $featureClass,
                    ];
                }
            }
        }

        $this->saveJson('gnis_hawaii_places.json', $places);
    }

    // ---------------------------------------------------------------
    // Hawaiian word list
    // ---------------------------------------------------------------

    public function loadHawaiianWordList(): array
    {
        if ($this->isStale('hawaiian_wordlist.json')) {
            $this->downloadHawaiianWordList();
        }
        return $this->loadJson('hawaiian_wordlist.json');
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

        $this->saveJson('hawaiian_wordlist.json', $words);
    }

    // ---------------------------------------------------------------
    // English words
    // ---------------------------------------------------------------

    public function loadEnglishWords(): array
    {
        if ($this->isStale('english_words.json')) {
            $this->downloadEnglishWords();
        }
        return $this->loadJson('english_words.json');
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

        $this->saveJson('english_words.json', $words);
    }
}
