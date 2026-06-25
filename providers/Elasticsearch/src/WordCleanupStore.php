<?php

namespace HawaiianSearch;

class WordCleanupStore
{
    private const DEFAULT_OVERRIDES_FILE = __DIR__ . '/../../../data/word_cleanup_overrides.json';

    public static function getOverridesFilePath(): string
    {
        return self::DEFAULT_OVERRIDES_FILE;
    }

    public static function normalizeWord(string $word): string
    {
        return CorpusScanner::normalizeWord($word);
    }

    public static function loadOverrides(?string $filePath = null): array
    {
        $path = $filePath ?? self::getOverridesFilePath();

        if (!file_exists($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        if (isset($decoded['entries']) && is_array($decoded['entries'])) {
            return self::normalizeEntries($decoded['entries']);
        }

        $entries = [];
        if (isset($decoded['stopwords']) && is_array($decoded['stopwords'])) {
            foreach ($decoded['stopwords'] as $word) {
                $entries[] = self::buildEntry((string)$word, 'stopword');
            }
        }

        if (isset($decoded['recategorized']) && is_array($decoded['recategorized'])) {
            foreach ($decoded['recategorized'] as $word => $category) {
                $entries[] = self::buildEntry((string)$word, 'include', (string)$category);
            }
        }

        return self::normalizeEntries($entries);
    }

    public static function saveOverrides(array $entries, ?string $filePath = null): void
    {
        $path = $filePath ?? self::getOverridesFilePath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $payload = [
            'entries' => array_values(self::normalizeEntries($entries)),
            'updated_at' => gmdate('c'),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode cleanup overrides.');
        }

        $tempPath = $path . '.tmp';
        if (file_put_contents($tempPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write cleanup overrides.');
        }

        if (!rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new \RuntimeException('Failed to replace cleanup overrides file.');
        }
    }

    public static function applyToWordSet(array $wordSet, ?string $filePath = null): array
    {
        foreach (self::loadOverrides($filePath) as $entry) {
            $normalized = $entry['normalized'] ?? self::normalizeWord((string)($entry['word'] ?? ''));
            if ($normalized === '') {
                continue;
            }

            $action = strtolower(trim((string)($entry['action'] ?? '')));
            if (in_array($action, ['stopword', 'exclude', 'remove'], true)) {
                unset($wordSet[$normalized]);
                continue;
            }

            if (in_array($action, ['include', 'hawaiian', 'add'], true)) {
                $wordSet[$normalized] = true;
            }
        }

        return $wordSet;
    }

    public static function upsertEntries(array $entries, string $action, string $category = '', string $note = '', ?string $filePath = null): array
    {
        $existing = self::loadOverrides($filePath);

        foreach ($entries as $word) {
            $existing[self::normalizeWord($word)] = self::buildEntry($word, $action, $category, $note);
        }

        self::saveOverrides($existing, $filePath);

        return array_values($existing);
    }

    public static function removeEntries(array $entries, ?string $filePath = null): array
    {
        $existing = self::loadOverrides($filePath);

        foreach ($entries as $word) {
            $normalized = self::normalizeWord($word);
            unset($existing[$normalized]);
        }

        self::saveOverrides($existing, $filePath);

        return array_values($existing);
    }

    public static function getSummary(?string $filePath = null): array
    {
        $summary = [
            'total' => 0,
            'stopword' => 0,
            'include' => 0,
            'review' => 0,
            'note' => 0,
            'other' => 0,
        ];

        foreach (self::loadOverrides($filePath) as $entry) {
            $summary['total']++;
            $action = strtolower(trim((string)($entry['action'] ?? '')));
            if (!isset($summary[$action])) {
                $summary['other']++;
                continue;
            }

            $summary[$action]++;
        }

        return $summary;
    }

    private static function buildEntry(string $word, string $action, string $category = '', string $note = ''): array
    {
        $trimmed = trim($word);
        $normalized = self::normalizeWord($trimmed);

        return [
            'word' => $trimmed,
            'normalized' => $normalized,
            'action' => strtolower(trim($action)) ?: 'review',
            'category' => trim($category),
            'note' => trim($note),
            'updated_at' => gmdate('c'),
        ];
    }

    private static function normalizeEntries(array $entries): array
    {
        $normalizedEntries = [];

        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $entry = self::buildEntry($entry, 'stopword');
            }

            if (!is_array($entry)) {
                continue;
            }

            $word = trim((string)($entry['word'] ?? ''));
            if ($word === '') {
                continue;
            }

            $normalized = trim((string)($entry['normalized'] ?? self::normalizeWord($word)));
            if ($normalized === '') {
                continue;
            }

            $normalizedEntries[$normalized] = [
                'word' => $word,
                'normalized' => $normalized,
                'action' => strtolower(trim((string)($entry['action'] ?? 'review'))) ?: 'review',
                'category' => trim((string)($entry['category'] ?? '')),
                'note' => trim((string)($entry['note'] ?? '')),
                'updated_at' => (string)($entry['updated_at'] ?? gmdate('c')),
            ];
        }

        ksort($normalizedEntries);

        return $normalizedEntries;
    }
}