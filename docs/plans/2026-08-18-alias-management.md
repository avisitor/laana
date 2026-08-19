# Alias Management for Index Recreation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add configurable alias management so index recreation restores production-facing aliases (`hawaiian_documents`, `hawaiian_sentences`) pointing to the new `_new` indices.

**Architecture:** Add alias names to `.env`, add alias methods to `ElasticsearchClient`, update `createIndex()` to create aliases after index creation, and add `--aliases` flag to `createindex.php`.

**Tech Stack:** PHP 8.x, Elasticsearch 8.x, PHPUnit

---

## Context

The full rebuild created `hawaiian_documents_new` and `hawaiian_sentences_new` but didn't recreate the aliases that production code uses. The mapping JSON files don't define aliases. The index creation process needs to manage aliases separately.

Current state:
- `hawaiian_documents_new` exists with 2,520 docs
- `hawaiian_sentences_new` exists with 62,125 sentences
- No aliases exist
- Production code expects `hawaiian_documents` and `hawaiian_sentences`

---

## Task 1: Add alias names to `.env`

**Files:**
- Modify: `.env`

**Step 1: Add alias configuration**

Add after the ES configuration block:

```
# Elasticsearch aliases (production-facing names)
ES_DOCUMENTS_ALIAS=hawaiian_documents
ES_SENTENCES_ALIAS=hawaiian_sentences
```

**Step 2: Verify**

Run: `grep ES_DOCUMENTS_ALIAS .env`
Expected: `ES_DOCUMENTS_ALIAS=hawaiian_documents`

---

## Task 2: Add alias methods to ElasticsearchClient

**Files:**
- Modify: `providers/Elasticsearch/src/ElasticsearchClient.php`
- Create: `tests/Indexing/ElasticsearchClientAliasTest.php`

**Step 1: Write the failing tests**

```php
// tests/Indexing/ElasticsearchClientAliasTest.php
<?php

namespace Noiiolelo\Tests\Indexing;

use HawaiianSearch\ElasticsearchClient;
use Noiiolelo\Tests\BaseTestCase;

class ElasticsearchClientAliasTest extends BaseTestCase
{
    public function testGetDocumentsAliasReturnsString(): void
    {
        $client = new ElasticsearchClient();
        $alias = $client->getDocumentsAlias();
        $this->assertIsString($alias);
        $this->assertNotEmpty($alias);
    }

    public function testGetSentencesAliasReturnsString(): void
    {
        $client = new ElasticsearchClient();
        $alias = $client->getSentencesAlias();
        $this->assertIsString($alias);
        $this->assertNotEmpty($alias);
    }

    public function testAliasExistsReturnsBool(): void
    {
        $client = new ElasticsearchClient();
        $result = $client->aliasExists('nonexistent_alias_' . uniqid());
        $this->assertIsBool($result);
        $this->assertFalse($result);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `cd /var/www/html/noiiolelo && php vendor/bin/phpunit tests/Indexing/ElasticsearchClientAliasTest.php`
Expected: FAIL with "Call to undefined method"

**Step 3: Implement alias methods**

Add to `ElasticsearchClient.php` after `getSourceMetadataName()`:

```php
/**
 * Get the documents alias name from environment
 */
public function getDocumentsAlias(): string
{
    return $_ENV['ES_DOCUMENTS_ALIAS'] ?? 'hawaiian_documents';
}

/**
 * Get the sentences alias name from environment
 */
public function getSentencesAlias(): string
{
    return $_ENV['ES_SENTENCES_ALIAS'] ?? 'hawaiian_sentences';
}

/**
 * Check if an alias exists
 */
public function aliasExists(string $aliasName): bool
{
    try {
        return $this->client->indices()->existsAlias(['name' => $aliasName])->asBool();
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Create or update an alias pointing to an index
 */
public function createAlias(string $aliasName, string $indexName): void
{
    try {
        // Remove existing alias if it exists
        if ($this->aliasExists($aliasName)) {
            $this->client->indices()->deleteAlias([
                'index' => '_all',
                'name' => $aliasName
            ]);
            $this->print("Removed existing alias: {$aliasName}");
        }
        
        // Create new alias
        $this->client->indices()->putAlias([
            'index' => $indexName,
            'name' => $aliasName
        ]);
        $this->print("Created alias: {$aliasName} -> {$indexName}");
    } catch (\Exception $e) {
        $this->print("Failed to create alias {$aliasName}: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Remove an alias
 */
public function removeAlias(string $aliasName): void
{
    try {
        if ($this->aliasExists($aliasName)) {
            $this->client->indices()->deleteAlias([
                'index' => '_all',
                'name' => $aliasName
            ]);
            $this->print("Removed alias: {$aliasName}");
        }
    } catch (\Exception $e) {
        $this->print("Failed to remove alias {$aliasName}: " . $e->getMessage());
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `cd /var/www/html/noiiolelo && php vendor/bin/phpunit tests/Indexing/ElasticsearchClientAliasTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add providers/Elasticsearch/src/ElasticsearchClient.php tests/Indexing/ElasticsearchClientAliasTest.php
git commit -m "feat: add alias management methods to ElasticsearchClient"
```

---

## Task 3: Update createIndex() to create aliases

**Files:**
- Modify: `providers/Elasticsearch/src/ElasticsearchClient.php` (createIndex method)

**Step 1: Add alias creation to createIndex()**

Modify the `createIndex()` method to create aliases after creating indices:

```php
public function createIndex(bool $recreate = false,
                            string $indexType = 'all',
                            string $customIndexName = '',
                            string $customMappingFile = ''): void
{
    switch ($indexType) {
        case 'documents':
            $this->createDocumentsIndex($recreate, $customIndexName, $customMappingFile);
            break;
        case 'sentences':
            $this->createSentencesIndex($recreate, $customIndexName, $customMappingFile);
            break;
        case 'source-metadata':
            $this->createSourceMetadataIndex($recreate, $customIndexName ?: $this->indexName);
            break;
        case 'all':
            $this->createDocumentsIndex($recreate);
            $this->createSentencesIndex($recreate);
            $this->createSourceMetadataIndex($recreate, $this->indexName);
            break;
        default:
            // Backward compatibility: create the original combined index
            $this->createLegacyIndex($recreate, $customIndexName, $customMappingFile);
            break;
    }
    
    // Create aliases after index creation
    $this->createAliases();
}

/**
 * Create production-facing aliases pointing to the current indices
 */
protected function createAliases(): void
{
    $documentsAlias = $this->getDocumentsAlias();
    $sentencesAlias = $this->getSentencesAlias();
    $documentsIndex = $this->getDocumentsIndexName();
    $sentencesIndex = $this->getSentencesIndexName();
    
    $this->print("Creating production aliases...");
    
    if ($this->indexExists($documentsIndex)) {
        $this->createAlias($documentsAlias, $documentsIndex);
    } else {
        $this->print("Documents index {$documentsIndex} does not exist, skipping alias");
    }
    
    if ($this->indexExists($sentencesIndex)) {
        $this->createAlias($sentencesAlias, $sentencesIndex);
    } else {
        $this->print("Sentences index {$sentencesIndex} does not exist, skipping alias");
    }
}
```

**Step 2: Run syntax check**

Run: `cd /var/www/html/noiiolelo && php -l providers/Elasticsearch/src/ElasticsearchClient.php`
Expected: No syntax errors

**Step 3: Commit**

```bash
git add providers/Elasticsearch/src/ElasticsearchClient.php
git commit -m "feat: create aliases after index creation in createIndex()"
```

---

## Task 4: Update createindex.php to support --aliases flag

**Files:**
- Modify: `php/php/createindex.php`

**Step 1: Add --aliases flag**

Add to the options parsing section:

```php
$aliasesOnly = in_array('--aliases-only', $argv);
$noAliases = in_array('--no-aliases', $argv);
```

**Step 2: Add aliases-only mode**

Add after the index creation section:

```php
// Handle aliases-only mode
if ($aliasesOnly) {
    echo "Creating aliases only...\n";
    $client->createAliases();
    echo "Done.\n";
    exit(0);
}
```

**Step 3: Skip aliases if --no-aliases**

Modify the createIndex call to pass a flag:

```php
if (!$noAliases) {
    $client->createAliases();
}
```

**Step 4: Update help text**

Add to the help/usage section:

```
  --aliases-only    Only recreate aliases without touching indices
  --no-aliases      Skip alias creation after index creation
```

**Step 5: Run syntax check**

Run: `cd /var/www/html/noiiolelo && php -l php/php/createindex.php`
Expected: No syntax errors

**Step 6: Commit**

```bash
git add php/php/createindex.php
git commit -m "feat: add --aliases flag to createindex.php"
```

---

## Task 5: Add integration test for alias creation

**Files:**
- Modify: `tests/Indexing/ElasticsearchClientAliasTest.php`

**Step 1: Add integration test**

```php
public function testCreateAliasCreatesAlias(): void
{
    $client = new ElasticsearchClient();
    $testAlias = 'test_alias_' . uniqid();
    $documentsIndex = $client->getDocumentsIndexName();
    
    // Create alias
    $client->createAlias($testAlias, $documentsIndex);
    
    // Verify alias exists
    $this->assertTrue($client->aliasExists($testAlias));
    
    // Clean up
    $client->removeAlias($testAlias);
    $this->assertFalse($client->aliasExists($testAlias));
}
```

**Step 2: Run tests**

Run: `cd /var/www/html/noiiolelo && php vendor/bin/phpunit tests/Indexing/ElasticsearchClientAliasTest.php`
Expected: PASS

**Step 3: Commit**

```bash
git add tests/Indexing/ElasticsearchClientAliasTest.php
git commit -m "test: add integration test for alias creation"
```

---

## Task 6: Run full test suite

**Step 1: Run all tests**

Run: `cd /var/www/html/noiiolelo && ./tests/run-tests.sh`
Expected: All tests pass (exit code 0)

---

## Task 7: Create aliases for current indices

**Step 1: Run alias creation**

Run: `cd /var/www/html/noiiolelo && php php/php/createindex.php --aliases-only`
Expected: Aliases created pointing to existing indices

**Step 2: Verify aliases exist**

Run: `curl -s -k -H "Authorization: ApiKey ..." "https://localhost:9200/_alias/hawaiian_documents"`
Expected: Shows alias pointing to `hawaiian_documents_new`

**Step 3: Commit final state**

```bash
git add -A
git commit -m "feat: complete alias management for index recreation"
```
