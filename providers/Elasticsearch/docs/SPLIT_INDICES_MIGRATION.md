# Split Indices Migration Guide

## Overview

The Hawaiian Search System has been upgraded to support **Split Indices Architecture** for better scalability and performance. This guide explains how to migrate from the old combined index to the new split indices system.

## Architecture Changes

### Before (Combined Index)
```
hawaiian_docs
├── documents (with nested sentences array)
└── text, metadata, sentences[]
```

### After (Split Indices)  
```
hawaiian_docs (documents index)
├── documents only
└── text, metadata, sentence_count

hawaiian_docs_sentences (sentences index)
├── individual sentences
└── text, vector, doc_id, metadata
```

## Configuration

### Enable Split Indices

Add to your configuration:
```php
$config['USE_SPLIT_INDICES'] = true;
```

### Index Names

The system automatically calculates index names:
- **Documents Index:** Same as your main index name (e.g., `hawaiian_docs`)
- **Sentences Index:** Main index name + `_sentences` (e.g., `hawaiian_docs_sentences`)

### ElasticsearchClient Configuration
```php
$client = new ElasticsearchClient([
    'indexName' => 'hawaiian_docs',  // This becomes the documents index
    'verbose' => true
]);

// System automatically provides:
// - Documents index: hawaiian_docs  
// - Sentences index: hawaiian_docs_sentences
```

### CorpusIndexer Configuration
```php
$config = [
    'COLLECTION_NAME' => 'hawaiian_docs',
    'USE_SPLIT_INDICES' => true,  // Enable split indices mode
    'BATCH_SIZE' => 10,
    'verbose' => true
];

$indexer = new CorpusIndexer($config);
```

## Migration Process

### 1. Prepare Migration

Ensure you have:
- ✅ Backup of your current index
- ✅ Updated code deployed
- ✅ Index mappings available in `/config/`

### 2. Run Migration Script (RESTARTABLE)



**The migration script is RESTARTABLE** - it can be safely interrupted and resumed.

It automatically skips documents that have already been migrated.



**Dry run first (recommended):**

```bash

php scripts/migrate_to_split_indices.php --source-index=hawaiian_docs --dry-run --verbose

```



**Full migration:**

```bash

php scripts/migrate_to_split_indices.php --source-index=hawaiian_docs --verbose

```



**Resume interrupted migration:**

```bash

# Just run the same command again - it will skip already migrated documents

php scripts/migrate_to_split_indices.php --source-index=hawaiian_docs --verbose

```



**Force complete restart (DANGEROUS):**

```bash

php scripts/migrate_to_split_indices.php --source-index=hawaiian_docs --force-recreate --verbose

```



### 3. Migration Script Options

```
--source-index=INDEX    Source index name (default: hawaiian_docs)
--dry-run              Only show what would be done  
--batch-size=SIZE      Documents per batch (default: 100)
--verbose              Enable verbose output
--help                 Show help message
  --force-recreate       Delete and recreate target indices (DANGEROUS)
```

### 4. Verify Migration

The script automatically verifies:
- ✅ Document count matches
- ✅ Sentences are properly indexed
- ✅ Index structure is correct

Manual verification:
```bash
curl -X GET "localhost:9200/hawaiian_docs/_count"
curl -X GET "localhost:9200/hawaiian_docs_sentences/_count"
```

### 5. Update Application Configuration

After successful migration:
```php
$config['USE_SPLIT_INDICES'] = true;
```

## Key Benefits

### 🚀 Performance
- **Faster sentence queries** - Direct queries instead of nested
- **Better indexing speed** - Parallel document and sentence indexing
- **Optimized storage** - No nested structure overhead

### 🎯 Scalability  
- **Independent scaling** - Scale documents vs sentences separately
- **Flexible queries** - Mix document and sentence search modes
- **Better resource usage** - Targeted queries per index type

### 🔧 Maintainability
- **Clear separation** - Documents and sentences logically separated
- **Easier debugging** - Index-specific issues easier to isolate
- **Future-proof** - Ready for additional specialized indices

## Query Behavior

### Automatic Routing
The system automatically routes queries to the correct index:

```php
// Document queries → documents index
$client->search("search term", "match", $options);

// Sentence queries → sentences index  
$client->search("search term", "matchsentence", $options);
```

### Search Modes
- **Document modes:** `match`, `term`, `phrase`, `regexp`
- **Sentence modes:** `matchsentence`, `termsentence`, `phrasesentence`, `regexpsentence`, `vectorsentence`, `knnsentence`

## Troubleshooting

### Migration Issues

**Migration interrupted or failed:**
```bash
# Just re-run the same command - it will resume where it left off
php scripts/migrate_to_split_indices.php --source-index=hawaiian_docs --verbose
```

**Want to start completely fresh:**
```bash
php scripts/migrate_to_split_indices.php --source-index=hawaiian_docs --force-recreate --verbose
```

**"Index already exists" error:**
```bash
curl -X DELETE "localhost:9200/hawaiian_docs_sentences"
# Then re-run migration
```

**Low memory during migration:**
- Reduce `--batch-size` parameter
- Monitor Elasticsearch heap usage

### Query Issues

**Search returns no results:**
- Verify `USE_SPLIT_INDICES=true` in config
- Check index names with: `curl -X GET "localhost:9200/_cat/indices"`

**Performance slower than expected:**
- Ensure proper index mappings are applied
- Verify vector dimensions are correct
- Check Elasticsearch cluster health

## Rollback Plan

To rollback to combined index:

1. **Keep original index** (don't delete during migration)
2. **Set configuration:**
   ```php
   $config['USE_SPLIT_INDICES'] = false;
   ```
3. **Use original index name**

## Advanced Configuration

### Custom Index Names
```php
$client = new ElasticsearchClient([
    'documents_index' => 'custom_docs',
    'sentences_index' => 'custom_sentences'
]);
```

### Production Deployment
1. **Blue-green deployment** - Run migration on standby environment
2. **Gradual rollout** - Test with subset of queries first  
3. **Monitoring** - Watch query performance and error rates
4. **Cleanup** - Remove old indices after verification period

## Support

For issues with migration:
1. Check migration script logs
2. Verify Elasticsearch cluster health
3. Ensure sufficient disk space
4. Monitor memory usage during migration

---

*Split Indices Architecture provides significant performance and scalability improvements for the Hawaiian Search System.*
