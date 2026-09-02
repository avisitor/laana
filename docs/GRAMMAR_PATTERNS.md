# The Grammar Pattern System

The grammar system detects syntactic patterns (pepeke, ʻaʻano/hamani
constructions, negation, etc.) in Hawaiian sentences, stores the matches per
backend, and exposes them as a searchable facet.

## 1. Pattern definitions — `lib/grammar_patterns.json` + `lib/grammar_patterns.php`

23 pattern types are defined in `lib/grammar_patterns.json`
(`aohe_existential`, `hiki_infinitive`, `hoole_pepeke`, `hoole_pepeke_aike_he`,
`hoole_pepeke_aike_o`, `kalele_kumua`, `kalele_akena`, `kalele_kulana`,
`pepeke_aike_he`, `pepeke_aike_o`, `pepeke_painu`, `pepeke_nonoa`, …).

Each entry has a `regex` that may embed lexicon tokens like `%%TOKEN%%`;
`getGrammarPatterns()` (in `lib/grammar_patterns.php`) substitutes each token
from the JSON-backed lexicon (`lib/hawaiian_lexicon.php`) and compiles the
regex with `/ui` (unicode, case-insensitive). Add or tune patterns by editing
the JSON — nothing else needs to change.

## 2. Detection — `Noiiolelo\GrammarScanner`

`lib/GrammarScanner.php` matches compiled patterns against sentence text
(`scanSentence()`) and, for SQL backends, writes one row per
(sentence, pattern_type).

## 3. Storage per backend

| Backend | Match storage | Counts for the dropdown |
|---|---|---|
| Elasticsearch / OpenSearch | `grammar_patterns` keyword **array** on each document in `hawaiian_sentences_new` (e.g. `["pepeke_painu", "hoole_pepeke"]`) | Terms aggregation on the `grammar_patterns` field |
| MySQL | Rows in `sentence_patterns` | `grammar_pattern_counts` summary table (refreshed via `refresh_grammar_counts()`), or a live join when date filters are set |
| Postgres | `sentence_patterns` in schema `laana` | Same pattern as MySQL |

Most population is automatic (see *Keeping counts and assignments current*
below); `scripts/populate_grammar_patterns.php` is the standalone delta
backstop for the SQL backends (see [INGESTION.md](INGESTION.md)):

```bash
php scripts/populate_grammar_patterns.php --provider=elasticsearch --force
php scripts/populate_grammar_patterns.php --provider=mysql
php scripts/populate_grammar_patterns.php --provider=postgres
```

Notes:
- The ES path bulk-updates every sentence document with its pattern array;
  without `--force` it targets documents missing the field.
- The SQL path delta-scans sentences lacking patterns (or everything with
  `--force`) and refreshes `grammar_pattern_counts` afterwards.
- The MySQL save path also updates patterns incrementally:
  `addsentences.php` and `MySQLSaveManager` call
  `GrammarScanner::updateSourcePatterns()` for each ingested source.

### Keeping counts and assignments current

Per-provider story — who writes pattern assignments, and what keeps the
counts feeding the grammar dropdown fresh:

| Backend | Assignments | Counts |
|---|---|---|
| MySQL | Save-time scan: `MySQLSaveManager` calls `GrammarScanner::updateSourcePatterns()` per ingested source | Hourly MySQL event `hourly_grammar_refresh` calls `refresh_grammar_counts()` (see `createtables.sql`); the populate backstop refreshes after each run |
| Postgres | Save-time scan: `PostgresSaveManager` and the `pg_import.php` pipeline scan each source inside its transaction (`PostgresSourcePipeline`) | Once per run after the loop: `REFRESH MATERIALIZED VIEW CONCURRENTLY` on `grammar_pattern_counts` (STDERR warning on failure, never inside a transaction) |
| Elasticsearch / OpenSearch | Index time: the shared client code writes the `grammar_patterns` array while indexing sentences | Live terms aggregation on that field — nothing to refresh |

Cron/backstop story: `scripts/updatenoiiolelo.sh` runs
`populate_grammar_patterns.php` for both SQL providers after its save loop
as the delta backstop; on MySQL the hourly `hourly_grammar_refresh` event
keeps the counts table fresh between runs; Postgres counts are refreshed
once per ingestion run by design (the `CONCURRENTLY` refresh can also be
moved to cron if the view grows); ES/OpenSearch need no bookkeeping at all.

Known limitation (delta non-convergence): a sentence with **zero** pattern
matches never gets a `sentence_patterns` row (`savePatterns` writes nothing
for zero matches), so the delta queries re-select those sentences on every
run — bounded per source, but the scan never fully converges. A future
scanned-state marker table would fix this.

## 4. Querying — provider methods

- `getGrammarPatterns($options)` — list of `{pattern_type, count}`, with
  optional `from`/`to` year filters. Served by `ops/getGrammarPatterns.php`
  (`?provider=&from=&to=`).
- `getGrammarMatches($pattern, $limit, $offset, $options)` — sentences
  matching one pattern, with `from`/`to`/`order`. Served by
  `ops/getGrammarMatchesHtml.php` which renders the sentence HTML (with
  regex highlighting) for the infinite scroll.

Provider implementations:
- **Elasticsearch/OpenSearch** (`ElasticsearchClient`): terms aggregation on
  `grammar_patterns`; matches via `term` on the same field; sorts follow the
  standard `order` values (`text.raw`, `date`, `sourcename`, `length`).
- **MySQL** (`db/funcs.php`): counts from `grammar_pattern_counts` (or a
  filtered join); matches from `sentence_patterns` joined to `sentences`.
- **Postgres**: same shape via `PostgresClient`.

⚠️ Field-name rule: on the sentence index the field is `grammar_patterns`
(direct keyword) — querying `grammar_patterns.keyword` returns nothing. See
[INDEX_STRUCTURES.md](INDEX_STRUCTURES.md).

## 5. The grammar view — `index.php?grammar`

URL parameters preselect the form and auto-load results:

- `provider` — case-insensitive; resolved to the canonical name (MySQL,
  Elasticsearch, OpenSearch, Postgres); unknown values fall back to MySQL.
- `pattern` — preselected after the pattern list loads; results auto-load
  once if the pattern exists for that provider.
- `sortorder` (or legacy `order`) — one of the standard order values,
  validated against a whitelist, else `rand`.
- `from` / `to` — year filters on the source date.

Flow: the page fetches `ops/getGrammarPatterns.php?provider=...` to fill the
pattern dropdown (with per-pattern counts), then `loadGrammarResults()` pulls
paginated HTML from `ops/getGrammarMatchesHtml.php` via infinite scroll
(`pattern`, `provider`, `page`, `order`, `from`, `to`). A match count
("N matching sentences") comes from the selected option's count.

Dashboard views: `provider-dashboard.php` compares grammar-pattern counts
across all providers; `/?stats` charts grammar stats via `grammar_stats.php`.
