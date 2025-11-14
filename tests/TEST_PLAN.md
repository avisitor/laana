# Noiʻiʻōlelo Test Suite Plan

## Testing Scope

### Components to Test (Using Provider Interface)

#### Core Entry Points
1. **index.php** - Main search interface
   - Default provider selection (from .env or $_REQUEST)
   - Search pattern selection
   - Query parameter handling
   - Provider-specific search modes

2. **api.php** - REST API
   - GET /sources (with/without group parameter)
   - Provider switching via query parameter
   - Error handling

#### Provider Operations (ops/)
3. **ops/getPageHtml.php** - Paginated search results
   - Word search across providers
   - Pattern matching (exact, any, all, regex, hybrid, etc.)
   - Ordering (rand, alpha, date, source, length)
   - Date range filtering
   - Pagination

4. **ops/getPage.php** - Raw sentence retrieval
   - Basic sentence fetching

5. **ops/resultcount.php** - Count matching sentences
   - Count queries across providers
   - Vector search mode handling (-1 for hybrid)

6. **ops/sources.php** - Source listing
   - Provider-based source retrieval

#### Utility Pages
7. **context.php** - Sentence context retrieval
8. **rawpage.php** - Raw document retrieval
9. **groupcounts.php** - Group statistics

### Components to Exclude (Direct DB Access)

- **scripts/** - Data ingestion and processing scripts
  - nupepa_scraper.php
  - savedocument.php
  - updatedocument.php
  - deleteSource.php
  - cleanup.php
- **extract*.php** - Document extraction utilities
- **db/** - Direct database operations
- **ops/parsetext.php** - Text parsing (not provider-dependent)
- **ops/recordsearch.php** - Search logging (database only)

## Test Categories

### 1. Provider Interface Tests
- Laana provider operations
- Elasticsearch provider operations
- Provider switching
- Search mode compatibility

### 2. Search Functionality Tests
- Exact match
- Any word match
- All words match
- Regex patterns
- Hybrid search (Elasticsearch only)
- Phrase search (Elasticsearch only)

### 3. API Endpoint Tests
- REST API endpoints
- Parameter validation
- Error handling
- Response formats

### 4. Integration Tests
- Multi-page result handling
- Date range filtering
- Source grouping
- Count accuracy

## Test Framework

- **PHPUnit** for unit and integration tests
- **JSON output** for programmatic consumption
- **Console summary** for human readability
- **Test fixtures** for consistent test data
