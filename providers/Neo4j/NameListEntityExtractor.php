<?php
namespace Noiiolelo\Providers\Neo4j;

use HawaiianSearch\CorpusScanner;
use HawaiianSearch\NameListRecords;

/**
 * Entity extraction driven by the curated name lists in data/name_lists,
 * restricted to the lists named in data/entity_sources.
 *
 * Every list record is indexed under its normalized core name first and
 * under its aliases second, so a text match on an alias (e.g. "Aʻala-mist")
 * resolves to the record's core entity ("Aʻala") instead of creating a
 * separate node. Name forms take priority over alias claims when the two
 * collide. Matching is diacritic-insensitive: list keys and text tokens are
 * normalized through the same scheme (okina/apostrophes stripped, macrons
 * converted to plain vowels, lowercased, hyphens treated as spaces), so
 * "Hāloa" in a modern document and "Haloa" in an old one produce the same
 * entity id.
 *
 * Precision gates (the previous regex extractor flooded the graph with
 * uninteresting entities): a candidate phrase is only accepted when its
 * first token is capitalized in the text (proper-noun usage), and a
 * single-token candidate is additionally rejected at sentence-initial
 * position — many Hawaiian sentence starters ("Kai", "Mele", "La") are also
 * SSA given names — and when it belongs to the particle/calendar skip set.
 * Names claimed only by the SSA given-name dump must sit inside a
 * capitalized run ("Hannah Kobayashi"): standing alone they also match
 * Hawaiian words that are conventionally capitalized mid-sentence ("ke
 * Akua"). Names also carried by a curated list are trusted without that
 * extra gate, and skip-set tokens can still appear inside multi-token
 * matches (e.g. "A Pohina").
 */
class NameListEntityExtractor
{
    private const ENTITY_SOURCES_FILE = __DIR__ . '/../../data/entity_sources';
    private const NAME_LIST_DIR = __DIR__ . '/../../data/name_lists';
    private const MAX_SENTENCE_RELATION_PAIRS = 200;

    /** name-list file -> graph label */
    private const FILE_LABELS = [
        'gnis_hawaii_places.json' => 'Place',
        'hawaiian_place_names.json' => 'Place',
        'hawaiian_place_names_supplement.json' => 'Place',
        'hawaiian_given_names.json' => 'Person',
        'ssa_all_names.json' => 'Person',
        'historical_figures.json' => 'Person',
        'literary_figures.json' => 'Person',
        'winds.json' => 'Wind',
        'rain.json' => 'Rain',
        'rainbows.json' => 'Rainbow',
        'weapons.json' => 'Weapon',
    ];

    /**
     * Normalized single tokens that never name an entity on their own:
     * Hawaiian grammatical particles (capitalized before proper nouns
     * mid-sentence, e.g. "Ke Aolama", "Ko Hawaiʻi Pae ʻĀina") and English
     * month/weekday names from newspaper datelines ("HONOLULU, JUNE 17,
     * 1896") that double as SSA given names.
     */
    private const SKIP_SINGLE_TOKENS = [
        'a' => true, 'e' => true, 'i' => true, 'ia' => true, 'ka' => true,
        'ke' => true, 'la' => true, 'ma' => true, 'na' => true, 'no' => true,
        'o' => true, 'ai' => true, 'ana' => true, 'aku' => true,
        'atu' => true, 'ko' => true, 'ela' => true,
        'january' => true, 'february' => true, 'march' => true, 'april' => true,
        'may' => true, 'june' => true, 'july' => true, 'august' => true,
        'september' => true, 'october' => true, 'november' => true,
        'december' => true,
        'sunday' => true, 'monday' => true, 'tuesday' => true,
        'wednesday' => true, 'thursday' => true, 'friday' => true,
        'saturday' => true,
    ];

    /** @var array<string, list<array>> normalized key -> record entries */
    private static ?array $index = null;
    private static int $maxKeyTokens = 0;
    private static string $entitySourcesFile = self::ENTITY_SOURCES_FILE;
    private static string $nameListDir = self::NAME_LIST_DIR;

    /**
     * Point the extractor at different source files (test hook).
     */
    public static function setSourcePaths(string $entitySourcesFile, string $nameListDir): void
    {
        self::$entitySourcesFile = $entitySourcesFile;
        self::$nameListDir = $nameListDir;
        self::$index = null;
    }

    /**
     * Drop the cached index (test hook).
     */
    public static function reset(): void
    {
        self::$index = null;
        self::$maxKeyTokens = 0;
    }

    /**
     * Extract entities found in $text by matching the curated name lists.
     *
     * @return list<array{name: string, type: string, id: string, source: string,
     *     category: string, context: string, island: string,
     *     birth_year: int|string|null, death_year: int|string|null}>
     */
    public static function extractEntities(string $text, array $options = []): array
    {
        $entities = [];
        $seen = [];
        foreach (self::matchInText(trim($text)) as $match) {
            $entry = $match['entry'];
            $id = self::entityId($entry);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $entities[] = [
                'name' => $entry['name'],
                'type' => $entry['label'],
                'id' => $id,
                'source' => implode(',', $entry['source']),
                'category' => $entry['category'],
                'context' => $entry['context'],
                'island' => $entry['island'],
                'birth_year' => $entry['birth_year'],
                'death_year' => $entry['death_year'],
            ];
        }
        return $entities;
    }

    /**
     * Extract undirected CO_OCCURS_WITH relationships between list-matched
     * entities appearing in the same sentence, capped like the legacy
     * extractor. Deliberately excludes the legacy pattern edges: with a
     * curated graph, sentence co-occurrence plus document-level
     * CO_MENTIONED_WITH (added by the rebuild script) carries the signal.
     *
     * @param array $entities Accepted for signature compatibility; matches
     *     are recomputed from the text so offsets stay authoritative.
     * @return list<array{source: string, relation: string, target: string}>
     */
    public static function extractRelationships(string $text, array $entities = []): array
    {
        $matches = self::matchInText(trim($text));
        if ($matches === []) {
            return [];
        }

        $sentenceStarts = self::sentenceStartOffsets(trim($text));
        $sentenceCount = count($sentenceStarts);
        $sentenceIds = array_fill(0, $sentenceCount, []);
        $sentenceIndex = 0;
        foreach ($matches as $match) {
            while ($sentenceIndex + 1 < $sentenceCount && $sentenceStarts[$sentenceIndex + 1] <= $match['offset']) {
                $sentenceIndex++;
            }
            $sentenceIds[$sentenceIndex][self::entityId($match['entry'])] = true;
        }

        $relationships = [];
        $relationshipSet = [];
        $pairCount = 0;
        foreach ($sentenceIds as $ids) {
            $ids = array_keys($ids);
            $count = count($ids);
            if ($count < 2) {
                continue;
            }
            for ($i = 0; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    [$source, $target] = strcmp($ids[$i], $ids[$j]) <= 0 ? [$ids[$i], $ids[$j]] : [$ids[$j], $ids[$i]];
                    $key = $source . '|CO_OCCURS_WITH|' . $target;
                    if (isset($relationshipSet[$key])) {
                        continue;
                    }
                    $relationshipSet[$key] = true;
                    $relationships[] = [
                        'source' => $source,
                        'relation' => 'CO_OCCURS_WITH',
                        'target' => $target,
                    ];
                    $pairCount++;
                    if ($pairCount >= self::MAX_SENTENCE_RELATION_PAIRS) {
                        break 3;
                    }
                }
            }
        }

        return $relationships;
    }

    /**
     * Walk the text token stream and return every list match in offset
     * order: longest candidate first, gated to capitalized proper-noun
     * usage, sentence-initial single tokens and the particle/calendar skip
     * set.
     *
     * @return list<array{offset: int, entry: array}>
     */
    private static function matchInText(string $text): array
    {
        $index = self::index();
        if ($index === []) {
            return [];
        }

        if (!preg_match_all("/[\\p{L}\\p{M}ʻʼ'’]+/u", $text, $tokenMatches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $rawTokens = [];
        $normTokens = [];
        $offsets = [];
        $lowerCounts = [];
        foreach ($tokenMatches[0] as $match) {
            $normalized = self::normalizeKey($match[0]);
            if ($normalized === '') {
                continue;
            }
            if (!self::isCapitalized($match[0])) {
                $lowerCounts[$normalized] = ($lowerCounts[$normalized] ?? 0) + 1;
            }
            $rawTokens[] = $match[0];
            $normTokens[] = $normalized;
            $offsets[] = $match[1];
        }
        $count = count($rawTokens);
        if ($count === 0) {
            return [];
        }

        $sentenceStarts = [];
        foreach (self::sentenceStartOffsets($text) as $start) {
            $sentenceStarts[$start] = true;
        }

        $matches = [];
        $i = 0;
        while ($i < $count) {
            if (!self::isCapitalized($rawTokens[$i])) {
                $i++;
                continue;
            }

            // Same-sentence Title-case neighbor: a capitalized word at the
            // end of a sentence must not borrow the next sentence's first
            // word as its "surname", and ALL-CAPS runs (newspaper mastheads
            // like "KA LAMA HAWAII") satisfy any capitalization test.
            $nextTokenInRun = false;
            if ($i + 1 < $count && !isset($sentenceStarts[$offsets[$i + 1]])) {
                $nextTokenInRun = self::isTitleCase($rawTokens[$i + 1]);
            }

            $maxLen = min(self::$maxKeyTokens, $count - $i);
            $matched = false;
            for ($len = $maxLen; $len >= 1; $len--) {
                $phrase = implode(' ', array_slice($normTokens, $i, $len));
                $entries = $index[$phrase] ?? null;
                if ($entries === null) {
                    continue;
                }
                if ($len === 1
                    && (isset($sentenceStarts[$offsets[$i]]) || isset(self::SKIP_SINGLE_TOKENS[$phrase]))) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if ($len === 1 && self::isSsaOnly($entry)
                        && (!self::isTitleCase($rawTokens[$i]) || !$nextTokenInRun
                            || mb_strlen($phrase) < 3 || ($lowerCounts[$phrase] ?? 0) >= 2)) {
                        // The 106k SSA given-name list only signals a person
                        // when the token is a Title-case word (3+ characters,
                        // so "Mr" and "de" never qualify) inside a
                        // capitalized run with a following Title-case token
                        // ("Hannah Kobayashi"). Standing alone it also
                        // matches Hawaiian words that are conventionally
                        // capitalized ("ke Akua") or appear in ALL-CAPS
                        // mastheads ("KA LAMA HAWAII", often running straight
                        // into Title-case body text), so require the full
                        // surname context. A document that also uses the
                        // token in lowercase ("ke Akua" next to "na akua")
                        // is treating it as a common word, not a name.
                        continue;
                    }
                    $matches[] = ['offset' => $offsets[$i], 'entry' => $entry];
                }
                $matched = true;
                $i += $len;
                break;
            }
            if (!$matched) {
                $i++;
            }
        }

        return $matches;
    }

    /**
     * Load data/entity_sources and build the normalized match index.
     * Pass 1 indexes core names; pass 2 attaches aliases to their owning
     * record only where the key is still unclaimed, so alias matches
     * resolve to the core entity.
     *
     * @return array<string, list<array>>
     */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        if (!is_file(self::$entitySourcesFile)) {
            throw new \RuntimeException('entity_sources file not found: ' . self::$entitySourcesFile);
        }
        $lines = file(self::$entitySourcesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Failed to read entity_sources file: ' . self::$entitySourcesFile);
        }
        $listFiles = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $listFiles[] = $line;
        }
        if ($listFiles === []) {
            throw new \RuntimeException('entity_sources names no name lists: ' . self::$entitySourcesFile);
        }

        $index = [];
        $maxKeyTokens = 0;

        foreach (['name', 'alias'] as $pass) {
            foreach ($listFiles as $listFile) {
                $path = rtrim(self::$nameListDir, '/') . '/' . $listFile;
                if (!is_file($path)) {
                    throw new \RuntimeException("Name list not found: {$path}");
                }
                $label = self::FILE_LABELS[$listFile] ?? null;
                if ($label === null) {
                    throw new \RuntimeException("No graph label mapping for name list {$listFile}");
                }
                $raw = file_get_contents($path);
                if ($raw === false) {
                    throw new \RuntimeException("Failed to read name list: {$path}");
                }
                $records = json_decode($raw, true);
                if (!NameListRecords::isRecordArray($records)) {
                    throw new \RuntimeException("Name list {$listFile} does not use the canonical record schema");
                }

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }
                    $entry = self::entry($label, $listFile, $record);
                    if ($entry['key'] === '' || $entry['name'] === '') {
                        continue;
                    }
                    if ($pass === 'name') {
                        self::claim($index, $entry['key'], $entry, $maxKeyTokens);
                    } else {
                        foreach (self::aliasKeys($record) as $aliasKey) {
                            if (!isset($index[$aliasKey])) {
                                self::claim($index, $aliasKey, $entry, $maxKeyTokens);
                            }
                        }
                    }
                }
                unset($records, $raw);
            }
        }

        // Curated lists are authoritative: where a curated file already
        // claims a normalized key, an SSA-only entry for the same key adds
        // nothing but noise (SSA "hawaii" duplicating the curated place
        // "Hawaii").
        $curatedKeys = [];
        foreach ($index as $key => $entries) {
            foreach ($entries as $entry) {
                foreach ($entry['source'] as $sourceFile) {
                    if ($sourceFile !== 'ssa_all_names.json') {
                        $curatedKeys[$key] = true;
                        break 2;
                    }
                }
            }
        }
        foreach (array_keys($curatedKeys) as $key) {
            $index[$key] = array_values(array_filter(
                $index[$key],
                static fn (array $entry): bool => !self::isSsaOnly($entry)
            ));
            if ($index[$key] === []) {
                unset($index[$key]);
            }
        }

        if ($index === []) {
            throw new \RuntimeException('Name list index is empty; check entity_sources and the list files');
        }

        self::$index = $index;
        self::$maxKeyTokens = $maxKeyTokens;
        return $index;
    }

    /**
     * Add an entry under $key. When the same label already owns the key
     * (a name shared by several list files), merge provenance and keep the
     * first metadata; a different label becomes an additional entry so
     * homonyms stay distinct entities.
     */
    private static function claim(array &$index, string $key, array $entry, int &$maxKeyTokens): void
    {
        foreach ($index[$key] ?? [] as $i => $existing) {
            if ($existing['label'] === $entry['label']) {
                $merged = array_values(array_unique(array_merge($existing['source'], $entry['source'])));
                $index[$key][$i]['source'] = $merged;
                return;
            }
        }
        $index[$key][] = $entry;
        $maxKeyTokens = max($maxKeyTokens, substr_count($key, ' ') + 1);
    }

    /**
     * Build the index entry for a record. The entry keeps the record's
     * normalized core name as "key" so alias matches and name matches
     * produce the same entity id.
     */
    private static function entry(string $label, string $listFile, array $record): array
    {
        return [
            'key' => self::normalizeKey((string)($record['name'] ?? '')),
            'label' => $label,
            'name' => (string)($record['name'] ?? ''),
            'source' => [$listFile],
            'category' => (string)($record['category'] ?? ''),
            'context' => (string)($record['context'] ?? ''),
            'island' => (string)($record['island'] ?? ''),
            'birth_year' => $record['birth_year'] ?? null,
            'death_year' => $record['death_year'] ?? null,
        ];
    }

    /**
     * Normalized alias keys for a record (duplicates removed).
     *
     * @return list<string>
     */
    private static function aliasKeys(array $record): array
    {
        $keys = [];
        foreach ($record['aliases'] ?? [] as $alias) {
            if (!is_string($alias)) {
                continue;
            }
            $key = self::normalizeKey($alias);
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * Diacritic-insensitive normalization shared by list keys and text
     * tokens: okina/apostrophe variants removed, macrons flattened,
     * lowercased, hyphens read as spaces, whitespace collapsed.
     */
    private static function normalizeKey(string $value): string
    {
        $value = str_replace(["\u{02BB}", "\u{02BC}", "\u{2018}", "\u{2019}"], '', $value);
        $value = CorpusScanner::normalizeWord($value);
        $value = str_replace('-', ' ', $value);
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    private static function entityId(array $entry): string
    {
        return strtoupper($entry['label']) . '_' . substr(sha1($entry['label'] . '|' . $entry['key']), 0, 24);
    }

    /**
     * True when the entry is claimed solely by the SSA given-name dump; a
     * name also carried by a curated list (Hawaiian given names, historical
     * or literary figures, places) is trusted without extra gating.
     */
    private static function isSsaOnly(array $entry): bool
    {
        return $entry['source'] === ['ssa_all_names.json'];
    }

    /**
     * Proper-noun usage: the token must start with an uppercase letter,
     * tolerating a leading okina/apostrophe. All-caps tokens pass too.
     */
    private static function isCapitalized(string $token): bool
    {
        return preg_match("/^[ʻʼ'’]?\\p{Lu}/u", $token) === 1;
    }

    /**
     * Mixed-case (Title) capitalization: an uppercase letter followed by a
     * lowercase one. All-caps tokens ("HAWAII") fail on purpose — inside
     * ALL-CAPS runs capitalization carries no proper-noun signal.
     */
    private static function isTitleCase(string $token): bool
    {
        return preg_match("/^[ʻʼ'’]?\\p{Lu}\\p{Ll}/u", $token) === 1;
    }

    /**
     * Byte offsets where sentences begin: position 0 plus the end of every
     * sentence-delimiter run. Sentence delimiters include the ASCII hyphen
     * (old newspapers separate items with "-") and the literal backslash
     * stored in corpus text ("...\n..." sequences).
     *
     * @return list<int>
     */
    private static function sentenceStartOffsets(string $text): array
    {
        $starts = [0];
        if (preg_match_all('/[.!?;\\\\-]+[\s\\\\]*/u', $text, $delimiters, PREG_OFFSET_CAPTURE)) {
            foreach ($delimiters[0] as $delimiter) {
                $starts[] = $delimiter[1] + strlen($delimiter[0]);
            }
        }
        return $starts;
    }
}
