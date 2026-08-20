# Delete and Re-index with createindex.php

## Overview

`scripts/createindex.php` is the CLI entry point for `CorpusIndexer`. It can run a
full corpus reindex, a scoped reindex (by source or group), an alias-only
refresh, or a content-only ingest — against either Elasticsearch or
OpenSearch.

This document describes the options that currently exist. The
`--delete-existing` / `--domain` flags and the groupname-scoped delete
described in earlier versions of this document **no longer exist in
`createindex.php`** — that functionality lived in `scripts/savedocument.php`
(via `ElasticsearchClient::deleteByGroupname()`), not here. See
[Groupname-scoped reindexing](#groupname-scoped-reindexing-source-id---group-name)
below for the current equivalent and its important caveats.

## ⚠️ `--recreate` is global, not scoped

`--recreate` deletes and recreates the **entire** documents, sentences, and
source-metadata indices — it is not limited by `--source-id` or
`--group-name`. If you combine `--recreate` with `--group-name=X`, the
script will:

1. Delete **all** documents, sentences, and source-metadata (every group,
   not just `X`).
2. Re-index only the sources belonging to group `X`.

Every other group's documents/sentences/source-metadata will be gone until
a separate full (or per-group) reindex repopulates them. **Do not combine
`--recreate` with `--source-id` or `--group-name` unless you intend a full
wipe.** For a truly scoped delete-and-reindex of one group without
affecting the rest of the corpus, use `--group-name=X` **without**
`--recreate` — the indexer will index/overwrite documents for that group by
ID, without touching anything else. `--recreate` is only for the
"rebuild everything from scratch" case.

## Usage

```bash
php scripts/createindex.php [options]
```

### Options

| Option | Description |
|---|---|
| `--recreate` | Delete and recreate the documents/sentences/source-metadata indices before indexing (global — see warning above) |
| `--dryrun`, `--dry-run` | Show what would happen without writing anything |
| `--verbose` | Verbose output |
| `--quiet` | Suppress non-error output |
| `--max-documents=N`, `--limit=N` | Stop after indexing N documents |
| `--source-id=N` | Only index the source with this ID |
| `--group-name=NAME`, `--groupname=NAME` | Only index sources in this group |
| `--batch-size=N` | Documents per batch (default: 1) |
| `--sentence-batch-size=N` | Sentences per embedding request (default: 100) |
| `--checkpoint-interval=N` | Sources between checkpoints (default: 50) |
| `--split-indices` | Use separate document/sentence indices (default) |
| `--no-split-indices` | Use a single combined index |
| `--collection-name=NAME` | Base collection/index name (default: `hawaiian`) |
| `--aliases-only` | Only (re)create production aliases without touching indices |
| `--no-aliases` | Skip alias creation/update after indexing |
| `--provider=NAME` | Search provider: `Elasticsearch` (default) or `OpenSearch` |
| `--import-raw` | Ingest ONLY the raw-content index (`hawaiian-content`) without touching any other index (see below) |
| `--help` | Show usage |

Exit codes: `0` success, `1` error, `130` interrupted by SIGINT (Ctrl+C),
`143` interrupted by SIGTERM. Pressing Ctrl+C once requests a graceful stop
at the next batch boundary; pressing it again forces an immediate exit.

## What a normal (full) run does

With no special flags, `createindex.php`:

1. Validates index schemas (aborts with guidance if invalid — use
   `--recreate` to fix, or correct the schema files).
2. Creates/recreates the documents, sentences, and source-metadata indices
   per `--recreate`.
3. Fetches the source list (optionally filtered by `--source-id` /
   `--group-name`) and indexes documents + sentences.
4. Also ingests the raw-content index (`hawaiian-content`) for every
   matching source — creating it if missing, recreating it only if
   `--recreate` was passed, and skipping sources whose content record
   already exists. This keeps `hawaiian-content` in sync as part of a
   normal run.
5. Ensures production aliases exist (unless `--no-aliases`).

## Content-only mode: `--import-raw`

`--import-raw` ingests **only** `hawaiian-content` and touches nothing
else — no documents, sentences, metadata, source-metadata, or aliases are
created, deleted, or written to.

```bash
# Create/populate hawaiian-content for the whole corpus, without touching
# any other index:
php scripts/createindex.php --import-raw --provider=opensearch

# Recreate hawaiian-content from scratch (delete + rebuild), still without
# touching any other index:
php scripts/createindex.php --import-raw --recreate --provider=opensearch

# Ingest content for a single source only:
php scripts/createindex.php --import-raw --source-id=52839 --provider=opensearch
```

- `--import-raw` alone: creates `hawaiian-content` if missing, leaves an
  existing one alone, and only adds records for sources that don't already
  have one (idempotent).
- `--import-raw --recreate`: deletes and recreates `hawaiian-content`, then
  re-ingests everything matching `--source-id` / `--group-name` (or all
  sources if neither is given).
- `--dryrun` with `--import-raw` prints what would be ingested and performs
  no writes.

## Groupname-scoped reindexing (`--source-id` / `--group-name`)

To re-index a specific source or group without a full `--recreate` wipe:

```bash
php scripts/createindex.php --group-name=kauakukalahale
php scripts/createindex.php --source-id=52839 --verbose
```

This fetches the filtered source list and indexes/overwrites those
documents by ID — existing documents for other groups/sources are left
untouched. This does **not** first delete stale documents that may have
been removed from the source group upstream; it only adds/overwrites. If
you need to purge documents whose sources have since disappeared from a
group, that is a separate operation (see `ElasticsearchClient::deleteByGroupname()`,
used by `scripts/savedocument.php`) and is not currently wired into
`createindex.php`.

### Testing first (recommended)

```bash
php scripts/createindex.php --group-name=kauakukalahale --dryrun
```

## Aliases-only mode

```bash
php scripts/createindex.php --aliases-only
```

(Re)creates production aliases without touching any index. Useful after
manually recreating indices, or to repair alias drift.

## Provider selection

```bash
php scripts/createindex.php --recreate --provider=opensearch
```

Defaults to the `PROVIDER` environment variable, or `Elasticsearch` if
unset. `opensearch` / `os` (case-insensitive) select OpenSearch.

## Examples

```bash
php scripts/createindex.php --dryrun
php scripts/createindex.php --recreate --verbose --max-documents 10
php scripts/createindex.php --recreate --verbose
php scripts/createindex.php --group-name=kauakukalahale --dryrun
php scripts/createindex.php --aliases-only
php scripts/createindex.php --recreate --provider=opensearch
php scripts/createindex.php --import-raw --provider=opensearch
php scripts/createindex.php --import-raw --source-id=52839 --provider=opensearch
```

## Recovering source-metadata

`hawaiian-source-metadata` is derived, checkpoint-style data
(sourceid/sourcename/groupname/authors/date/link/title/discarded) that can
be fully reconstructed from the documents index if it's ever lost or
emptied. Use:

```bash
php php/php/rebuild_source_metadata.php --provider=Elasticsearch --dryrun
php php/php/rebuild_source_metadata.php --provider=Elasticsearch
php php/php/rebuild_source_metadata.php --provider=OpenSearch
```

This scrolls the documents index and rebuilds one source-metadata record
per document; it never deletes an existing source-metadata index, only
fills in missing/refreshed records.
