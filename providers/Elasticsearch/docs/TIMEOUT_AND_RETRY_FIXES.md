# Timeout and Retry Fixes Implementation

## Problem Identified
The indexing process failed with "cURL error 52: Empty reply from server" when processing document 13376, a large document (115KB, ~1,777 sentences). This indicated that Guzzle's default HTTP timeout settings were too restrictive for embedding large documents.

## Root Cause
1. **Default Timeouts Too Short**: Guzzle HTTP client was using default timeouts (~30 seconds)
2. **Large Document Processing**: Documents with 100+ KB and 1,000+ sentences require significant processing time
3. **No Retry Logic**: Single timeout failures caused complete indexing failure

## Solutions Implemented

### 1. Enhanced EmbeddingClient Timeout Configuration

**Updated `src/EmbeddingClient.php`:**
- **Connection timeout**: 10 seconds (time to establish connection)
- **Read timeout**: 5 minutes (300 seconds) for normal requests
- **Dynamic timeout for large batches**: Up to 10 minutes for sentence batches
- **Timeout calculation**: `min(600, max(60, sentenceCount * 2))` seconds
- **Error logging**: Added context-aware error logging with text/sentence counts

**Key improvements:**
```php
// Base configuration
'timeout' => 300,          // 5 minutes total timeout
'connect_timeout' => 10,    // 10 seconds to establish connection
'read_timeout' => 300,      // 5 minutes to read response

// Dynamic timeout for large sentence batches
$estimatedTime = max(60, $sentenceCount * 2);
$timeoutOptions = [
    'timeout' => min(600, $estimatedTime), // Cap at 10 minutes
    'read_timeout' => min(600, $estimatedTime)
];
```

### 2. Retry Logic Implementation

**Updated `src/CorpusIndexer.php`:**
- Added `retryEmbeddingOperation()` method with intelligent retry logic
- Detects retryable errors (timeouts, connection failures, empty replies)
- **1 automatic retry** with 2-second delay between attempts
- Clear logging of retry attempts and failure reasons
- **Fail-fast for non-retryable errors** (e.g., authentication, validation errors)

**Retryable error patterns:**
- `cURL error 52` (Empty reply from server)
- `cURL error 7` (Failed to connect)
- `timeout` (Request timeout)
- `Empty reply` (Server connection dropped)

### 3. Integration Points

**Modified embedding calls:**
```php
// Before: Direct call
$sentenceVectors = $embeddingClient->embedSentences($sentencesToEmbed);

// After: With retry logic
$sentenceVectors = $this->retryEmbeddingOperation(function() use ($embeddingClient, $sentencesToEmbed) {
    return $embeddingClient->embedSentences($sentencesToEmbed);
}, "embedSentences", count($sentencesToEmbed) . " sentences");
```

## Testing Results

### Document 13376 (Previously Failed)
- **Size**: 111,669 characters
- **Processing time**: ~88 seconds total, 27 seconds for sentence embedding
- **Result**: ✅ Successfully indexed
- **Chunks created**: Yes (due to size > 30K characters)

### Document 13115 (Next in sequence)
- **Result**: ✅ Successfully indexed  
- **Processing time**: ~6 seconds total, 1 second for sentence embedding
- **No timeouts or retries needed**

## Performance Impact

**Positive impacts:**
- **No more silent failures** due to timeouts
- **Graceful handling** of temporary connection issues
- **Automatic recovery** from transient network problems
- **Clear error reporting** with context

**Minimal overhead:**
- Retry only triggers on actual failures
- 2-second delay only on retry attempts
- No performance impact on successful operations

## Safety Measures

1. **Maximum retry limit**: 1 retry attempt (2 total attempts)
2. **Timeout caps**: Maximum 10 minutes per request
3. **Error classification**: Only retries connection/timeout errors
4. **Clear logging**: All retry attempts and final failures are logged
5. **Exception preservation**: Original exception is thrown after failed retries

## Expected Benefits

✅ **Eliminates timeout failures** for documents up to ~3,000 sentences  
✅ **Handles temporary service issues** automatically  
✅ **Maintains data integrity** through proper error handling  
✅ **Provides clear diagnostics** for troubleshooting  
✅ **Enables reliable long-running indexing** of large document collections  

The implementation ensures that indexing can continue reliably even with occasional embedding service hiccups or large document processing delays.
