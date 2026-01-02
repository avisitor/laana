# Embedding Service Memory Management Improvements

## Problem Addressed

The embedding service was experiencing continuous memory growth from ~1.1GB at startup to 3GB+ during indexing operations. The existing memory cleanup was ineffective:
- Triggered on every request when memory exceeded 1.5GB threshold
- Only performed basic `gc.collect()` operations
- Freed only 8-16 objects per cleanup while memory continued growing
- Created overhead without actually reducing memory footprint

## Root Causes

1. **Low memory threshold**: 1.5GB threshold too close to baseline model memory (~1.1GB)
2. **Ineffective cleanup**: Basic garbage collection doesn't address ML model-specific memory leaks
3. **Over-aggressive triggering**: Cleanup running on every request when over threshold
4. **Missing tensor cleanup**: PyTorch tensors and model states not being properly released

## Improvements Implemented

### 1. Smart Memory Management
- **Raised threshold**: Memory cleanup threshold increased from 1.5GB to 2.5GB
- **Multiple triggers**: Cleanup now triggers based on:
  - High memory usage (>2.5GB)
  - Periodic intervals (every 5 minutes)
  - Request count thresholds (every 50 requests)
  - Manual force cleanup via API

### 2. Aggressive Cleanup Techniques
- **PyTorch cache clearing**: Both CUDA and CPU tensor caches
- **Model state cleanup**: Clear gradients and force eval mode
- **Multiple GC passes**: 3 garbage collection cycles with temporary threshold adjustments
- **Memory allocator optimization**: Temporary aggressive GC thresholds

### 3. Background Memory Monitoring
- **Dedicated thread**: Background monitoring every 60 seconds
- **Reduced per-request overhead**: Only lightweight request counting
- **Thread-safe cleanup**: Proper locking to prevent concurrent cleanups

### 4. Batch Processing Optimization
- **Smaller batches**: Process embeddings in batches of 32 instead of all at once
- **Intermediate cleanup**: Clear tensor caches between batches
- **Memory-conscious processing**: Reduces peak memory usage during large requests

### 5. Enhanced Monitoring & Control
- **Improved memory endpoint**: Better status reporting (normal/elevated/high)
- **Manual cleanup API**: Force cleanup via `POST /cleanup`
- **Detailed logging**: Better cleanup reason reporting and memory tracking

## Usage

### Current Service Status
Check current memory usage:
```bash
curl -s http://localhost:5000/memory | jq
```

### Force Cleanup
Trigger immediate cleanup:
```bash
curl -X POST http://localhost:5000/cleanup
```

### Upgrade Process
When indexing is complete, run the upgrade:
```bash
# Check if safe to upgrade (looks for recent activity)
./embedding_service/upgrade_memory_management.sh

# Force upgrade regardless of activity
./embedding_service/upgrade_memory_management.sh --force
```

## Expected Results

1. **Stable memory footprint**: Memory should stabilize around 1.5-2GB instead of growing to 3GB+
2. **Reduced cleanup frequency**: Cleanup will run periodically rather than on every request
3. **Better performance**: Less per-request overhead from excessive cleanup
4. **More effective cleanup**: Actual memory reduction when cleanup runs
5. **Improved monitoring**: Better visibility into memory patterns and cleanup effectiveness

## Configuration Options

The improved service supports several environment variables:
- `EMBEDDING_MODEL`: Model name (default: 'intfloat/multilingual-e5-small')
- Memory thresholds are configurable in the code if needed

## Monitoring

Key metrics to watch:
- Memory should stay below 2.5GB during normal operations
- Cleanup frequency should be much lower (minutes apart, not every request)
- "freed X objects" should show larger numbers when cleanup runs
- Background cleanup should handle most memory management automatically

## Rollback Plan

If issues occur:
1. The upgrade script creates automatic backups
2. Original `app.py` is preserved as `app.py.backup-[timestamp]`
3. Service can be quickly reverted by restoring the backup and restarting
