# OpenSearch Bootstrap via createindex.php

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow `createindex.php` to bootstrap OpenSearch from MySQL, just as it currently bootstraps Elasticsearch.

**Architecture:** Add `--provider` flag to `createindex.php`. Modify `CorpusIndexer` to accept an optional client instance. Since `OpenSearchClient extends ElasticsearchClient`, the inherited indexing methods work with OpenSearch through the wrapper.

**Tech Stack:** PHP 8.x, Elasticsearch 8.x PHP client, OpenSearch

---

## Context

- `OpenSearchClient` extends `ElasticsearchClient` and inherits `createIndex()`, `bulkIndex()`, `deleteByDocId()`, `getDocumentsIndexName()`, `getSentencesIndexName()`, `getSourceMetadataName()`, etc.
- `OpenSearchClient` overrides `__construct()` to initialize an OpenSearch client (reads `OS_*` env vars) and wraps it to look like an ES client
- `CorpusIndexer` hardcodes `new ElasticsearchClient(...)` in its constructor (line 123)
- `createindex.php` hardcodes `new ElasticsearchClient(...)` for pre-flight validation (line 190)
- The `.env` already has OpenSearch config: `OS_HOST=localhost`, `OS_PORT=9201`, `OS_USER=admin`, `OS_PASS=...`

---

## Task 1: Modify CorpusIndexer to accept an optional client

**Files:**
- Modify: `providers/Elasticsearch/src/CorpusIndexer.php` (constructor, line 59-174)

**Step 1: Add optional $client parameter**

Change the constructor signature from:
```php
public function __construct(array $config, bool $recreate = false, bool $dryrun = false, ?string $sourceIndexForReindex = null)
```
to:
```php
public function __construct(array $config, bool $recreate = false, bool $dryrun = false, ?string $sourceIndexForReindex = null, ?ElasticsearchClient $client = null)
```

**Step 2: Use provided client or create new one**

Replace line 123:
```php
$this->client = new ElasticsearchClient($client_config);
```
with:
```php
$this->client = $client ?? new ElasticsearchClient($client_config);
```

**Step 3: Syntax check**

Run: `php -l providers/Elasticsearch/src/CorpusIndexer.php`

---

## Task 2: Add --provider flag to createindex.php

**Files:**
- Modify: `php/php/createindex.php`

**Step 1: Add provider to CLI options**

Add to `$longOptions`:
```php
'provider:',  // Search provider: Elasticsearch (default) or OpenSearch
```

**Step 2: Parse provider option**

After the existing flag parsing:
```php
$provider = $options['provider'] ?? $_ENV['PROVIDER'] ?? 'Elasticsearch';
```

**Step 3: Show provider in preamble**

Add to the preamble output:
```php
echo "Provider:            {$provider}\n";
```

**Step 4: Create client based on provider**

Replace the hardcoded `new ElasticsearchClient(...)` on line 190 with:
```php
if (strtolower($provider) === 'opensearch' || strtolower($provider) === 'os') {
    $esClient = new \HawaiianSearch\OpenSearchClient([...]);
} else {
    $esClient = new ElasticsearchClient([...]);
}
```

**Step 5: Pass client to CorpusIndexer**

Modify line 250:
```php
$indexer = new CorpusIndexer($config, $recreate, $dryrun);
```
to:
```php
// Create client for CorpusIndexer
if (strtolower($provider) === 'opensearch' || strtolower($provider) === 'os') {
    $indexerClient = new \HawaiianSearch\OpenSearchClient([
        'indexName'     => $config['COLLECTION_NAME'],
        'verbose'       => $verbose,
        'quiet'         => $quiet,
        'SPLIT_INDICES' => $config['SPLIT_INDICES'],
    ]);
} else {
    $indexerClient = new ElasticsearchClient([
        'indexName'     => $config['COLLECTION_NAME'],
        'verbose'       => $verbose,
        'quiet'         => $quiet,
        'SPLIT_INDICES' => $config['SPLIT_INDICES'],
    ]);
}
$indexer = new CorpusIndexer($config, $recreate, $dryrun, null, $indexerClient);
```

**Step 6: Update alias client creation**

The `CliAliasClient extends ElasticsearchClient` approach won't work for OpenSearch. Instead, create the client directly and call `createAliases()`:

```php
// For aliases-only mode and post-indexing aliases
if (strtolower($provider) === 'opensearch' || strtolower($provider) === 'os') {
    $aliasClient = new \HawaiianSearch\OpenSearchClient([...]);
} else {
    $aliasClient = new CliAliasClient([...]);
}
$aliasClient->createAliases();
```

Wait — `createAliases()` is `public` (we fixed that in Task 3 of the alias work). So we can call it directly on the client without the `CliAliasClient` wrapper!

**Step 7: Update help text**

Add to usage:
```
  --provider=NAME       Search provider: Elasticsearch (default) or OpenSearch
```

**Step 8: Syntax check**

Run: `php -l php/php/createindex.php`

---

## Task 3: Run tests

Run: `cd /var/www/html/noiiolelo && ./tests/run-tests.sh`
Expected: All tests pass

---

## Task 4: Verify --help output

Run: `php php/php/createindex.php --help`
Expected: Shows --provider flag in options

---

## Task 5: Commit

```bash
git add providers/Elasticsearch/src/CorpusIndexer.php php/php/createindex.php
git commit -m "feat: add --provider flag to createindex.php for OpenSearch bootstrap"
```
