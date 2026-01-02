# Vector Null Value Fix for CorpusIndexer

## Problem
Elasticsearch indexing was failing with the error:
```
mapper [sentences.vector] cannot be changed from type [dense_vector] to [float]
```

This error occurred because `null` values were being assigned to vector fields that are mapped as `dense_vector` type in Elasticsearch.

## Root Cause
The issue was in `/var/www/html/elasticsearch/php/src/CorpusIndexer.php` at line 601 where `$s['vector'] = null` was being assigned when there was a mismatch between sentence texts and embedding vectors.

## Fixes Applied

### 1. Fixed Null Vector Assignment
**Location**: `CorpusIndexer.php` line ~600
**Before**: 
```php
$s['vector'] = null; // Ensure field exists even if null
```
**After**: 
```php
// Skip sentences with invalid vectors instead of assigning null
if (isset($s['text']) && isset($newSentenceVectors[$vectorIdx]) && 
    is_array($newSentenceVectors[$vectorIdx]) && !empty($newSentenceVectors[$vectorIdx])) {
    $s['vector'] = $newSentenceVectors[$vectorIdx];
    $validSentenceObjects[] = $s;
} else {
    $this->print("⚠️ Skipping sentence with missing/invalid vector...");
}
```

### 2. Added Vector Validation in Sentence Processing
**Location**: `CorpusIndexer.php` line ~490
**Added**: Validation to ensure vectors are valid arrays before creating sentence objects:
```php
// Validate vector before using it
if (!isset($sentenceVectors[$vectorIdx]) || !is_array($sentenceVectors[$vectorIdx]) || empty($sentenceVectors[$vectorIdx])) {
    $this->print("⚠️ Skipping sentence at index {$vectorIdx} - invalid or missing vector");
    $vectorIdx++;
    continue;
}
```

### 3. Enhanced Batch Vector Validation
**Location**: `CorpusIndexer.php` line ~470
**Added**: Validation after batch embedding operations:
```php
// Validate batch vectors before merging
if (!is_array($batchVectors)) {
    $this->print("⚠️ Embedding service returned invalid result");
    continue; // Skip this batch
}

// Filter out any null/invalid vectors
$validBatchVectors = [];
foreach ($batchVectors as $idx => $vector) {
    if (is_array($vector) && !empty($vector)) {
        $validBatchVectors[] = $vector;
    } else {
        $this->print("⚠️ Skipping invalid vector at batch index {$idx}");
    }
}
```

### 4. Improved ElasticsearchClient Vector Validation
**Location**: `ElasticsearchClient.php` `validateVectorDimensions()` method
**Added**: Type checking before calling `count()` on vectors:
```php
if (!is_array($action['_source']['text_vector'])) {
    throw new \RuntimeException("Text vector must be an array, got: " . gettype($action['_source']['text_vector']));
}
```

### 5. Improved Source Metadata Tracking
**Added**: 
- `private array $config;` - Configuration array property
- English-only tracking directly in `$sourceMeta` instead of separate `$meta` array:
  ```php
  $this->sourceMeta[$sourceid]['english_only'] = true;
  ```

## Result
- Prevents `null` values from being assigned to dense_vector fields
- Provides early validation and clear error messages
- Skips invalid vectors instead of causing indexing failures
- Maintains data integrity by only processing valid embeddings

## Testing
The fix should resolve the `illegal_argument_exception` error and allow document 45127 and similar documents to index successfully without vector type conflicts.
