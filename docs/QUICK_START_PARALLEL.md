# Quick Start: Parallel Embeddings Ingestion

## TL;DR - Faster Embeddings Processing

```bash
# Before (slow):
python3 scripts/ingest_embeddings.py sentences 100

# After (fast - 3-5x speedup):
python3 scripts/ingest_embeddings.py sentences 100 --workers 4
```

## Quick Examples

### CPU System (4-8 cores)
```bash
python3 scripts/ingest_embeddings.py sentences 100 --workers 4
```

### GPU System  
```bash
python3 scripts/ingest_embeddings.py sentences 200 --workers 2
```

### High-throughput Mode
```bash
python3 scripts/ingest_embeddings.py sentences 50 --workers 8
```

### Metrics Backfill with Parallelization
```bash
python3 scripts/ingest_embeddings.py sentences 100 --metrics-only --workers 6
```

## How Many Workers?

| System Type | Recommended Workers | Why |
|-------------|---------------------|-----|
| 4-core CPU | 4 | Match CPU cores |
| 8-core CPU | 6-8 | Match CPU cores |
| GPU | 2-4 | Avoid GPU contention |
| Fast DB | 8+ | Overcome I/O latency |

## Tips

✅ **DO**: Start with `--workers 4` for most systems  
✅ **DO**: Reduce batch size when increasing workers  
✅ **DO**: Monitor CPU/GPU usage to tune workers  

❌ **DON'T**: Use more workers than CPU cores (unless DB-bound)  
❌ **DON'T**: Exceed PostgreSQL connection limit  
❌ **DON'T**: Use too many workers on GPU (causes contention)  

## Check if It's Working

You should see output like:
```
Configuration: workers=4, batch_size=100, table=sentences
Using parallel processing with 4 workers
Processed batch: 100 Progress: 100 / 5000 | avg_ratio=0.856 ...
Processed batch: 100 Progress: 200 / 5000 | avg_ratio=0.891 ...
```

Multiple batches processing simultaneously = it's working! 🎉

## Troubleshooting

**"Too many connections"**
→ Reduce workers or increase PostgreSQL `max_connections`

**GPU out of memory**  
→ Use `--workers 1` or `--workers 2`, or set `EMBED_DEVICE=cpu`

**Not faster**
→ Check CPU/GPU utilization. May be bottlenecked elsewhere.

## More Info

See `PARALLEL_EMBEDDINGS.md` for detailed documentation.
