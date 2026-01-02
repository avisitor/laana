# Delete and Re-index Documents by Groupname

## Overview

The `createindex.php` script now supports a `--delete-existing` option that allows you to delete all existing documents with a specific groupname before re-indexing them. This ensures you don't have duplicate or stale documents when re-importing data from the same source.

## Usage

### Basic Command

To delete existing documents and re-index:

```bash
php php/createindex.php --groupname=kauakukalahale --domain=noiiolelo.worldspot.org --delete-existing
```

### What It Does

1. **Deletes** all documents, sentences, and metadata records with the specified groupname
2. **Fetches** fresh source list from the API filtered by groupname
3. **Indexes** all documents with their sentences

### Important Notes

- The `--delete-existing` flag **requires** `--groupname` to be specified (for safety)
- Deletion happens in all three indices:
  - Documents index (`hawaiian_documents_new`)
  - Sentences index (`hawaiian_sentences_new`)  
  - Metadata index (`hawaiian-metadata`)

### Testing First (Recommended)

Use `--dryrun` to see what would happen without actually making changes:

```bash
php php/createindex.php --groupname=kauakukalahale --domain=noiiolelo.worldspot.org --delete-existing --dryrun
```

This will show you:
- How many sources match the groupname
- That it would delete existing documents
- Sample of the sources that would be processed

## Examples

### Example 1: Re-index a specific group

```bash
php php/createindex.php --groupname=kauakukalahale --domain=noiiolelo.worldspot.org --delete-existing
```

### Example 2: Test with a limit

```bash
php php/createindex.php --groupname=kauakukalahale --domain=noiiolelo.worldspot.org --delete-existing --limit=10
```

This will delete all existing documents with that groupname but only re-index the first 10 sources (useful for testing).

### Example 3: Dry run to preview

```bash
php php/createindex.php --groupname=kauakukalahale --domain=noiiolelo.worldspot.org --delete-existing --dryrun
```

## Current Statistics for kauakukalahale

Based on the last check:
- **Documents**: 820
- **Sentences**: 23,711
- **Sources to re-index**: 758

## Technical Details

The deletion uses Elasticsearch's `deleteByQuery` API with a term query on `groupname.keyword` field:

```json
{
  "query": {
    "term": {
      "groupname.keyword": "kauakukalahale"
    }
  }
}
```

This ensures only exact matches are deleted.

## Safety Features

1. **Groupname required**: The `--delete-existing` flag requires `--groupname` to prevent accidental deletion of all documents
2. **Dry run mode**: Test the operation without making changes
3. **Statistics reporting**: Shows exactly how many documents/sentences/metadata records were deleted
4. **Index-specific**: Only deletes from the actual indices, not the entire database

## New ElasticsearchClient Method

A new method `deleteByGroupname(string $groupname)` was added to the ElasticsearchClient class:

```php
$stats = $client->deleteByGroupname('kauakukalahale');
// Returns: ['documents' => 820, 'sentences' => 23711, 'metadata' => N]
```
