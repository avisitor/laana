# Highlighting Tests Documentation

This document describes the highlighting tests added to the Hawaiian Search System test suite.

## Overview

The highlighting functionality has been extensively tested with three comprehensive test suites that cover all search modes and highlighting scenarios.

## Test Files

### 1. Document-Level Highlighting Tests
**File:** `tests/document_query/test_highlighting.php`  
**Class:** `TestHighlighting`

Tests document-level highlighting across all modes:
- Basic modes: `match`, `phrase`, `term`
- Vector modes: `vector`, `knn` 
- Hybrid mode: `hybrid`
- Regex mode: `regexp`
- Highlighting enabled/disabled scenarios

### 2. Sentence-Level Highlighting Tests  
**File:** `tests/sentence_query/test_sentence_highlighting.php`  
**Class:** `TestSentenceHighlighting`

Tests sentence-level highlighting across all sentence modes:
- Basic sentence modes: `matchsentence`, `termsentence`, `phrasesentence`
- Advanced modes: `matchsentence_all`
- Vector sentence modes: `knnsentence`, `vectorsentence`, `hybridsentence`
- Regex sentence mode: `regexpsentence`
- Inner hits functionality

### 3. QueryBuilder Unit Tests
**File:** `tests/unit/test_querybuilder_highlighting.php`  
**Class:** `TestQueryBuilderHighlighting`

Unit tests for the underlying highlighting logic:
- Highlight configuration structure
- Regex term extraction from patterns
- Highlight query generation
- Sentence-level mode detection
- Various highlighting options

## Running Highlighting Tests

### Using the Main Test Runner

```bash
# List all available tests (highlighting tests will be included)
php run_tests.php --list

# Run all unit tests (includes QueryBuilder highlighting tests)
php run_tests.php --group=unit

# Run all document query tests (includes document highlighting tests)  
php run_tests.php --group=document_query

# Run all sentence query tests (includes sentence highlighting tests)
php run_tests.php --group=sentence_query

# Run specific highlighting tests
php run_tests.php --test=test_highlighting
php run_tests.php --test=test_sentence_highlighting  
php run_tests.php --test=test_querybuilder_highlighting

# Run with verbose output
php run_tests.php --test=test_highlighting --verbose
```

### Using the Convenience Script

```bash
# Run all highlighting tests in sequence
./test_highlighting.sh
```

## Test Coverage

The highlighting tests cover:

✅ **All Search Modes:**
- Document-level: match, phrase, term, regexp, vector, knn, hybrid
- Sentence-level: matchsentence, phrasesentence, termsentence, matchsentence_all, regexpsentence, vectorsentence, knnsentence, hybridsentence

✅ **Highlighting Features:**
- HTML `<mark>` tag generation
- Proper highlight marker placement
- Fragment sizing and numbering
- Pre/post tag configuration
- Diacritic sensitivity options

✅ **Special Cases:**
- Regex term extraction for highlighting
- Vector-based query highlighting
- Nested query highlighting (sentence modes)
- Inner hits highlighting
- Highlighting disabled scenarios

✅ **Integration Points:**
- QueryBuilder highlight configuration
- ElasticsearchClient result processing
- Command-line flag handling

## Test Data

Each test creates its own temporary test index with specialized content designed to trigger highlighting scenarios. The tests clean up after themselves by deleting the test indices.

## Notes

- Some vector-based tests may skip if the embedding service is unavailable
- Regex highlighting tests may not always produce highlights due to the nature of regex matching on raw fields
- All tests are designed to be non-destructive and isolated from production data
