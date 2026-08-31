# ElasticsearchSaveManager

Web → Elasticsearch ingestion: saves Hawaiian-language documents scraped from
external sites directly into Elasticsearch, bypassing the MySQL Laana
database. It mirrors `providers/MySQL/MySQLSaveManager.php` (which stores the
same scraped documents in MySQL) and is driven by the unified CLI script
`scripts/save.php`.

## Files

- **`providers/Elasticsearch/ElasticsearchSaveManager.php`** — orchestrates retrieval, Hawaiian-ratio scoring, and indexing
- **`scripts/save.php`** — CLI driver (`--provider=es` selects this manager)
- **`scripts/parsers.php`** — parser registry (site → parser class from `db/parsehtml.php`)
- **`providers/Elasticsearch/src/ElasticsearchClient.php`** — ES communication, embeddings, bulk indexing

## Storage layout

| Data | Elasticsearch (this manager) | MySQL (`MySQLSaveManager`) |
|---|---|---|
| Full text | `text` in `hawaiian_documents_new` | `contents.text` |
| Raw HTML | `hawaiian-content` index | `contents.html` |
| Sentences | `hawaiian_sentences_new` (one doc per sentence, with vectors) | `sentences` table |
| Metadata | Fields on both indices | `sources` table |
| Embeddings | Generated automatically via the embedding service | Not generated |

## Usage

```bash
# Index documents from a site into Elasticsearch
php scripts/save.php --provider=es --parser=nupepa
php scripts/save.php --provider=es --parser=ulukau --debug --maxrows=5
php scripts/save.php --provider=es --parser=nupepa --force --maxrows=20

# Single source / range
php scripts/save.php --provider=es --parser=nupepa --sourceid=45678
php scripts/save.php --provider=es --parser=nupepa --minsourceid=1000 --maxsourceid=1050

# Snapshot a parser's document list, then run against it later
php scripts/save.php --provider=es --parser=nupepa --doclist-save
php scripts/save.php --provider=es --parser=nupepa --doclist-file=scripts/doclists/nupepa.json
```

Options (parsed by `scripts/save.php`): `--parser=KEY` (required),
`--sourceid=`, `--minsourceid=`, `--maxsourceid=`, `--maxrows=N`,
`--force`, `--resplit`, `--local`, `--doclist-save[=PATH]`,
`--doclist-file=PATH`, `--doclist-only`, `--debug`, `--verbose`.

## Available parser keys

Defined in `scripts/parsers.php`: `nupepa` (Nupepa Hawaii newspapers),
`ulukau` (Ulukau digital library), `ulukaulocal`, `keaolama`,
`kauakukalahale` (Star-Advertiser column), `kapaamoolelo`, `baibala`,
`ehooululahui`, `kaiwakiloumoku`, `kaulanapilina` (Civil Beat).

## How it works

1. **Document retrieval** — the parser's `getDocumentList()` enumerates documents on the site
2. **Content fetching** — the parser's `getContents()` retrieves each page's HTML
3. **Sentence extraction** — `extractSentencesFromHTML()` parses content into sentences
4. **Hawaiian detection** — Hawaiian word ratio from diacritics + word list
5. **Embedding generation** — embedding service vectors for text and sentences
6. **Elasticsearch indexing** — `ElasticsearchClient::indexDocumentAndSentences()` writes to the documents and sentences indices; a processing-log record covers the run

## Architecture

```
┌─────────────────────────────────────┐
│   parsehtml.php Parsers             │
│   (domain-specific HTML parsing)    │
└──────────────┬──────────────────────┘
               ▼
┌─────────────────────────────────────┐
│   ElasticsearchSaveManager          │
│   (retrieval, Hawaiian ratio,       │
│    indexing workflow)               │
└──────────────┬──────────────────────┘
               ▼
┌─────────────────────────────────────┐
│   ElasticsearchClient               │
│   (embeddings, bulk indexing)       │
└──────────────┬──────────────────────┘
               ▼
┌─────────────────────────────────────┐
│   Elasticsearch                     │
│   hawaiian_documents_new            │
│   hawaiian_sentences_new            │
│   hawaiian-content                  │
└─────────────────────────────────────┘
```

Programmatic usage:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
\Avisitor\Env\Loader::load(__DIR__ . '/.env');

$manager = new Noiiolelo\Providers\Elasticsearch\ElasticsearchSaveManager([
    'parserkey' => 'nupepa',
    'debug'     => true,
    'maxrows'   => 50,
]);

$manager->getAllDocuments();          // index every document from the parser
// $manager->processOneSource('45678'); // or a single source
```

## Relation to the MySQL path

`scripts/save.php --provider=mysql` runs the identical parsers through
`MySQLSaveManager` into the Laana DB. The corpus-wide MySQL → Elasticsearch
build is a separate step: `scripts/createindex.php` (see
[INGESTION.md](INGESTION.md)). Both systems can run in parallel; verify
search results match between them via `provider-dashboard.php` before
switching.

## Requirements

- PHP 7.4+
- Elasticsearch 8.x/9.x (or OpenSearch) running, credentials in `.env`
- Embedding service running at `EMBEDDING_SERVICE_URL` (default
  `http://localhost:5000`; health check `curl http://localhost:5000/health`)
- Network access to the target sites

## Troubleshooting

- **"Embedding service not available"** — start the embedding service; see `providers/Elasticsearch/docs/embedding_service_requirements.txt`
- **"No parser specified/found"** — `--parser` must match a key in `scripts/parsers.php`
- **Slow runs** — reduce `--maxrows`; the manager throttles requests between documents
