# Search Modes Test Analysis

## Problem Statement
The regexp query functionality was broken for two days of reindexing efforts. This analysis documents the issues found and the comprehensive test coverage needed to prevent regression.

## Issues Discovered

### 1. QueryBuilder Field Name Mismatch
**Problem**: QueryBuilder was using `text_chunks.text.raw` but the actual mapping uses `text_chunks.chunk_text.raw`
**Impact**: All text_chunks-based regexp queries failed silently
**Root Cause**: Field name inconsistency between QueryBuilder and index mapping

### 2. Missing text_chunks Fallback  
**Problem**: No documents currently have `text_chunks` populated, but QueryBuilder assumed they existed
**Impact**: Document-level regexp queries failed completely
**Root Cause**: QueryBuilder should fall back to `text.raw` when `text_chunks` don't exist

### 3. Argument Parsing in query.php
**Problem**: Using `--query=pattern` format instead of `"pattern" --mode=regexp` 
**Impact**: Query text included command-line flags, causing search failures
**Root Cause**: Incorrect CLI usage documentation and examples

## Solutions Applied

### 1. Fixed Field Names
- Updated `regexpQuery()` to use `text.raw` for document-level search
- Updated `regexpsentenceQuery()` to use `sentences.text.raw` for sentence-level search
- Removed dependency on non-existent `text_chunks.chunk_text.raw`

### 2. Verified Field Mapping
- Confirmed `text.raw` exists and supports regex (keyword field, ignore_above: 50000)
- Confirmed `sentences.text.raw` exists for sentence-level regex
- Verified proper regex functionality with production data

## Current Search Modes Status

✅ **Working Modes** (7/12):
- `match` - Basic text matching  
- `term` - Exact term matching
- `regexp` - Document-level regex (FIXED)
- `regexpsentence` - Sentence-level regex (FIXED)
- `wildcard` - Wildcard pattern matching
- `vector` - Vector similarity search
- `knn` - K-nearest neighbor search

❌ **Needs Investigation** (5/12):
- `phrase` - Phrase matching (may need quoted phrases)
- `hybrid` - Text + vector hybrid search
- `vectorsentence` - Sentence vector search  
- `hybridsentence` - Hybrid sentence search
- `knnsentence` - Sentence k-NN search

## Test Coverage Required

### Comprehensive Search Modes Test
A test that validates ALL 12 search modes defined in QueryBuilder::MODES:

```php
// Should test each mode with appropriate queries
$testCases = [
    'match' => 'aloha',
    'term' => 'hawaiian', 
    'phrase' => '"test document"',  // Note: may need quotes
    'regexp' => '.*mea.*',
    'regexpsentence' => '.*aloha.*',
    'wildcard' => '*island*',
    'vector' => 'hawaiian culture',
    'hybrid' => 'aloha islands', 
    'vectorsentence' => 'welcome greeting',
    'hybridsentence' => 'aloha mahalo',
    'knn' => 'island geography',
    'knnsentence' => 'hawaiian greeting'
];
```

### Regexp-Specific Tests
- Field mapping validation (text.raw, sentences.text.raw exist)
- QueryBuilder query generation correctness
- Actual search results with known patterns
- Performance testing with complex patterns
- Error handling for invalid patterns

### Integration Tests  
- Test with production data
- Test with test indices using production mappings
- Verify chunk-based search when text_chunks exist
- Test sentence-level vs document-level results

## Recommendations

1. **Add Comprehensive Test**: Create `test_all_search_modes.php` that validates every mode
2. **Fix Remaining Modes**: Investigate and fix the 5 failing search modes
3. **Automate Testing**: Run search mode tests in CI/CD before deployments
4. **Documentation**: Update query.php usage examples with correct syntax
5. **Monitoring**: Add search mode success/failure metrics to production monitoring

## Test Commands That Would Have Caught This

```bash
# Basic search mode validation
php test_search_modes_simple.php

# Comprehensive test suite  
php run_tests.php group document_query --allow-indexing

# Individual regexp testing
php query.php ".*mea.*" --mode=regexp
php query.php ".*aloha.*" --mode=regexpsentence
```

## Conclusion

The regexp functionality is now working correctly, but this incident reveals the need for:
1. Comprehensive automated testing of all search modes
2. Better field name consistency between QueryBuilder and mappings  
3. Proper fallback handling when expected fields don't exist
4. Clear documentation of correct query syntax

The test harness should include these tests to prevent similar regressions in the future.
