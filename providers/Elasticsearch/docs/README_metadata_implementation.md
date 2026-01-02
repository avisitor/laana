# Hawaiian Search Metadata Implementation

This document describes the comprehensive implementation of sentence-level quality metadata extraction and indexing for the Hawaiian Search system.

## Overview

The system now includes:
1. **MetadataExtractor** - PHP class for NLP metrics extraction
2. **Enhanced CorpusIndexer** - Extracts metadata during document indexing
3. **Metadata Rebuild Script** - Standalone script to rebuild corrupted metadata index
4. **Python NLP Enhancer** - Optional advanced NLP analysis with spaCy

## Files Created/Modified

### Core Components

1. **`src/MetadataExtractor.php`** - New class providing:
   - Sentence hashing (MD5 of normalized text)
   - Hawaiian word ratio calculation
   - Entity counting (basic pattern matching)
   - Boilerplate score computation
   - Bulk metadata operations
   - Proper metadata index creation

2. **`src/CorpusIndexer.php`** - Enhanced to include:
   - MetadataExtractor integration
   - Sentence-level metadata extraction during indexing
   - Performance tracking for metadata operations
   - Automatic metadata index creation

3. **`scripts/rebuild_metadata_index.php`** - Standalone script to:
   - Walk through all documents in main index
   - Extract sentence metadata from existing documents
   - Rebuild the corrupted metadata index
   - Support dry-run, verbose, and batch processing modes

4. **`scripts/nlp_enhancer.py`** - Python script providing:
   - Advanced entity recognition with spaCy
   - Enhanced boilerplate detection
   - Part-of-speech analysis
   - Fallback to basic NLP without spaCy

### Enhanced Testing

5. **`scripts/direct_metadata_test.php`** - Updated to:
   - Detect metadata corruption
   - Compare expected vs actual index structure
   - Provide clear remediation guidance
   - Show comprehensive statistics for both indices

## Metadata Structure

### Expected hawaiian_hybrid-metadata Index

Each document represents a unique sentence with the following fields:

```json
{
  "_id": "sentence_hash",  // MD5 hash of normalized sentence text
  "sentence_hash": "...",
  "frequency": 2,          // How many times this sentence appears
  "length": 145,           // Character length
  "entity_count": 3,       // Number of named entities
  "word_count": 28,        // Number of words
  "hawaiian_word_ratio": 0.75,  // Ratio of Hawaiian words
  "boilerplate_score": 0.1,     // Quality score (0.0-1.0, lower=better)
  "metadata": {
    "doc_ids": ["12345", "67890"],     // Documents containing this sentence
    "positions": [0, 5]               // Position within each document
  }
}
```

## Usage Instructions

### 1. Test Current State

```bash
# Check for corruption
cd /var/www/html/elasticsearch/php/scripts
php direct_metadata_test.php

# Verbose output for detailed analysis
php direct_metadata_test.php --verbose
```

### 2. Rebuild Metadata Index

```bash
# Dry run first to see what would be processed
php rebuild_metadata_index.php --dry-run --verbose

# Recreate the metadata index from scratch
php rebuild_metadata_index.php --recreate

# Process with specific batch size
php rebuild_metadata_index.php --batch-size 100
```

### 3. Future Indexing

The enhanced CorpusIndexer will automatically extract metadata during normal indexing:

```bash
# New documents will include metadata extraction
php index_corpus.php
```

### 4. Optional Python Enhancement

```bash
# Test Python NLP enhancer
echo "Test sentence with entities like John Doe." | python3 nlp_enhancer.py

# Batch processing
python3 nlp_enhancer.py --mode batch --input sentences.json --output enhanced.json
```

## Performance Considerations

### PHP Implementation
- Basic entity detection using pattern matching
- Optimized Hawaiian word ratio calculation with caching
- Bulk operations for better Elasticsearch performance
- Memory-efficient processing with garbage collection

### Python Enhancement
- Advanced entity recognition with spaCy (if available)
- Fallback to basic NLP without dependencies
- Better boilerplate detection
- Part-of-speech analysis

### Processing Statistics (from dry-run)
- **Documents**: 1,189 total in index
- **Sentences**: ~139,000+ sentences to process
- **Batch Processing**: Configurable batch sizes (default: 50 documents)
- **Estimated Time**: ~45+ minutes for full rebuild (based on dry-run timing)

## Quality Metrics Explained

### Boilerplate Score (0.0 - 1.0, lower = better quality)
- **Length penalty**: +0.5 for sentences < 40 characters
- **Entity penalty**: +0.5 if no entities detected
- **Repetition penalty**: +0.3 for repeated patterns
- **Pattern penalty**: +0.2 for boilerplate phrases (copyright, click here, etc.)

### Entity Count
- Capitalized words (excluding sentence starts)
- Date patterns (MM/DD/YYYY, etc.)
- Year patterns (4-digit numbers)
- Named entities (with spaCy enhancement)

### Hawaiian Word Ratio
- Words with diacritical marks (ā, ē, ī, ō, ū, ')
- Dictionary lookups against hawaiian_words.txt
- Normalized comparison (removes diacritics and punctuation)

## Troubleshooting

### Common Issues

1. **Memory Issues**: Reduce batch size in rebuild script
2. **Timeout Issues**: Process in smaller chunks or increase PHP limits
3. **Python Dependencies**: NLP enhancer works without spaCy but with reduced features

### Verification

```bash
# After rebuilding, verify the structure
php direct_metadata_test.php

# Check document count in metadata index
curl -u elastic:$ELASTIC_PASSWORD -X GET "https://localhost:9200/hawaiian_hybrid-metadata/_count" --cacert /etc/elasticsearch/certs/http_ca.crt
```

## Integration with Search

The metadata index enables:
1. **Quality-based reranking** in search results
2. **Sentence-level filtering** by quality scores
3. **Frequency-based ranking** for common phrases
4. **Entity-aware search** improvements

## Future Enhancements

1. **Advanced NLP**: Full spaCy integration in PHP via Python bridge
2. **Machine Learning**: Quality scoring based on trained models
3. **Real-time Updates**: Incremental metadata updates during indexing
4. **Quality Dashboards**: Monitoring and analytics for content quality
