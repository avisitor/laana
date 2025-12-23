# PostgreSQL Indexer System

## Overview

The Postgres indexer system backfills the Postgres database with data from MySQL, including:
- **Sentences**: Hawaiian text with embeddings and metrics
- **Documents**: Full document text with embeddings and metrics

## Components

### Core Libraries

1. **PostgresClient.php** - Main client for Postgres operations
   - Sentence operations: `countTotalSentences()`, `countMissingEmbeddings()`, `fetchCandidateSentenceIds()`, `bulkUpdateSentenceEmbeddings()`, `upsertSentenceMetrics()`
   - Document operations: `countTotalDocuments()`, `countMissingDocumentEmbeddings()`, `fetchCandidateDocumentIds()`, `bulkUpdateDocumentEmbeddings()`, `upsertDocumentMetrics()`

2. **PostgresSentenceIterator.php** - Iterates over sentences needing embeddings/metrics
3. **PostgresDocumentIterator.php** - Iterates over documents needing embeddings/metrics
4. **PostgresSentenceIndexer.php** - Processes sentence batches with embeddings and metrics
5. **PostgresDocumentIndexer.php** - Processes document batches with embeddings and metrics
6. **MetricsComputer.php** - Computes Hawaiian language metrics
   - `computeSentenceMetrics()` - For individual sentences
   - `computeDocumentMetrics()` - For full documents

### Driver Script

**scripts/pg_indexer.php** - Unified indexer that replaces `ops/pg_index_sentences.php`

## Usage

### Basic Usage

```bash
# Dry run (default) - shows what would be processed without making changes
php scripts/pg_indexer.php

# Write mode - actually update the database
php scripts/pg_indexer.php --write

# Process only sentences
php scripts/pg_indexer.php --write --sentences

# Process only documents
php scripts/pg_indexer.php --write --documents

# Process both (default when neither flag is specified)
php scripts/pg_indexer.php --write

# Limit number of records
php scripts/pg_indexer.php --write --limit 100

# Quiet mode (minimal output)
php scripts/pg_indexer.php --write --quiet

# Verbose mode (detailed output)
php scripts/pg_indexer.php --write --verbose
```

### Output Options

```bash
# Save processed IDs to file
php scripts/pg_indexer.php --write --ids-out /tmp/processed_ids.txt

# Save JSON summary with timing and counts
php scripts/pg_indexer.php --write --out-json /tmp/indexer_results.json
```

### Force Reindexing

By default, the indexer only processes records that are missing embeddings or metrics (incremental mode).

```bash
# Force reindex all records (NOT YET IMPLEMENTED - future feature)
php scripts/pg_indexer.php --write --force
```

## Database Schema

### MySQL (Source)

- **sentences**: Contains `sentenceID`, `sourceID`, `hawaiianText`, `englishText`
- **contents**: Contains `sourceID`, `html`, `text`

### Postgres (Target)

- **sentences**: Includes `embedding` vector(384) field
- **sentence_metrics**: Stores `hawaiian_word_ratio`, `word_count`, `length`, `entity_count`, `frequency`
- **contents**: Includes `embedding` vector(384) field  
- **document_metrics**: Stores `hawaiian_word_ratio`, `word_count`, `length`, `entity_count`

## Configuration

Configuration is read from `.env` file:

```env
PG_HOST=74.208.79.220
PG_PORT=5432
PG_DATABASE=noiiolelo
PG_USER=laana
PG_PASSWORD=WmC2UPRz

DB_HOST=localhost
DB_SOCKET="/tmp/mysql.sock"
DB_DATABASE=laana
DB_USER=laana
DB_PASSWORD=WmC2UPRz
DB_PORT=3306

EMBEDDING_SERVICE_URL=http://localhost:5000
```

## Architecture

The system follows a consistent pattern across sentences and documents:

1. **Iterator** - Fetches batches of records missing embeddings/metrics from Postgres
2. **Indexer** - For each batch:
   - Extracts text
   - Generates embeddings via embedding service
   - Computes Hawaiian language metrics
   - Updates Postgres in a transaction (embeddings + metrics)
3. **Client** - Provides all database operations

### No Code Duplication

- Iterator pattern is identical for sentences and documents
- Indexer pattern is identical for sentences and documents  
- PostgresClient provides parallel methods for both types
- MetricsComputer provides separate but similar methods for both types

### No Fallback Code

- Configuration is explicit and required
- No hidden defaults that mask configuration issues
- Failures are reported immediately, not silently ignored

## Migration from Old System

The old `ops/pg_index_sentences.php` is now replaced by `scripts/pg_indexer.php`.

**Old command:**
```bash
php ops/pg_index_sentences.php --write --limit 100
```

**New command:**
```bash
php scripts/pg_indexer.php --write --sentences --limit 100
```

To process both sentences and documents:
```bash
php scripts/pg_indexer.php --write
```

## Performance

- Batch processing: 25 records per batch (configurable via BATCH_SIZE)
- Progress tracking with timing metrics
- JSON output includes embed_ms, metrics_ms, db_ms, total_ms
- Throughput calculated as records/second

## Example Output

```
Unified Postgres Indexer
========================
Mode: WRITE MODE
Force: NO (incremental)
Processing: sentences, documents

Processing Sentences
----------------------------------------
Total sentences: 5585049
Missing embeddings/metrics: 400
Will process: 400

Sentence Results:
  Processed: 400
  Embeddings: 400
  Metrics: 400
  Errors: 0
  Time: 49981.3ms

Processing Documents
----------------------------------------
Total documents: 41
Missing embeddings/metrics: 15
Will process: 15

Document Results:
  Processed: 15
  Embeddings: 15
  Metrics: 15
  Errors: 0
  Time: 8234.5ms

Overall Summary
========================================
Sentences - Processed: 400, Embeddings: 400, Metrics: 400, Errors: 0
Documents - Processed: 15, Embeddings: 15, Metrics: 15, Errors: 0
```
