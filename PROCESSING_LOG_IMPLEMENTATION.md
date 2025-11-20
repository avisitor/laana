# Processing Log Implementation

## Overview
This implementation adds comprehensive logging of document fetch/parse/addition operations across both MySQL (Laana) and Elasticsearch backends. The design pushes logging logic into providers to keep top-level code simple and extensible.

## Architecture

### Core Components

1. **ProcessingLogger Trait** (`lib/ProcessingLogger.php`)
   - Provides a shared interface for processing log operations
   - Defines abstract methods that must be implemented by storage backends
   - Includes `loggedOperation()` helper for automatic logging with error handling
   - Reusable across different storage implementations

2. **ElasticsearchProcessingLogger** (`lib/ElasticsearchProcessingLogger.php`)
   - Elasticsearch-specific implementation of processing logging
   - Stores logs in `processing-logs` index
   - Used by ElasticsearchSaveManager and ElasticsearchProvider

3. **Database Table** (`processing_log`)
   - MySQL table for tracking operations in Laana backend
   - Schema defined in `createtables.sql`
   - Fields: log_id, operation_type, source_id, groupname, parser_key, status, sentences_count, started_at, completed_at, error_message, metadata

## Implementation Details

### MySQL Backend (Laana)

**Laana class** (`db/funcs.php`):
- Uses `ProcessingLogger` trait
- Implements `*Impl` methods to handle MySQL storage
- Methods: `startProcessingLog()`, `completeProcessingLog()`, `getProcessingLogs()`

**SaveManager** (`scripts/saveFuncs.php`):
- Delegates logging to Laana instance
- Tracks operations in `saveContents()` and `getAllDocuments()`
- Logs include operation type, source ID, groupname, parser key, and metadata

**LaanaSearchProvider** (`lib/LaanaSearchProvider.php`):
- Exposes `getProcessingLogger()` method that returns Laana instance
- Allows external code to access logging capabilities

### Elasticsearch Backend

**ElasticsearchProcessingLogger** (`lib/ElasticsearchProcessingLogger.php`):
- Standalone class for Elasticsearch logging
- Stores in `processing-logs` index (separate from document indices)
- Implements same interface as MySQL version via ProcessingLogger trait

**ElasticsearchSaveManager** (`scripts/ElasticsearchSaveManager.php`):
- Uses ElasticsearchProcessingLogger instance
- No MySQL dependency
- Uses `loggedOperation()` helper for clean, functional-style logging

**ElasticsearchProvider** (`lib/ElasticsearchProvider.php`):
- Exposes `getProcessingLogger()` method
- Returns ElasticsearchProcessingLogger instance
- Allows other code to log operations through the provider

## Usage Examples

### Tracking a Single Operation

```php
// MySQL backend
$laana = new Laana();
$logId = $laana->startProcessingLog('save_contents', $sourceID, $groupname, $parserKey);
// ... perform operation ...
$laana->completeProcessingLog($logId, 'completed', $sentenceCount);

// Elasticsearch backend
$logger = new ElasticsearchProcessingLogger($esClient);
$logId = $logger->startProcessingLog('save_contents', $sourceID, $groupname, $parserKey);
// ... perform operation ...
$logger->completeProcessingLog($logId, 'completed', $sentenceCount);
```

### Using loggedOperation() Helper

```php
$result = $processingLogger->loggedOperation(
    'save_contents',
    function() use ($parser, $sourceID) {
        // Perform the actual work
        return $this->indexSentences($parser, $sourceID);
    },
    [
        'sourceID' => $sourceID,
        'groupname' => $groupname,
        'parserKey' => $parser->logName,
        'metadata' => ['link' => $link, 'force' => $force]
    ]
);
```

### Querying Logs

```php
// Get recent failed operations
$logs = $laana->getProcessingLogs([
    'status' => 'failed',
    'limit' => 10
]);

// Get logs for specific groupname
$logs = $logger->getProcessingLogs([
    'groupname' => 'nupepa',
    'limit' => 50
]);
```

## Log Entry Structure

Each log entry contains:
- `log_id`: Unique identifier
- `operation_type`: Type of operation (e.g., 'save_contents', 'get_all_documents')
- `source_id`: ID of the document being processed (if applicable)
- `groupname`: Document collection/group name
- `parser_key`: Parser used for the operation
- `status`: 'started', 'completed', or 'failed'
- `sentences_count`: Number of sentences processed
- `started_at`: Timestamp when operation began
- `completed_at`: Timestamp when operation finished
- `error_message`: Error details (if failed)
- `metadata`: Additional context (JSON/text)

## Benefits

1. **Separation of Concerns**: Logging logic is in providers, not in SaveManagers
2. **Extensibility**: Easy to add new storage backends by implementing ProcessingLogger trait
3. **No Cross-Dependencies**: ElasticsearchSaveManager doesn't depend on MySQL
4. **Consistent Interface**: Same logging API across different backends
5. **Error Tracking**: Automatic error capture and logging
6. **Audit Trail**: Complete history of all processing operations
7. **Debugging**: Easy to identify failed operations and their causes

## Database Setup

To create the MySQL processing_log table:

```sql
mysql -u username -p database_name < createtables.sql
```

Or run the specific CREATE TABLE statement from `createtables.sql`.

## Future Enhancements

1. Add processing log queries to web interface
2. Implement log retention/archival policies
3. Add performance metrics (processing time, throughput)
4. Create dashboard for monitoring operations
5. Add alerts for repeated failures
