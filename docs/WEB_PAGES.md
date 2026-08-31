# Web Pages and Views

The frontend is plain PHP + jQuery; `index.php` is the front controller whose
views are selected by flag query parameters. Common chrome (nav tabs, fonts,
bootstrap) comes from `common-head.html`; static assets in `static/`.

## `index.php` views

| URL | View | Contents |
|---|---|---|
| `/?search=<term>&searchpattern=<mode>` (default) | Home / word search | Search bar with mode dropdown, year range, ordering; results via infinite scroll (`ops/getPageHtml.php`); per-result links to Context/Simplified/Snapshot/Translate |
| `/?sources` | Sources browser | Paginated, sortable, searchable source table via `ops/getSourcesHtml.php` (params: `page`, `group`, `search`, `provider`) |
| `/?resources` | Resources | Static list of related Hawaiian-language resources |
| `/?grammar` | Grammar-pattern search | See [GRAMMAR_PATTERNS.md](GRAMMAR_PATTERNS.md); URL params `provider`, `pattern`, `sortorder` (or `order`), `from`, `to` preselect/auto-load |
| `/?stats` | Statistics | Charts from `grammar_stats.php` (Chart.js) |
| `/?search=...&grammar` etc. | Flags combine | `sources`/`resources`/`grammar`/`stats` are mutually exclusive views; `search` only applies to the home view |

Provider for the page comes from `provider=` (fallback `.env` `PROVIDER`).
The word-search form fields: `search` (term), `searchpattern` (mode),
`searchtype` dropdown, `frombox`/`tobox` years, provider select, ordering.

## Document pages

| Page | Purpose |
|---|---|
| `context.php?id=<sentenceid>&highlight_text=<urlencoded>` | Sentence in context with highlighted match ("Simplified" links here) |
| `rawpage.php?id=<sourceid>[&simplified]` | Original stored HTML (`&simplified` → plain text) — the "Snapshot" link target |
| `rawtext.php` | Raw plain text variant |

## Dashboards and stats pages

| Page | Purpose |
|---|---|
| `provider-dashboard.php` | Side-by-side provider comparison: corpus stats, group counts, grammar-pattern counts |
| `searchstats.php` | Recent searches dashboard (timestamps normalized to Pacific/Honolulu) |
| `stats.php` | Statistics dashboard shell (nav "Stats" tab) |
| `wordstats.html` | Word-count visualization page |
| `overview.html`, `about.html`, `sources.html`, `resources.html` | Static informational pages |

## Browser-driven web → MySQL ingestion

These pages scrape a source site in the browser and save into the MySQL
Laana DB — the manual upstream of `scripts/save.php` (see
[INGESTION.md](INGESTION.md)).

| Page | Purpose |
|---|---|
| `extractUlukau.php`, `extractCB.php`, `extractAoLama.php`, `extractKauakukalahale.php` | Thin wrappers that pick the site parser and include `extractBase.php` |
| `extractBase.php` | Shared form: `?title=&url=` → renders extracted sentences for review, submits to `addsentences.php` |
| `addsentences.php` | POST endpoint: adds source (if new) + sentences to MySQL, then runs `GrammarScanner::updateSourcePatterns()` |
| `ulukau.php`, `ulukaupages.php` | Server-side Ulukau scraping via the puppeteer script `db/ulukau/ulukau.js` |
| `cbpages.php`, `aolamapages.php`, `kauakukalahalepages.php`, `pagelist.php` | Page-list helpers for building document lists per site |

## Review app (`review/`)

A standalone single-page app for reviewing/correcting ingested documents:
keyboard + swipe navigation, typeahead source search, group filtering, and
views for plain text / sentences / original HTML. It talks to the API
(`api.php/sources?details`, `api.php/source/{id}/plain`) — see
`review/README.md`.
