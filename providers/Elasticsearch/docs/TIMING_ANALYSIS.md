# CorpusIndexer Timing Analysis

## Overview

The CorpusIndexer includes comprehensive timing instrumentation to identify performance bottlenecks during document processing and indexing.

## Timing Categories Explained

### Major Operations

- **Sentence Embedding** - Time spent calling the embedding service to generate vectors for sentences
- **Document Embedding** - Time spent calling the embedding service to generate vectors for full documents  
- **Total Document Processing** - Overall time spent processing each document (includes all sub-operations)

### Document Processing Breakdown

- **Source Validation** - Time spent checking if documents already exist, validating source metadata
- **Text Processing** - Time spent fetching text from external APIs and calculating Hawaiian word ratios
- **Sentence Splitting** - Time spent splitting documents into individual sentences using regex
- **Sentence Filtering** - Time spent filtering sentences by Hawaiian word ratio thresholds
- **Document Chunking** - Time spent splitting large documents into chunks for regex support
- **Vector Validation** - Time spent validating vector dimensions and format before indexing
- **Document Assembly** - Time spent building the final document structure for Elasticsearch

### Metadata Operations

- **Metadata Extraction** - Time spent in bulk metadata extraction operations
- **Individual Sentence Metadata** - Time spent analyzing each sentence individually for metadata
- **Metadata Operations** - Time spent saving/loading source metadata to/from Elasticsearch

### Sentence Processing

- **Sentence Object Construction** - Time spent building sentence objects with vectors and metadata
- **Sentence Processing Other** - Any remaining unaccounted time in sentence processing

### Infrastructure

- **Document Indexing** - Time spent indexing documents to the documents index
- **Sentence Indexing** - Time spent indexing sentences to the sentences index  
- **Source Iterator Setup** - Time spent initializing document source iterators
- **Source Batch Fetching** - Time spent fetching batches of source metadata
- **Final Checkpoint** - Time spent on final metadata checkpoint operations
- **Fetch Plain/HTML** - Time spent making HTTP requests to fetch document content
- **Hawaiian Ratio Calc** - Time spent calculating Hawaiian word ratios (with caching)
- **Cache Operations** - Time spent on cache hits/misses for Hawaiian word ratios
- **Retry Operations** - Time spent waiting for embedding service retries/delays

### Development/Debugging

- **Split Index Creation** - Time spent creating separate document/sentence objects for split indices

## Performance Insights

The Timer system provides accurate performance measurement with ~97% correlation to real runtime.

### Current Performance Profile (12 large documents):

- **Sentence Embedding: 97.3%** - Overwhelming bottleneck (297s out of 305s total)
- **Sentence Indexing: 1.3%** - Elasticsearch sentence bulk operations  
- **Document Embedding: 1.2%** - Much faster than sentence embedding
- **Document Indexing: 0.0%** - Document bulk operations are very fast
- **Infrastructure: <0.3%** - Setup, checkpoints, fetching

### Optimization Priority:

1. **#1 Priority: Sentence Embedding Service** (97.3% impact)
   - Consider parallel sentence embedding requests
   - Optimize embedding service performance/scaling  
   - Investigate batch size tuning (currently 100 sentences/batch)

2. **#2 Priority: Sentence Indexing** (1.3% impact)
   - Already fast but room for improvement

3. **Everything Else** (<1% impact) - Not worth optimizing until #1 is addressed

The timing report automatically provides insights when operations exceed certain thresholds:

- **HTTP requests > 30%** - Consider more parallel fetching
- **Sentence embedding > 20%** - Major bottleneck, consider embedding service optimization
- **Document embedding > 10%** - Significant time, monitor embedding service performance
- **Individual sentence metadata > 5%** - Per-sentence analysis is expensive
- **Unaccounted time > 5%** - Need to investigate missing timing instrumentation

## Common Issues

### High "Sentence Processing Other" Time
This usually indicates:
1. Individual sentence metadata analysis taking too long
2. Hawaiian word ratio calculations for each sentence
3. Missing timing instrumentation for some operations

### High "Total Document Processing" vs Sum of Parts
This indicates operations that aren't being timed individually. The report calculates and highlights unaccounted time.

### High Embedding Times
- Check embedding service health and performance
- Consider batch size optimization
- Monitor for connection timeouts and retries

## Usage

Run indexing with timing enabled (always on by default):

```bash
php create-index.php --verbose --maxdocuments=10
```

The detailed timing breakdown is printed at the end of the indexing run.
