# Parallel Embeddings Ingestion (historical)

> **Status:** historical
> **Verified:** 2026-11-09
> **Superseded by:** ../../scripts/pg_import.php

The Python backfill is mostly abandoned. Prefer the PHP path:
`scripts/pg_import.php` (or `save.php --provider=postgres`) fills the same
`sentences.embedding` (384-dim) and `contents.embedding_1024` (1024-dim)
vectors through `EmbeddingClient` / `PostgresSourcePipeline`. Use
`scripts/ingest_embeddings.py` only when you specifically want the Python
sentence-transformers environment.

## Process model

`scripts/ingest_embeddings.py` uses **processes**, not threads:

- **`--workers N`** (default from `EMBED_WORKERS`, else 1) sizes a
  `ProcessPoolExecutor`.
- When `N > 1`, each worker process runs `worker_init()`, which loads its
  **own** `SentenceTransformer` model (and its own spaCy pipeline) — the model
  is *not* shared between workers.
- Each worker opens its **own** PostgreSQL connection inside
  `process_batch_worker()`; the main process keeps a separate connection for
  fetching batches and counting progress.
- The main process fetches one batch at a time, submits it, and blocks on
  `future.result()` before fetching the next. So batches are not pipelined:
  the pool may have N processes, but only one batch is in flight.
- With `N > 1`, frequency calculation is disabled (`calc_frequency` is
  replaced with a zero-returning lambda). Only frequency counts are affected;
  the other metrics are still written.
- `--metrics-only` passes `None` as the model name, so workers skip model
  loading and only compute metrics for rows that already have embeddings.

## Usage

```bash
# Single process (original behaviour, includes frequency metrics)
python3 scripts/ingest_embeddings.py sentences 100

# Four worker processes
python3 scripts/ingest_embeddings.py sentences 100 --workers 4

# Default worker count from the environment
export EMBED_WORKERS=8
python3 scripts/ingest_embeddings.py sentences 100

# Metrics-only
python3 scripts/ingest_embeddings.py sentences 100 --metrics-only --workers 6
```

## Performance considerations

- Each worker holds a full model copy; memory scales with
  `workers × model size`, plus `batch_size × embedding_dimension` per batch.
- Each worker also opens its own database connection; watch PostgreSQL
  `max_connections`.
- GPU: several processes compete for the same device, so `--workers 2` is
  usually the useful upper bound; CPU encoding scales with cores.
- Because only one batch is in flight, raising `--workers` does not produce
  the near-linear speedup described in earlier revisions of this document.

## Monitoring

The script prints one line per completed batch:

```
Configuration: workers=4, batch_size=100, table=sentences
Using parallel processing with 4 processes
Processed batch: 100 Progress: 100 / 5000 | avg_ratio=0.856 avg_wc=12.4 avg_len=89.2 avg_ec=1.23
```

## Troubleshooting

- **"Too many connections"** — lower `--workers`; connections are
  per-process and are not pooled across batches.
- **GPU memory errors** — lower `--workers` (or set `EMBED_DEVICE=cpu`).
- **Progress counter** — updated by worker processes through a shared
  `Manager` value/lock; the final totals are correct.
- **Missing frequencies** — rerun with `--workers 1`, or use
  `scripts/pg_import.php`.
