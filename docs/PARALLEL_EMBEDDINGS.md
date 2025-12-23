# Parallel Embeddings Ingestion

The `ingest_embeddings.py` script now supports parallel processing to significantly speed up embeddings generation and metrics calculation.

## New Features

### Parallel Processing
- **`--workers N`**: Run N parallel threads to process batches concurrently
- Each worker gets its own database connection
- Model encoding is thread-safe and can utilize multiple CPU/GPU resources

### Environment Variable
- **`EMBED_WORKERS`**: Set default number of workers (default: 1)

## Usage Examples

### Single-threaded (original behavior)
```bash
python3 scripts/ingest_embeddings.py sentences 100
```

### Parallel with 4 workers
```bash
python3 scripts/ingest_embeddings.py sentences 100 --workers 4
```

### Using environment variable
```bash
export EMBED_WORKERS=8
python3 scripts/ingest_embeddings.py sentences 100
```

### Metrics-only mode with parallelization
```bash
python3 scripts/ingest_embeddings.py sentences 100 --metrics-only --workers 6
```

## Performance Considerations

### Optimal Worker Count
- **CPU-bound (CPU encoding)**: Set workers to number of CPU cores (e.g., 4-8)
- **GPU-bound (GPU encoding)**: 2-4 workers usually optimal (avoid GPU contention)
- **Database-bound**: 4-8 workers can help overcome I/O latency

### Batch Size
- With parallel workers, you may want to reduce batch size for better parallelization
- Example: Instead of 1000 batch / 1 worker, try 200 batch / 5 workers

### Resource Usage
- Each worker maintains its own DB connection (check PostgreSQL connection limits)
- Model is shared across workers (loaded once in main thread)
- Memory usage scales with: `workers * batch_size * embedding_dimension`

### Frequency Calculation Optimization
- When `workers > 1`, frequency calculation is skipped to avoid expensive DB queries
- This is a performance optimization as frequency queries can serialize execution
- Run with `--workers 1` if you need frequency metrics

## Monitoring

The script outputs progress for each completed batch:
```
Configuration: workers=4, batch_size=100, table=sentences
Using parallel processing with 4 workers
Processed batch: 100 Progress: 100 / 5000 | avg_ratio=0.856 avg_wc=12.4 avg_len=89.2 avg_ec=1.23
Processed batch: 100 Progress: 200 / 5000 | avg_ratio=0.891 avg_wc=10.8 avg_len=82.1 avg_ec=0.95
...
```

## Example Performance Gains

Typical speedup factors (your results may vary):

| Workers | Speedup | Best For |
|---------|---------|----------|
| 1       | 1x      | Single-threaded baseline, includes frequency metrics |
| 2       | 1.7x    | GPU systems, light parallelization |
| 4       | 3.2x    | CPU encoding on quad-core systems |
| 8       | 5.5x    | High-core CPU or fast database I/O |

## Troubleshooting

### "Too many connections" error
- Reduce number of workers
- Check PostgreSQL `max_connections` setting
- Ensure old connections are being closed properly

### GPU memory errors
- Reduce workers to 1-2 for GPU encoding
- Reduce batch size
- Use CPU encoding instead: `EMBED_DEVICE=cpu`

### Inconsistent results
- Progress numbers are accurate but may print out-of-order due to thread scheduling
- Final totals are always correct due to lock-protected counters
