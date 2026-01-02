# Hawaiian Search Documentation

This directory contains all documentation for the Hawaiian Search system. The executable scripts are located in the `../scripts/` directory.

## Available Scripts (in ../scripts/)


This directory contains consolidated testing and analysis tools for the Hawaiian Search system. These scripts have been consolidated from numerous individual debug, test, and utility files to provide comprehensive functionality with better organization.

## Available Scripts

### 1. search_mode_tester.php
Tests all Hawaiian Search search modes with various query options.

**Key Features:**
- Test individual modes or all modes at once
- Configurable result limits and verbosity
- Performance timing for each mode
- Support for testing only "fixed" modes that work correctly
- Comprehensive results summary

**Examples:**
```bash
php search_mode_tester.php --query='hawaii' --mode=term
php search_mode_tester.php --all-modes --verbose
php search_mode_tester.php --modes=term,phrase,regexp --limit=10
```

### 2. regex_tester.php
Comprehensive regex functionality testing including chunked document processing.

**Key Features:**
- Test individual regex patterns or load from file
- Support for chunked document testing
- Create temporary test indices
- Relevance scoring analysis
- Pattern matching against specific documents

**Examples:**
```bash
php regex_tester.php --pattern='ka.*poe' --limit=5
php regex_tester.php --pattern='[aeiou]+' --chunked --doc-id=24688
php regex_tester.php --relevance-test --patterns-file=patterns.txt
```

### 3. index_operations.php
Elasticsearch index management and bulk operations.

**Key Features:**
- List and analyze indices
- Create/delete indices with Hawaiian Search mappings
- Bulk index documents from JSON files
- Check field size limits and ignore_above settings
- Performance analysis and monitoring

**Examples:**
```bash
php index_operations.php --list-indices
php index_operations.php --create-index=test_hawaiian --verbose
php index_operations.php --bulk-index=documents.json --batch-size=50
php index_operations.php --check-limits --index=hawaiian_test
```

### 4. document_analyzer.php
Analyze specific documents and their processing behavior.

**Key Features:**
- Document chunking analysis
- Search performance testing against specific documents
- Field content analysis
- Document reprocessing
- Export analysis results

**Examples:**
```bash
php document_analyzer.php --doc-id=24688 --analyze-chunks
php document_analyzer.php --doc-id=24688 --search-test='ka poe'
php document_analyzer.php --reprocess=24688 --verbose
php document_analyzer.php --field-analysis --export=analysis.json
```

### 5. performance_tester.php
Comprehensive performance testing and benchmarking.

**Key Features:**
- Hawaiian word lookup benchmarks
- Indexing performance tests with controlled datasets
- Production-like performance testing
- System configuration validation
- Memory allocation testing
- Export performance results

**Examples:**
```bash
php performance_tester.php --word-benchmark --iterations=20
php performance_tester.php --indexing-performance --doc-count=500
php performance_tester.php --all-tests --verbose --export=perf_results.json
php performance_tester.php --config-test
```

### 6. metadata_manager.php
Metadata index management, migration, and testing.

**Key Features:**
- Rebuild metadata indices from scratch
- Migrate metadata between index formats
- Test metadata retrieval functionality
- Validate metadata consistency
- Export/import metadata for backup/restore
- Direct metadata testing

**Examples:**
```bash
php metadata_manager.php --rebuild-index --verbose
php metadata_manager.php --migrate-source --source-index=old_hawaiian
php metadata_manager.php --test-retrieval --batch-size=50
php metadata_manager.php --validate-metadata --export-metadata=backup.json
```

### 7. system_diagnostics.php
Comprehensive system diagnostics and health checks.

**Key Features:**
- PHP and system configuration validation
- Elasticsearch connection and cluster health testing
- Index structure and health analysis
- Query functionality testing across all modes
- Field content analysis and mapping validation
- Performance optimization recommendations
- Issue detection and reporting

**Examples:**
```bash
php system_diagnostics.php --all-diagnostics --verbose
php system_diagnostics.php --index-health --index=hawaiian_prod
php system_diagnostics.php --query-tests --sample-size=50
php system_diagnostics.php --config-check --fix-issues
```

## Common Options

All scripts support these common options:
- `--help` - Show detailed usage information
- `--verbose` - Show detailed output and debug information
- `--index=NAME` - Specify which Elasticsearch index to use
- `--dry-run` - Preview operations without executing (where applicable)
- `--export=FILE` - Export results to JSON file (where applicable)

## Consolidation Summary

This consolidation replaced **55 individual files** with **7 comprehensive scripts**:

### Original Files Consolidated (48 files removed):

**Search Mode Testing (8 files):**
- debug_remaining_modes.php, debug_search_modes.php
- test_all_modes*.php, test_*modes*.php, test_search_modes_simple.php

**Regex Testing (8 files):**
- debug_*regexp*.php, debug_*regex*.php
- test_*regex*.php, test_*regexp*.php, test_working_regex_patterns.php
- test_chunk_based_regex.php, test_complex_regex.php

**Index Operations (4 files):**
- test_bulk_index.php, debug_index_parameter.php, debug_bulk_size.php
- test_ignore_above_limits.php, test_text_raw_exists.php

**Document Analysis (3 files):**
- test_reprocess_24688.php, debug_24688.php, analyze_sentence_data.php

**Performance Testing (3 files):**
- benchmark_improvements.php, focused_perf_test.php, production_performance_test.php

**Metadata Management (3 files):**
- direct_metadata_test.php, migrate_source_metadata.php, rebuild_metadata_index.php

**System Diagnostics (19 files):**
- All remaining test_*.php and debug_*.php files for configuration, optimization, 
  field testing, connection testing, query validation, etc.

### Files Retained:

- **Documentation:** README.md, README_metadata_implementation.md, REFACTORING_SUMMARY.md, COMPREHENSIVE_DUPLICATION_ANALYSIS.md
- **Python Utility:** nlp_enhancer.py
- **New Consolidated Scripts:** 7 comprehensive tools

### Net Result:
- **Before:** 55 individual files
- **After:** 7 scripts + 5 documentation/utility files = 12 total files
- **Reduction:** 43 files eliminated (**78% reduction**)

## Design Philosophy

Each consolidated script follows these principles:

1. **Single Responsibility:** Each script handles one major functional area
2. **Command-Line Interface:** Full argument parsing with comprehensive help
3. **Flexible Options:** Multiple operational modes with sensible defaults
4. **Error Handling:** Graceful error handling with informative messages
5. **Verbose Mode:** Detailed output available for debugging and analysis
6. **Export Capabilities:** Results can be saved for later analysis
7. **Dry Run Support:** Preview operations before execution where applicable

## Usage Tips

1. **Always start with `--help`** to understand available options
2. **Use `--verbose`** when debugging or learning what the script does
3. **Test with small datasets** first using limit options
4. **Use `--dry-run`** where available to preview operations
5. **Export results** with `--export` options for analysis and reporting
6. **Combine operations** - many scripts support multiple operations in one run

## Migration Guide

If you were using the old individual scripts:

- **debug_*/test_* files** → Use corresponding consolidated script with appropriate options
- **Performance tests** → `performance_tester.php --all-tests`
- **Index operations** → `index_operations.php` with specific operation flags
- **Metadata tasks** → `metadata_manager.php` with appropriate operation
- **System checks** → `system_diagnostics.php --all-diagnostics`

This consolidation dramatically reduces maintenance overhead while providing more powerful, flexible, and user-friendly testing capabilities.

## PHP Component Documentation

### Recently Added Files:
- **tests_README.md** - Complete testing guide and test suite overview for the PHP components
- **php_TEST_SUITE_SUMMARY.md** - Detailed summary of all PHP test cases and their purposes
- **MEMORY_MANAGEMENT_IMPROVEMENTS.md** - Memory optimization improvements for the embedding service
- **embedding_service_requirements.txt** - Python dependencies for the embedding service
- **php_README.md** - PHP-specific documentation index

### Testing Documentation:
The PHP testing system includes comprehensive unit, integration, and system tests. See `tests_README.md` for detailed information about running and understanding the test suite.

### Technical Improvements:
Recent memory management improvements are documented in `MEMORY_MANAGEMENT_IMPROVEMENTS.md`, which details optimization strategies implemented in the embedding service.
