# Hawaiian Search System Test Suite

This comprehensive test suite validates the functionality of the Hawaiian Search System PHP components based on the requirements in `HAWAIIAN_SEARCH_FEATURES_AND_REQUIREMENTS.md`.

## Quick Start

### List all available tests
```bash
php run_tests.php list
```

### Run a specific test
```bash
php run_tests.php test elasticsearch_client --verbose
```

### Run a group of tests  
```bash
php run_tests.php group unit --verbose
```

### Run all tests (safe tests only)
```bash
php run_tests.php all --verbose
```

## Test Groups

### Unit Tests (`unit`)
- **elasticsearch_client**: Test ElasticsearchClient class functionality
- **corpus_indexer**: Test CorpusIndexer class methods  
- **metadata_extractor**: Test MetadataExtractor NLP processing
- **embedding_client**: Test EmbeddingClient communication
- **utilities**: Test utility functions and helpers

### Integration Tests (`integration`) 
- **indexing_pipeline**: Test complete document indexing pipeline
- **search_pipeline**: Test search functionality end-to-end
- **metadata_tracking**: Test source metadata persistence
- **chunking_system**: Test document chunking for long texts
- **embedding_integration**: Test embedding service integration

### Document Query Tests (`document_query`)
- **basic_search**: Test basic text search functionality
- **regex_search**: Test regex search on document text
- **metadata_filtering**: Test filtering by document metadata
- **hawaiian_word_search**: Test Hawaiian word detection and search
- **chunked_document_search**: Test search across document chunks

### Sentence Query Tests (`sentence_query`)
- **sentence_regex**: Test regex search within sentences
- **sentence_metadata**: Test sentence-level metadata queries
- **quality_scoring**: Test sentence quality scoring
- **embedding_similarity**: Test sentence similarity search
- **nlp_metrics**: Test NLP metrics extraction

### Indexing Tests (`indexing`) - **Requires `--allow-indexing`**
- **basic_indexing**: Test basic document indexing
- **batch_indexing**: Test batch document processing
- **checkpoint_system**: Test metadata checkpointing
- **error_recovery**: Test indexing error recovery
- **signal_handling**: Test graceful shutdown during indexing
- **recreate_indices**: Test index recreation functionality

### System Tests (`system`) - **Requires `--allow-system-changes`**
- **full_indexing**: Test complete indexing workflow
- **production_search**: Test search against real indices
- **performance_benchmarks**: Test system performance metrics
- **data_integrity**: Test data consistency across indices
- **backup_restore**: Test index backup and restoration

## Safety Features

### Permission Levels
- **Basic tests**: Safe to run anytime, use temporary test indices
- **`--allow-indexing`**: Required for tests that create/modify test indices
- **`--allow-system-changes`**: Required for tests that may affect production indices

### Safe Mode
- All tests use temporary indices with unique names
- Automatic cleanup on completion or interruption
- Signal handling for graceful shutdown (Ctrl-C)
- No modification of production data without explicit permission

## Options

- **`--verbose`**: Enable detailed logging output
- **`--no-cleanup`**: Don't clean up test indices after completion (for debugging)
- **`--timeout=N`**: Set test timeout in seconds (default: 300)
- **`--allow-indexing`**: Allow tests that create/modify test indices
- **`--allow-system-changes`**: Allow tests that may affect production indices

## Test Structure

### Test Files
Tests are organized by category in subdirectories:
- `tests/unit/` - Unit tests for individual classes
- `tests/integration/` - Integration tests for component interactions  
- `tests/document_query/` - Document-level search tests
- `tests/sentence_query/` - Sentence-level search tests
- `tests/indexing/` - Indexing system tests (requires permission)
- `tests/system/` - Full system integration tests (requires permission)

### Test Fixtures  
- `tests/fixtures/` - Sample data for testing
- `tests/tmp/` - Temporary files created during testing

### Base Test Class
All tests extend `BaseTest` which provides:
- Common assertion methods
- Test environment setup
- Logging capabilities  
- Fixture loading
- Cleanup handling

## Example Usage

```bash
# Run all safe tests
php run_tests.php all --verbose

# Run only unit tests
php run_tests.php group unit --verbose  

# Run indexing tests (creates temporary indices)
php run_tests.php group indexing --allow-indexing --verbose

# Run a specific test with debugging
php run_tests.php test regex_search --verbose --no-cleanup

# Run system integration tests (use with caution)
php run_tests.php group system --allow-system-changes --verbose
```

## Writing New Tests

1. Create test file in appropriate subdirectory
2. Extend `BaseTest` class
3. Implement `execute()` method with test logic
4. Use provided assertion methods
5. Add cleanup in `tearDown()` if needed
6. Update test registration in `run_tests.php`

See existing tests for examples and patterns.
