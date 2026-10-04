# Searching

Search is served by pluggable backends chosen per request. `lib/provider.php`
resolves a provider name (case-insensitively) to a class; the default comes
from `PROVIDER` in `.env`. Known providers: **MySQL**, **Elasticsearch**,
**OpenSearch**, **Postgres** (plus `Laana` as an alias of MySQL).

## Where search happens

| Surface | Entry point |
|---|---|
| Web search page | `index.php` (home view) — form posts `?search=<term>&searchpattern=<mode>` |
| JSON API | `api.php?path=search&query=...&mode=...&limit=...&offset=...&provider=...` |
| Pagination/results fragment | `ops/getPageHtml.php` (infinite scroll; params `word`, `pattern`, `page`, `order`, `from`, `to`, `nodiacriticals`, `provider`) |
| Grammar-pattern search | `ops/getGrammarPatterns.php`, `ops/getGrammarMatchesHtml.php` (see [GRAMMAR_PATTERNS.md](GRAMMAR_PATTERNS.md)) |
| Hover/context links | `context.php`, `rawpage.php`, `rawtext.php` |

## Search modes per provider

`getAvailableSearchModes()` returns each provider's modes; the web dropdown
and the default mode come from it.

| Provider | Modes |
|---|---|
| MySQL | `exact`, `any`, `all`, `regex` |
| Elasticsearch / OpenSearch | `match`, `matchall`, `phrase`, `regex`, `hybrid` (keyword + sentence vectors), `hybriddoc` (keyword + document vectors) |
| Postgres | `exact`, `any`, `all`, `near`, `regex`, `hybrid` |

Under the hood, the Elasticsearch/OpenSearch `QueryBuilder` supports a larger
set of internal modes (`matchsentence`, `matchsentence_all`, `termsentence`,
`phrasesentence`, `match`, `term`, `phrase`, `regexp`, `regexpsentence`,
`vector`, `hybrid`, `vectorsentence`, `hybridsentence`, `knn`, `knnsentence`)
— document-level modes hit the documents index, sentence-level modes hit the
sentences index (both resolved through the production aliases
`hawaiian_documents` / `hawaiian_sentences`, configurable via
`ES_DOCUMENTS_ALIAS` / `ES_SENTENCES_ALIAS`). `hybrid`/`hybriddoc` combine keyword matching with
kNN vector search (384-dim sentence vectors / 1024-dim document vectors);
documents longer than ~32K chars are searched via `text_chunks` chunk vectors.

On OpenSearch, hybrid modes use the neural-search `hybrid` query with the
search pipeline defined in `providers/OpenSearch/config/search_pipeline.json`
(currently `norm-pipeline`: min-max normalization, weights 0.3 keyword /
0.7 vector). `OpenSearchClient::createSearchPipeline()` installs that file;
a test fails if the cluster's copy drifts from it. OpenSearch is very slow to highlight a `hybrid` query (~6.5 s for
5 hits on 3.9.0), so `OpenSearchClient::searchHybridThenHighlight()` runs the
hybrid query without highlighting, then highlights the returned ids with a
separate plain request using the keyword sub-queries. A hit matched only by
the vector side has no keyword highlight.

## Ordering

`order` (a.k.a. `orderby`) values used by both the word-search results and the
grammar view:

`rand` (default), `alpha`, `alpha desc`, `date`, `date desc`, `source`,
`source desc`, `length`, `length desc`, `none`.

On Elasticsearch/OpenSearch these map to sorts on `text.raw` (alpha), `date`,
`sourcename` (source), and `length`. Invalid values fall back to `rand`.

## Filters

- `from` / `to` — restrict to a year range (applies to document `date`).
- `nodiacriticals=1` — offer non-diacritic query variants.
- Blocked groups — `applyBlockedGroupFilter()` excludes `BLOCKED_SOURCES`
  groups (`.env`) from grammar and sentence queries.

## Word search results page

`index.php` (no view flag) with a `search` term renders the results list via
infinite scroll: each result shows the highlighted sentence, source link,
date, authors, and links to Context, Simplified, raw Snapshot, and Google
Translate. Result counts are recorded per search (see below).

## Search statistics

- **Recording** — `ops/recordsearch.php` / `ops/resultcount.php` store
  searchterm, mode/pattern, result count, order, and elapsed time. MySQL
  writes to the `searchstats` table; Elasticsearch to the
  `hawaiian-searchstats` index.
- **Viewing** — `searchstats.php` (recent searches dashboard, HST-normalized)
  and `stats.php` (stats dashboard shell).

## API quick reference

All endpoints accept `provider=` (case-insensitive; default MySQL) and return
JSON. Routing works via `path` query param or path-rewrite:
`api.php?path=sources&details` ≡ `api.php/sources?details`.

| Endpoint | Returns |
|---|---|
| `api.php?path=providers` | Known provider names |
| `api.php?path=sources&details[&group=][&properties=a,b]` | Source list; without `details`, source IDs only (`sourceids`) |
| `api.php?path=source/{id}` | One source's metadata |
| `api.php?path=source/{id}/plain` | `{text: "..."}` full plain text |
| `api.php?path=source/{id}/html` | `{html: "..."}` raw stored HTML |
| `api.php?path=source/{id}/sentences` | Sentences for the source |
| `api.php?path=sentences/wordcounts` | Sentence word-count stats |
| `api.php?path=documents/wordcounts` | Document word-count stats |
| `api.php?path=search&query=&mode=&limit=&offset=` | Search results (`sources` key) |
| `api.php?path=source/{id}/sentences` etc. with `provider=` | Same data from another backend |

These endpoints are also what the ingestion pipeline consumes
(`SourceIterator`/`SourceRetriever` call `sources?details&provider=MySQL` and
`source/{id}/plain&provider=MySQL`) — see [INGESTION.md](INGESTION.md).

## Tuning OpenSearch hybrid search (Search Relevance Workbench)

`scripts/os_relevance.php` tunes the `hybriddoc` score combination against
human relevance ratings, using OpenSearch's Search Relevance Workbench:

```bash
php scripts/os_relevance.php prepare --from-searchstats   # or --queries=FILE (one query per line)
# rate logs/os-relevance-ratings.csv: rating 0 irrelevant .. 3 highly relevant (blank = skip)
php scripts/os_relevance.php optimize                     # import ratings, run HYBRID_OPTIMIZER, rank variants
php scripts/os_relevance.php apply --rank=1               # write search_pipeline.json + install on cluster
```

- `prepare` takes the most frequent non-regex searches from `searchstats`
  (only the MySQL provider logs them). Quotes and tags are stripped and
  duplicates are merged case-insensitively. It then pools the top `--depth`
  (default 5) documents from three searches — keyword, vector, and the current
  hybrid configuration — into a CSV with a snippet for rating.
- `optimize` evaluates about 80 variants (min-max / L2 / z-score
  normalization × arithmetic / geometric / harmonic combination × keyword
  weights 0.0–1.0, plus RRF), ranked by NDCG@10. It also reports where the
  installed pipeline ranks.
- The Workbench embeds query text inside OpenSearch. `RemoteEmbeddingModel`
  registers the embedding service (`EMBEDDING_SERVICE_URL`, 1024-dim
  `multilingual-e5-large-instruct`, `query: ` prefix) as an ML Commons remote
  model; no model runs in the OpenSearch JVM. Registering adds the service URL
  to `plugins.ml_commons.trusted_connector_endpoints_regex` (on top of the
  defaults) and enables private-IP connectors and `only_run_on_ml_node: false`.
- State (query set, judgments and experiment ids) is kept in
  `logs/os-relevance-state.json`. Workbench objects can be inspected in
  OpenSearch Dashboards → Search Relevance.

