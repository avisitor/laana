# Auto-Classify Entities Design

## Problem

Manually marking entities in `ops/word_cleanup.php` as stopword/include/review is
labor-intensive. Most entities extracted from Hawaiian texts are regular Hawaiian or
English words, not names. A standalone script can pre-classify entities using name
lists and word dictionaries, reducing manual work.

## Goals

1. Programmatically mark non-name entities as stop words (conservative: only clear cases)
2. Support multiple override files so past work is preserved and experiments can coexist
3. Never overwrite manual overrides already in a file
4. Allow iterative refinement — run the script multiple times as lists improve

## Design

### Multiple Override Files

**`WordCleanupStore` additions:**

- `listOverrideFiles(): array` — scans `data/` for `word_cleanup_overrides*.json`,
  returns `[filename, modified_date]` pairs sorted by date descending
- `createNewFile(string $name): string` — creates an empty override file with the
  given name, returns the path

**`ops/word_cleanup.php` UI changes:**

- Dropdown at the top listing available override files
- Selected file persists via `?file=` URL parameter
- "New file..." option opens a small form to name and create a new override file
- Current file name shown in the header and summary stats

**Consumer updates:**

- `AdvancedEntityExtractor::getStopwordSet()` and `HawaiianWordLoader` accept a
  configurable override file path (via parameter or class constant)

### Name List Acquisition

Five sources cached in `data/name_lists/` as normalized JSON:

| Source | Purpose | Format | URL |
|--------|---------|--------|-----|
| SSA full US baby names | Broad person name detection | TXT in ZIP | ssa.gov/oact/babynames/names.zip |
| SSA Hawaii baby names | Locally popular names | TXT in ZIP | ssa.gov/oact/babynames/state/namesbystate.zip |
| Wiktionary Appendix Hawaiian given names | Hawaiian-language names | HTML (scrape) | en.wiktionary.org/wiki/Appendix:Hawaiian_given_names |
| GNIS Hawaii geographic names | Place names | CSV | opendata.hawaii.gov dataset |
| MitchTalmadge/Hawaiian-Word-List | Cross-reference dictionary | CSV (GitHub) | github.com/MitchTalmadge/Hawaiian-Word-List |

**`NameListManager` class** handles downloading, parsing, caching (30-day TTL),
and lookup. Each name is normalized via `CorpusScanner::normalizeWord()`.

### Classification Logic

CLI script: `scripts/auto_classify_entities.php --file=<override_file>`

For each `:GraphEntity` node from Neo4j:

1. **Skip** if already has an override entry in the file (preserve manual work)
2. **Skip** if entity has Neo4j label `:Person` or `:Place` (trust the graph)
3. **Include** if normalized form is in any name list (SSA, Hawaiian names)
   - action: `include`, category: "person-name" or "hawaiian-name"
4. **Include** if in GNIS place names
   - action: `include`, category: "place"
5. **Stopword** if in Hawaiian word dictionary AND NOT in any name list
   - action: `stopword`, category: "hawaiian-word"
6. **Stopword** if in English/common word list AND NOT in any name list
   - action: `stopword`, category: "english-word"
7. **Skip** everything else (uncertain — leave for manual review)

Conservative approach: only mark entities as stopword when we are confident they
are regular words and clearly not names.

### File Structure

| File | Purpose |
|------|---------|
| `scripts/auto_classify_entities.php` | CLI entry point |
| `providers/Elasticsearch/src/NameListManager.php` | Name list download/cache/lookup |
| `providers/Elasticsearch/src/WordCleanupStore.php` | Existing — add list/create methods |
| `ops/word_cleanup.php` | Existing — add file selector dropdown |
| `data/name_lists/` | Cached downloaded name list files |

### Dependencies

No new Composer dependencies. Uses Guzzle (already present) for HTTP downloads.

### End-to-End Workflow

```bash
# Run auto-classification
php scripts/auto_classify_entities.php --file=word_cleanup_overrides_v2.json

# Review results in the UI
# Open ops/word_cleanup.php?file=word_cleanup_overrides_v2.json
```
