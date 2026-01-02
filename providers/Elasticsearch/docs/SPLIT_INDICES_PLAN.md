# Split Indices Implementation Plan

## Overview
Split the current combined Elasticsearch index into separate **documents** and **sentences** indices for better scalability and performance. Remove wildcard field support since regexp search is fully functional.

## New Index Structure

### 1. Documents Index (`hawaiian_docs`)
**Purpose**: Full-text document search, metadata, and text chunks
**Mapping**: `config/documents_mapping.json`
**Key Fields**:
- Document metadata (doc_id, sourcename, groupname, authors, date, etc.)
- Full text with regexp support (text.raw, text.folded)
- Text chunks for long documents
- Document-level vectors and metadata
- Sentence count, entity count, boilerplate score

### 2. Sentences Index (`hawaiian_sentences`) 
**Purpose**: Sentence-level search with vectors and quality metadata
**Mapping**: `config/sentences_mapping.json`
**Key Fields**:
- Sentence text with regexp support (text.raw, text.folded)
- Sentence vectors for similarity search
- Quality metadata (frequency, hawaiian_word_ratio, entity_count, boilerplate_score)
- Links to parent document via doc_id

### 3. Source Metadata Index (unchanged)
**Purpose**: Processing state and source tracking
**Mapping**: Existing source metadata structure

## Code Changes Required

### Phase 1: Core Infrastructure

#### 1.1 Update ElasticsearchClient.php
**File**: `php/src/ElasticsearchClient.php`

**Changes**:
```php
// Add new index name properties
private string $documentsIndexName;
private string $sentencesIndexName;

// Update constructor to handle multiple indices
public function __construct(array $options = []) {
    // Set index names based on config
    $this->documentsIndexName = $options['documents_index'] ?? 'hawaiian_docs';
    $this->sentencesIndexName = $options['sentences_index'] ?? 'hawaiian_sentences';
    // Keep existing indexName for backward compatibility
}

// Add getters for new index names
public function getDocumentsIndexName(): string
public function getSentencesIndexName(): string

// Update createIndex method to create both indices
public function createIndex(bool $recreate = false, string $indexType = 'all'): void
// Support: 'all', 'documents', 'sentences', 'source-metadata'

// Update indexDocument to split data between indices
public function indexDocuments(string $docId, array $documentData): void
public function indexSentences(string $docId, array $sentences): void

// Replace the current indexDocument method with:
public function indexDocumentAndSentences(string $docId, array $sourceData, string $text, array $sentences, float $hawaiianWordRatio): void
```

#### 1.2 Update QueryBuilder.php  
**File**: `php/src/QueryBuilder.php`

**Changes**:
```php
// Remove wildcard mode from MODES array
public const MODES = ["matchsentence", "matchsentence_all", "termsentence", "phrasesentence", "match", "term", "phrase", "regexp", "regexpsentence", "vector", "hybrid", "vectorsentence", "hybridsentence", "knn", "knnsentence"];

// Remove wildcardQuery method
// Update build() method to route queries to appropriate index

// Add index routing logic
private function getTargetIndex(string $mode): string {
    $sentenceModes = ['matchsentence', 'matchsentence_all', 'termsentence', 'phrasesentence', 'regexpsentence', 'vectorsentence', 'hybridsentence', 'knnsentence'];
    return in_array($mode, $sentenceModes) ? 'sentences' : 'documents';
}

// Update all sentence query methods to remove nested structure
// Example transformation:
// OLD: nested.path = "sentences", nested.query = {...}
// NEW: direct query on sentences index

public function matchsentenceQuery(string $text, array $options = []): array {
    // Remove nested wrapper, query directly against sentences index
    return [
        "match" => [
            "text.folded" => [
                "query" => $text,
                "operator" => "or"
            ]
        ]
    ];
}
```

#### 1.3 Update CorpusIndexer.php
**File**: `php/src/CorpusIndexer.php`

**Changes**:
```php
// Update indexDocument calls to use new split methods
// In processSource method, replace:
// $this->esClient->indexDocument(...)
// With:
$this->esClient->indexDocumentAndSentences(...)

// Update bulk indexing to handle both indices
private function bulkIndexDocuments(array $documentActions): void
private function bulkIndexSentences(array $sentenceActions): void

// Modify sentence processing to include metadata
private function processSentencesInBatches(array $sentenceTexts, string $sourceid): array {
    // Include sentence metadata (frequency, quality scores) with each sentence
}
```

### Phase 2: Search Interface Updates

#### 2.1 Update Search Method in ElasticsearchClient.php
```php
public function search(string $query, string $mode, array $options = []): ?array {
    $targetIndex = $this->queryBuilder->getTargetIndex($mode);
    $indexName = match($targetIndex) {
        'sentences' => $this->sentencesIndexName,
        'documents' => $this->documentsIndexName,
        default => $this->indexName // fallback
    };
    
    // Build and execute query against appropriate index
    // Update result formatting based on index type
}
```

#### 2.2 Update Result Formatting
```php
private function formatResults(array $response, string $mode, array $sortOptions = []): array {
    $targetIndex = $this->queryBuilder->getTargetIndex($mode);
    
    if ($targetIndex === 'sentences') {
        return $this->formatSentenceResults($response, $mode, $sortOptions);
    } else {
        return $this->formatDocumentResults($response, $mode, $sortOptions);
    }
}

private function formatSentenceResults(array $response, string $mode, array $sortOptions = []): array
private function formatDocumentResults(array $response, string $mode, array $sortOptions = []): array
```

### Phase 3: Configuration and Setup

#### 3.1 Update Configuration Files
**Files**: 
- `config/elasticsearch.json` (if exists)
- Update any hardcoded index names in scripts

#### 3.2 Create Migration Script
**File**: `scripts/migrate_to_split_indices.php`

#!/usr/bin/env php
<?php
// Script to migrate from combined index to split indices
// 1. Create new indices
// 2. Copy data from old index to new indices
// 3. Validate data integrity
// 4. Optional: Remove old index
```

#### 3.3 Update Tests
**Files**: All test files in `php/tests/`

- Update test data creation to use new index structure
- Modify search expectations for new result formats
- Update any hardcoded index names

### Phase 4: Cleanup and Optimization

#### 4.1 Remove Wildcard Support
- Remove all wildcard field mappings from new indices
- Remove wildcardQuery method from QueryBuilder
- Update any references to wildcard fields

#### 4.2 Performance Optimizations
- Optimize sentence index for vector similarity, regexp, and full-text searches
- Optimize document index for full-text, regexp, and vector searches
- Review and optimize field mappings for storage efficiency

## Migration Strategy

### Step 1: Preparation
1. Create new index mappings
2. Update code but keep backward compatibility
3. Add configuration options for index names

### Step 2: Deploy with Dual Support
1. Deploy updated code with both old and new index support
2. Test thoroughly with existing index
3. Create new indices alongside existing ones

### Step 3: Data Migration
1. Run migration script to populate new indices
2. Verify data integrity and search functionality
3. Run full test suite against new indices

### Step 4: Switch Over
1. Update configuration to use new indices
2. Monitor performance and functionality
3. Remove old index after validation period

## Testing Strategy

### Unit Tests
- Test QueryBuilder with new index routing
- Test ElasticsearchClient methods with split indices
- Test result formatting for both index types

### Integration Tests
- Test full search pipeline with both indices
- Test indexing pipeline with split data
- Test performance with realistic data volumes

### Performance Tests
- Compare search performance before/after split
- Test vector similarity search on sentences index
- Test document search performance

## Risk Mitigation

### Data Loss Prevention
- Keep existing index until migration is fully validated
- Implement rollback procedures
- Test migration on non-production data first

### Compatibility
- Maintain backward compatibility during transition
- Version control all configuration changes
- Document all breaking changes

### Performance
- Monitor query performance during migration
- Have rollback plan if performance degrades
- Test with production data volumes

## File Checklist

### New Files
- [ ] `config/documents_mapping.json`
- [ ] `config/sentences_mapping.json`
- [ ] `scripts/migrate_to_split_indices.php`
- [ ] `SPLIT_INDICES_PLAN.md` (this file)

### Modified Files
- [ ] `php/src/ElasticsearchClient.php`
- [ ] `php/src/QueryBuilder.php`  
- [ ] `php/src/CorpusIndexer.php`
- [ ] All test files in `php/tests/`
- [ ] Any configuration files with index names

### Deprecated Files
- [ ] `config/index_mapping.json` (replace with documents_mapping.json)
- [ ] `config/metadata_mapping.json` (merge into sentences_mapping.json)

## Success Criteria

1. ✅ All existing search modes work with new indices
2. ✅ Indexing process successfully splits data
3. ✅ Performance is maintained or improved
4. ✅ All tests pass with new structure
5. ✅ Wildcard dependencies are removed
6. ✅ Data integrity is maintained during migration

## Migration Strategy Details

### Leveraging Existing Infrastructure

The migration script will maximize reuse of existing code by:

#### ElasticsearchClient Integration
```php
// Add migration-specific methods that build on existing functionality
public function createIndex(bool $recreate = false, string $indexType = 'all'): void {
    // Existing logic + new index type support
    switch ($indexType) {
        case 'documents':
            $this->createDocumentsIndex($recreate);
            break;
        case 'sentences':  
            $this->createSentencesIndex($recreate);
            break;
        case 'all':
            $this->createDocumentsIndex($recreate);
            $this->createSentencesIndex($recreate);
            $this->createSourceMetadataIndex($recreate);
            break;
    }
}

// Migration validation using existing patterns
public function validateMigration(): array {
    return [
        'documents' => $this->validateDocumentsIndex(),
        'sentences' => $this->validateSentencesIndex(),  
        'integrity' => $this->validateDataIntegrity()
    ];
}
```

#### CorpusIndexer Integration
```php
// Add migration method that reuses existing processing logic
public function migrateDocument(array $sourceData, string $docId): void {
    // Reuse existing text processing and sentence splitting
    $sentences = $this->splitIntoSentences($sourceData['text']);
    $hawaiianWordRatio = $this->calculateHawaiianWordRatio($sourceData['text']);
    
    // Reuse existing embedding and metadata extraction
    $this->processSentencesInBatches($sentences, $docId);
    
    // Use new split indexing method
    $this->esClient->indexDocumentAndSentences($docId, $sourceData, $sourceData['text'], $sentences, $hawaiianWordRatio);
}
```

#### ElasticSearchScrollIterator Reuse
- Use existing scroll iterator for efficient batch processing
- Leverage existing error handling and retry logic
- Maintain existing performance characteristics

### Performance Optimization Details

#### Sentences Index Optimization
```json
{
  "settings": {
    "number_of_shards": 3,
    "number_of_replicas": 1,
    "index": {
      // Optimize for mixed workload: vectors + text search
      "codec": "best_compression",
      "max_result_window": 50000,
      // Vector search optimization
      "knn_algorithm": "hnsw",
      // Text search optimization  
      "search.idle.after": "30s",
      "refresh_interval": "5s"
    }
  }
}
```

#### Documents Index Optimization  
```json
{
  "settings": {
    "number_of_shards": 2, 
    "number_of_replicas": 1,
    "index": {
      // Optimize for full-text and regexp searches
      "max_regex_length": 10000,
      "highlight.max_analyzed_offset": 1000000,
      // Also support document-level vectors
      "knn_algorithm": "hnsw"
    }
  }
}
```

### Additional Migration Methods

#### Data Integrity Validation
```php
// Add to ElasticsearchClient
private function validateDataIntegrity(): array {
    $oldIndex = $this->indexName;
    $docCount = $this->getTotalDocumentCount();
    $sentenceCount = $this->getTotalSentenceCount();
    
    // Validate counts match between old and new indices
    $docsIndexCount = $this->getRawClient()->count(['index' => $this->documentsIndexName]);
    $sentencesIndexCount = $this->getRawClient()->count(['index' => $this->sentencesIndexName]);
    
    return [
        'document_count_match' => $docCount === $docsIndexCount['count'],
        'sentence_count_match' => $sentenceCount === $sentencesIndexCount['count'],
        'sample_searches' => $this->validateSampleSearches()
    ];
}
```

This approach ensures:
1. **Minimal custom migration code** - reuses 80%+ of existing logic
2. **Consistent data processing** - same algorithms for text processing, embeddings, etc.
3. **Proven error handling** - leverages existing retry logic and error handling
4. **Performance** - uses existing optimized batch processing
5. **Testability** - can test migration using existing test patterns

