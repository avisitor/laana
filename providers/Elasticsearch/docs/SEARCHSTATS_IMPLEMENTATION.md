# Search Statistics Implementation

## Overview

This implementation adds transparent search statistics tracking across both database (Laana) and Elasticsearch search providers.

## Components Added

### 1. Elasticsearch Index Configuration
- **File**: `/var/www/html/elasticsearch/config/searchstats_mapping.json`
- **Purpose**: Defines the Elasticsearch index structure for storing search statistics
- **Fields**:
  - `searchterm`: The search query (text with keyword subfield)
  - `pattern`: Search mode/pattern (keyword)
  - `results`: Number of results returned (integer)
  - `sort`: Sort order used (keyword)
  - `elapsed`: Query execution time in seconds (float)
  - `created`: Timestamp of the search (date)

### 2. ElasticsearchClient Methods
**File**: `/var/www/html/elasticsearch/php/src/ElasticsearchClient.php`

Added methods:
- `getSearchStatsName($indexName = null)`: Returns the search stats index name
- `createSearchStatsIndex($recreate = false, $indexName = "")`: Creates the search stats index
- `addSearchStat($searchterm, $pattern, $results, $order, $elapsed)`: Records a search statistic
- `getSearchStats()`: Retrieves all search statistics ordered by creation date
- `getSummarySearchStats()`: Returns aggregated statistics grouped by pattern
- `getFirstSearchTime()`: Gets the timestamp of the first recorded search

### 3. SearchProviderInterface
**File**: `/var/www/html/noiiolelo/lib/SearchProviderInterface.php`

Added interface methods:
```php
public function addSearchStat(string $searchterm, string $pattern, int $results, string $order, float $elapsed): bool;
public function getSearchStats(): array;
public function getSummarySearchStats(): array;
public function getFirstSearchTime(): string;
```

### 4. LaanaSearchProvider
**File**: `/var/www/html/noiiolelo/lib/LaanaSearchProvider.php`

Implements the search stats methods by delegating to the existing `Laana` class database methods. Updated to match the new interface signatures with proper type hints.

### 5. ElasticsearchProvider
**File**: `/var/www/html/noiiolelo/lib/ElasticsearchProvider.php`

Implements the search stats methods by delegating to the `ElasticsearchClient` methods.

## Usage

### Recording a Search
```php
// Using either provider
$provider->addSearchStat(
    'aloha',           // search term
    'exact',           // pattern/mode
    150,               // number of results
    'date',            // sort order
    0.025              // elapsed time in seconds
);
```

### Retrieving Statistics
```php
// Get all search stats
$allStats = $provider->getSearchStats();

// Get summary by pattern
$summary = $provider->getSummarySearchStats();
// Returns: [['pattern' => 'exact', 'count' => 5], ...]

// Get first search timestamp
$firstSearch = $provider->getFirstSearchTime();
```

## Database vs Elasticsearch

### Database (Laana)
- Uses existing `SEARCHSTATS` table
- Methods in `/var/www/html/noiiolelo/db/funcs.php` (already existed)
- Stores data in MySQL

### Elasticsearch
- Uses new `{index}-searchstats` index (e.g., `hawaiian-searchstats`)
- Auto-creates index on first use
- Provides aggregation capabilities via `getSummarySearchStats()`

## Index Naming Convention

Following the existing pattern:
- Main index: `hawaiian`
- Metadata: `hawaiian-metadata`
- Source metadata: `hawaiian-source-metadata`
- **Search stats**: `hawaiian-searchstats`

## Testing

Run the test script:
```bash
php /var/www/html/elasticsearch/php/test_searchstats.php
```

This creates test data and verifies all CRUD operations work correctly.
