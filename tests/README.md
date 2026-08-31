# Noiiolelo Test Suite

Comprehensive functional test suite for the Noiiolelo Hawaiian text search application.

## Overview

This test suite validates the core functionality of Noiiolelo through PHPUnit tests, focusing on:
- Provider interface and switching
- Search functionality across different modes
- API endpoints
- Integration between components

## Test Structure

```
tests/
├── bootstrap.php              # Test initialization and helpers
├── run-tests.sh               # Test runner with console + report output
├── test-report.html           # HTML report viewer
├── reports/                   # Generated run reports (timestamped, auto-cleaned)
├── Provider/                  # Provider interface tests (incl. Elasticsearch/OpenSearch subdirs)
├── Search/                    # Search functionality tests
├── API/                       # API endpoint tests
├── Integration/               # End-to-end workflow tests
├── Database/                  # Database-layer tests
├── Minimal/                   # Minimal smoke tests
└── Indexing/                  # Indexing tests (ElasticsearchClient, iterators, highlighting, ...)
```

Suites are configured in the repo-root `phpunit.xml`: `Provider`, `Search`,
`API`, `Integration`, `Indexing`. Provider/Indexing tests run against the
live services from `.env` and skip when those are not reachable.

## Running Tests

### Quick Start

```bash
cd /var/www/html/noiiolelo
./tests/run-tests.sh
```

### Run Specific Test Suite

```bash
# Provider tests only
./vendor/bin/phpunit --testsuite Provider

# Search tests only
./vendor/bin/phpunit --testsuite Search

# API tests only
./vendor/bin/phpunit --testsuite API

# Integration tests only
./vendor/bin/phpunit --testsuite Integration
```

### Run Specific Test Class

```bash
./vendor/bin/phpunit tests/Provider/ProviderInterfaceTest.php
```

### Run with Verbose Output

```bash
./vendor/bin/phpunit --testdox --verbose
```

## Test Output

The test runner produces:

1. **Console Output**: Colorized summary with pass/fail counts
2. **JUnit XML**: `tests/reports/junit-<timestamp>.xml`
3. **Console transcript**: `tests/reports/test-report-<timestamp>.txt`

Reports older than 7 days are cleaned up automatically after each run.

### Viewing HTML Report

After running tests, open the HTML report:

```bash
# Simple HTTP server (Python 3)
cd /var/www/html/noiiolelo/tests
python3 -m http.server 8080
# Visit http://localhost:8080/test-report.html
```

Or access via your web server if configured.

## Test Categories

### Provider Tests
- Provider loading and initialization
- Provider switching
- Required method implementation
- Search mode availability
- Corpus statistics

### Search Tests
- Exact match search
- Any word search
- All words search
- Regular expression search
- Full-text match search
- Phrase search
- Hybrid/vector search
- Pagination
- Result counting

### API Tests
- Sources endpoint
- Result count endpoint
- Page HTML endpoint
- Provider parameter handling
- Error handling

### Integration Tests
- End-to-end search workflows
- Provider consistency
- Pagination and ordering
- Multiple consecutive requests
- Vector search integration

### Indexing Tests
- ElasticsearchClient operations (bulk, delete, grammar patterns/matches)
- Index schema and alias management
- Source/document iterators
- Highlighting behavior

## Requirements

- PHP 7.4 or higher
- PHPUnit 10.0 or higher
- Composer
- Python 3 (for JSON processing in test runner)

## Installation

Install test dependencies:

```bash
cd /var/www/html/noiiolelo
composer install
```

## Test Configuration

Test behavior can be configured in `phpunit.xml` (repo root):

```xml
<testsuites>
    <testsuite name="Provider">...</testsuite>
    <testsuite name="Search">...</testsuite>
    <testsuite name="API">...</testsuite>
    <testsuite name="Integration">...</testsuite>
    <testsuite name="Indexing">...</testsuite>
</testsuites>
```

## Writing New Tests

### Test Class Template

```php
<?php

namespace Noiiolelo\Tests\YourCategory;

use PHPUnit\Framework\TestCase;

class YourTest extends TestCase
{
    protected function setUp(): void
    {
        resetRequest();  // Clear $_REQUEST between tests
    }

    public function testYourFeature(): void
    {
        $provider = getTestProvider('MySQL');
        // Your test code
        $this->assertTrue($result);
    }
}
```

### Helper Functions

Available in `bootstrap.php`:

- `getTestProvider($name)`: Get a provider instance for testing
- `resetRequest()`: Clear $_REQUEST, $_GET, $_POST globals

## Continuous Integration

The test suite is designed for CI/CD integration:

```bash
# Exit code 0 = all passed, non-zero = failures
./tests/run-tests.sh
echo $?
```

## Test Coverage

Current coverage focuses on:
- ✅ Provider interface usage
- ✅ Search modes and patterns
- ✅ API endpoints
- ✅ Request/response flow
- ❌ Database operations (excluded - tested via providers)
- ❌ Text parsing utilities (excluded)
- ❌ HTML output formatting (excluded)

## Troubleshooting

### "Class not found" errors

```bash
composer dump-autoload
```

### Provider connection errors

Check `.env` configuration and service availability:

```bash
# Test Elasticsearch
curl http://localhost:9200

# Test database
mysql -u your_user -p -e "SHOW DATABASES;"
```

### Permission errors

Ensure test runner is executable:

```bash
chmod +x tests/run-tests.sh
```

## Best Practices

1. **Keep tests isolated**: Use `resetRequest()` in setUp()
2. **Test provider interfaces**: Don't access database directly
3. **Use assertions liberally**: Multiple assertions per test is fine
4. **Test edge cases**: Empty searches, special characters, etc.
5. **Document complex tests**: Add comments explaining non-obvious logic

## Contributing

When adding new features to Noiiolelo:

1. Write tests first (TDD approach)
2. Run full test suite before committing
3. Update this README if adding new test categories
4. Maintain minimum 80% pass rate

## License

Same as parent Noiiolelo project.
