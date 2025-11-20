# ElasticsearchSaveManager

A replacement for the MySQL-based `SaveManager` class that saves Hawaiian language documents directly to Elasticsearch.

## Overview

This class provides the same functionality as `SaveManager` from `saveFuncs.php` but stores documents in Elasticsearch instead of the MySQL Laana database. It uses:

- The same HTTP retrieval functions from `/var/www/html/noiiolelo/db/parsehtml.php`
- The same parser configurations from `/var/www/html/noiiolelo/scripts/parsers.php`
- The Elasticsearch client from `/var/www/html/elasticsearch/php/src/ElasticsearchClient.php`

## Files

- **ElasticsearchSaveManager.php** - Main class that handles document retrieval and Elasticsearch indexing
- **elasticsearch_index_example.php** - Example script demonstrating usage

## Key Differences from SaveManager

| Feature | SaveManager (MySQL) | ElasticsearchSaveManager |
|---------|-------------------|------------------------|
| Storage | MySQL Laana DB (sources, sentences, contents tables) | Elasticsearch (documents, sentences, metadata indices) |
| Raw HTML | Stored in `contents.html` | Not stored (only processed text) |
| Full Text | Stored in `contents.text` | Stored as full document in documents index |
| Sentences | Stored in `sentences` table | Stored in sentences index with vectors |
| Metadata | Stored in `sources` table | Stored in both indices as fields |
| Embeddings | Not generated | Automatically generated via embedding service |

## Usage

### Basic Usage

```bash
# Index 10 documents from nupepa group
php /var/www/html/noiiolelo/scripts/elasticsearch_index_example.php \
    --parserkey=nupepa \
    --maxrows=10
```

### With Debug Output

```bash
php elasticsearch_index_example.php \
    --parserkey=kauakukalahale \
    --debug \
    --maxrows=5
```

### Force Re-index Existing Documents

```bash
php elasticsearch_index_example.php \
    --parserkey=nupepa \
    --force \
    --maxrows=20
```

### Delete and Re-index

```bash
# Delete all existing documents with groupname and re-index
php elasticsearch_index_example.php \
    --parserkey=kauakukalahale \
    --delete-existing \
    --maxrows=100
```

### Process Specific Source Range

```bash
php elasticsearch_index_example.php \
    --parserkey=nupepa \
    --minsourceid=1000 \
    --maxsourceid=1050
```

## Options

- `--parserkey=NAME` - Required. Parser/groupname to use (e.g., nupepa, kauakukalahale)
- `--debug` - Enable debug output
- `--force` - Force re-indexing of existing documents
- `--maxrows=N` - Maximum number of documents to process (default: 20000)
- `--sourceid=ID` - Process only this specific source ID
- `--minsourceid=ID` - Minimum source ID to process
- `--maxsourceid=ID` - Maximum source ID to process
- `--delete-existing` - Delete existing documents with this groupname before indexing
- `--help` - Show help message

## Available Parser Keys

To see all available parser keys:

```bash
php elasticsearch_index_example.php --help
```

Common parser keys include:
- `nupepa` - Nupepa Hawaii newspapers
- `kauakukalahale` - Kauakukalahale blog
- `ulukau` - Ulukau digital library
- And many more...

## How It Works

1. **Document Retrieval**: Uses the parser's `getDocumentList()` to get a list of documents
2. **Content Fetching**: Uses parser's `getContents()` to retrieve HTML from URLs
3. **Sentence Extraction**: Uses parser's `extractSentencesFromHTML()` to parse content
4. **Hawaiian Detection**: Calculates Hawaiian word ratio using diacriticals and phonetic patterns
5. **Embedding Generation**: Calls embedding service to generate vectors for text and sentences
6. **Elasticsearch Indexing**: Uses `indexDocumentAndSentences()` to store in Elasticsearch

## Architecture

```
┌─────────────────────────────────────┐
│   parsehtml.php Parsers             │
│   (Domain-specific HTML parsing)    │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│   ElasticsearchSaveManager          │
│   - Orchestrates retrieval          │
│   - Calculates Hawaiian ratio       │
│   - Manages indexing workflow       │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│   ElasticsearchClient               │
│   - Communicates with ES            │
│   - Generates embeddings            │
│   - Bulk indexes documents          │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│   Elasticsearch Cluster             │
│   - hawaiian_documents_new          │
│   - hawaiian_sentences_new          │
│   - hawaiian-metadata               │
└─────────────────────────────────────┘
```

## Example Output

```
ElasticsearchSaveManager initialized
Documents index: hawaiian_documents_new
Sentences index: hawaiian_sentences_new

10 documents found
[1/10] Processing: Nupepa Article 123 (ID: 45678)... SUCCESS (42 sentences)
[2/10] Processing: Nupepa Article 124 (ID: 45679)... SUCCESS (38 sentences)
...

=== Indexing Summary ===
Total sources: 10
Indexed: 8
Skipped: 1
Errors: 1
```

## Programmatic Usage

```php
<?php
require_once __DIR__ . '/ElasticsearchSaveManager.php';

$options = [
    'parserkey' => 'nupepa',
    'debug' => true,
    'maxrows' => 50,
    'force' => false
];

$manager = new ElasticsearchSaveManager($options);

// Delete existing documents (optional)
$stats = $manager->deleteByGroupname('nupepa');

// Index documents
$manager->getAllDocuments();
?>
```

## Comparison: MySQL vs Elasticsearch Storage

### MySQL Laana Database
```sql
sources:     sourceid, sourcename, groupname, date, authors, title, link
sentences:   sentenceid, sourceid, hawaiianText, simplified
contents:    sourceid, html, text
```

### Elasticsearch Indices
```json
documents: {
  "sourceid": "12345",
  "sourcename": "Document Title",
  "groupname": "nupepa",
  "text": "Full document text...",
  "text_vector": [0.123, 0.456, ...],
  "hawaiian_word_ratio": 0.85,
  "metadata": { "date": "2010-01-01", "authors": "..." }
}

sentences: {
  "sourceid": "12345",
  "text": "Sentence text...",
  "vector": [0.789, 0.012, ...],
  "groupname": "nupepa",
  "position": 5,
  "metadata": { "date": "2010-01-01", ... }
}
```

## Migration Path

To migrate existing Laana MySQL data to Elasticsearch:

1. Use the existing SaveManager to ensure MySQL DB is current
2. Use ElasticsearchSaveManager with `--force` to re-index to Elasticsearch
3. Both systems can run in parallel during transition
4. Verify search results match between systems
5. Switch queries to Elasticsearch when ready

## Requirements

- PHP 7.4+
- Elasticsearch 8.x or 9.x cluster running
- Embedding service running at http://localhost:5000
- Proper .env configuration in `/var/www/html/elasticsearch/php/.env`

## Troubleshooting

### "Embedding service not available"
Ensure the embedding service is running:
```bash
curl http://localhost:5000/health
```

### "No parser found"
Check available parsers with:
```bash
php elasticsearch_index_example.php --help
```

### Performance Issues
- Reduce `--maxrows` for smaller batches
- The script adds 100ms delay between documents to avoid overwhelming services
- Adjust embedding service resources if needed

## Future Enhancements

- [ ] Resume from checkpoint if interrupted
- [ ] Parallel processing of documents
- [ ] Progress bar for long-running jobs
- [ ] Validation mode to compare MySQL vs Elasticsearch results
- [ ] Statistics tracking similar to MySQL searchstats table
