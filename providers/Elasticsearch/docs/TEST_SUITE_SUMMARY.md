
## Production Index Integrity Tests

The test suite now includes critical production index integrity and consistency tests that verify:

### Index Integrity Tests (`test index_integrity`)
- Verifies all production indices exist (main, metadata, source metadata)
- Checks field mappings match expected schema
- Validates document counts and ratios
- Tests sample document structure
- Confirms Elasticsearch connectivity and configuration

### Data Consistency Tests (`test data_consistency`)  
- Verifies processed source IDs have corresponding documents
- Checks that indexed documents are tracked in metadata
- Validates chunk consistency for large documents
- Tests metadata coverage across document samples

### Key Features
- **Read-only**: Production tests never modify production data
- **Safe sampling**: Large datasets are sampled rather than fully scanned
- **Detailed reporting**: Provides specific counts, percentages, and examples
- **Issue detection**: Identifies missing fields, incorrect types, and data inconsistencies

### Usage
```bash
# Run both production integrity tests
php run_tests.php group production --verbose

# Run individual production tests
php run_tests.php test index_integrity --verbose
php run_tests.php test data_consistency --verbose
```

### Current Findings
The production tests have identified:
- ✅ All indices exist and are properly configured
- ✅ 100% data consistency between indices and metadata
- ⚠️ Missing critical metadata fields (`source_id`, `sentence_text`)  
- 🚨 Embedding dimensions showing as 0 (critical issue)

See `PRODUCTION_INDEX_INTEGRITY_REPORT.md` for detailed findings and recommendations.

This completes the comprehensive test suite providing full coverage of unit tests, integration tests, query functionality, and production system health validation.
