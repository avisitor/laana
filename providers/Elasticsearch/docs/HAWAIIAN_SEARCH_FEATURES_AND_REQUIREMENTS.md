# Hawaiian Search System - Features and Requirements Specification

## Overview
This document comprehensively lists all features, requirements, and behaviors for the Hawaiian Search System's PHP components. This serves as a reference to prevent regression bugs and ensure all functionality is preserved during development.

---

## Core PHP Scripts

### create-index.php
**Purpose**: Main indexing script for Hawaiian corpus documents

#### Command Line Arguments
- `--recreate`: Delete and recreate all indices from scratch
- `--dryrun`: Simulate indexing without actually indexing documents
- `--verbose`: Enable detailed logging output
- `--maxdocuments N`: Limit indexing to first N documents
- `--sourceid ID`: Index only the specific source ID 'ID'

#### Core Features
- **Source Tracking**: Maintains list of processed and rejected sourceids to skip already indexed documents
- **Recreate**: An option to recreate by first deleting main index and metadata indexes
- **Batch Processing**: Configurable batch sizes for submission to elastic search (default: 1)
- **Checkpoint System**: Saves metadata every 50 documents by default to a separate index
- **Signal Handling**: Graceful shutdown on Ctrl-C with metadata save
- **Performance Timing**: Always-enabled detailed timing breakdown
- **Memory Management**: Clears caches periodically to prevent memory issues
- **Long Document Chunking**: Automatically chunks documents >32K chars for regex compatibility
- **Hawaiian Word Loading**: Loads a list of Hawaiian words for evaluating language content
- **Embedding Generation**: Creates both document and sentence-level embeddings
- **Metadata Extraction**: Extracts NLP metrics for sentences and documents

#### Output Requirements
- Configuration summary at startup
- Progress indicators with source IDs and names
- Skipping messages for already processed documents
- Chunking warnings for long documents
- Timing breakdown with percentages
- Performance estimates (docs/hour, time for 1K/20K docs)
- Cache statistics
- Metadata checkpoint confirmations

#### Error Handling
- Elasticsearch connection failures
- Missing Hawaiian words file
- Embedding service failures
- Memory limit issues
- Invalid source IDs

---

### query.php
**Purpose**: Command-line query interface for searching indexed documents

#### Command Line Arguments
- `--query "text"`: Search query text (required)
- `--mode TYPE`: Search mode (fulltext, regex, embedding, hybrid)
- `--limit N`: Maximum number of results to return
- `--debug`: Enable debug output
- `--timing`: Show detailed timing information

#### Search Modes
- **all modes in QueryBuilder->MODES:
- match
- term
- phrase
- regexp
- regexpsentence
- wildcard
- vector
- hybrid
- vectorsentence
- hybridsentence
- knn
- knnsentence

#### Core Features
- **Multi-field Regex**: Searches both text.raw and text_full.keyword fields
- **Both document-level and sentence-level queries**
- **Chunk-aware Results**: Aggregates results from document chunks
- **Score Normalization**: Proper scoring across different search modes
- **Result Formatting**: Clean output with source names, IDs, and excerpts
- **Performance Metrics**: Startup latency and search completion timing
- **Error Handling**: Graceful failures with meaningful messages

#### Output Format
- Startup latency measurement
- Search completion timing
- Results with [match] prefix, source ID, source name, and score
- Text excerpts with → prefix
- Total time for result rendering

---

## Core Classes (src/ directory)

### ElasticsearchClient.php
**Purpose**: Central Elasticsearch interface with schema validation

#### Index Management
- **Index Creation**: Creates indices with proper mappings defined in index_mapping.json
- **Index Deletion**: Safe deletion of indices
- **Index Existence**: Checks if indices exist and correspond to configuration
- **Mapping Updates**: Updates field mappings dynamically
- **Schema Validation**: Auto-validates schema issues on startup

#### Document Operations
- **Bulk Indexing**: Efficient batch document indexing
- **Document Retrieval**: Get individual documents by ID
- **Document Search**: Execute search queries
- **Document Counting**: Count documents in indices

#### Metadata Management
- **Source Metadata**: Load/save processed source IDs and statistics
- **Metadata Index**: Separate index for sentence-level metadata
- **Schema Validation**: Ensures required fields exist with correct types
- **Auto-repair**: Fixes missing or invalid metadata structures

#### Configuration
- **Multiple Indices**: Constant required indices hawaiian_hybrid, hawaiian_hybrid-metadata, hawaiian_hybrid-source-metadata
- **SSL/TLS Support**: HTTPS connections with certificate validation
- **Authentication**: Username/password authentication
- **Connection Pooling**: Efficient connection management
- **Error Recovery**: Automatic retry logic for failed operations

#### Field Mappings Requirements
- **text**: Standard analyzed text field
- **text.raw**: Keyword field for regex (max ~32K chars, extended through chunking)
- **text_full**: Full text storage without keyword limit
- **sourceid**: Integer source identifier
- **sourcename**: Source document name
- **embeddings**: Dense vector field for similarity search
- **sentence_embeddings**: Array of sentence-level vectors
- **metadata fields**: Various NLP and quality metrics

---

### CorpusIndexer.php
**Purpose**: Main document processing and indexing engine

#### Document Processing Pipeline
1. **Source Fetching**: Retrieves document list from API
2. **Text Retrieval**: Fetches full document text
3. **Hawaiian Analysis**: Calculates Hawaiian word ratios
4. **Sentence Processing**: Splits and filters sentences
5. **Embedding Generation**: Creates document and sentence embeddings
6. **Metadata Extraction**: Computes NLP quality metrics
7. **Document Chunking**: Splits long documents for regex compatibility
8. **Bulk Indexing**: Efficiently indexes processed documents
9. **Dryrun Mode**: Evaluate document preparation up to submission to elastic search

#### Source Tracking
- **Processed IDs**: Maintains list of successfully indexed documents
- **Discarded IDs**: Tracks rejected documents
- **English-only IDs**: Documents without Hawaiian content
- **No-Hawaiian IDs**: Documents failing Hawaiian content checks
- **Checkpoint System**: Periodic metadata saves during indexing

#### Performance Features
- **Batch Processing**: Configurable batch sizes
- **Caching System**: Hawaiian ratio and embedding caches
- **Memory Management**: Periodic cache clearing
- **Parallel Processing**: Where possible, parallel operations
- **Progress Tracking**: Detailed timing for each processing stage
- **Memory/Performance Tuning**: Constants to tune memory usage

#### Document Chunking Logic
- **Size Threshold**: Chunks documents >30K characters
- **Chunk Strategy**: Preserves sentence boundaries where possible
- **Regex Compatibility**: Ensures all chunks support full regex search
- **Metadata Preservation**: Maintains document-level metadata across chunks

#### Error Handling
- **Graceful Degradation**: Continues processing on individual document failures
- **Retry Logic**: Automatic retries for transient failures
- **Signal Handling**: Clean shutdown on interruption
- **Memory Limits**: Detection and handling of memory constraints

---

### CorpusScanner.php
**Purpose**: Hawaiian language analysis and text processing

#### Hawaiian Language Features
- **Word Loading**: Loads Hawaiian vocabulary from file
- **Normalization**: Standardizes Hawaiian text (case, diacritics)
- **Ratio Calculation**: Computes Hawaiian vs total word ratios
- **Caching System**: Caches ratios for performance
- **Quality Metrics**: Various text quality assessments

#### Text Processing
- **Sentence Splitting**: Intelligent sentence boundary detection
- **Sentence Filtering**: Removes low-quality or irrelevant sentences
- **Word Counting**: Accurate word counts with Hawaiian awareness
- **Hash Generation**: Sentence hashing for deduplication

#### Cache Management
- **Ratio Cache**: Stores computed Hawaiian ratios
- **LRU Eviction**: Automatic cache size management
- **Performance Tracking**: Cache hit/miss statistics
- **Memory Efficiency**: Configurable cache limits

---

### MetadataExtractor.php
**Purpose**: NLP metric computation and sentence analysis

#### NLP Metrics
- **Hawaiian Word Ratio**: Percentage of Hawaiian words
- **Entity Count**: Basic named entity detection
- **Boilerplate Score**: Content quality assessment
- **Word Count**: Accurate word counting
- **Sentence Length**: Character and word length metrics
- **Position Tracking**: Sentence positions within documents
- **Hash Generation**: Unique sentence identifiers

#### Quality Assessment
- **Boilerplate Detection**: Identifies low-value content
- **Content Scoring**: Relevance and quality metrics
- **Language Detection**: Hawaiian vs English content ratios
- **Structural Analysis**: Document organization assessment

#### Integration
- **CorpusScanner Delegation**: Leverages existing Hawaiian analysis
- **Caching Support**: Utilizes shared caching systems
- **Performance Optimization**: Efficient metric computation

---

### EmbeddingClient.php
**Purpose**: Interface to Python embedding service

#### Service Communication
- **HTTP Client**: RESTful API communication
- **Batch Processing**: Efficient batch embedding generation
- **Error Handling**: Service availability and response validation
- **Timeout Management**: Configurable request timeouts

#### Embedding Types
- **Document Embeddings**: Full document vector representations
- **Sentence Embeddings**: Individual sentence vectors
- **Batch Operations**: Multiple documents/sentences per request

#### API Endpoints
- **/embed**: Single document embedding
- **/embed_sentences**: Batch sentence embedding
- **/health**: Service health check

#### Error Recovery
- **Service Detection**: Automatic service availability checks
- **Retry Logic**: Configurable retry attempts
- **Graceful Failures**: Fallback behaviors when service unavailable

---

### QueryBuilder.php
**Purpose**: Search query construction and optimization

#### Query Types
- **Full Text**: Standard Elasticsearch text queries
- **Regex Queries**: Pattern matching across text fields
- **Embedding Queries**: Vector similarity searches
- **Hybrid Queries**: Combined text and vector searches

#### Field Targeting
- **Multi-field Search**: Searches across text, text.raw, text_full
- **Field Boosting**: Relevance score adjustments
- **Chunk Aggregation**: Combines results from document chunks

#### Query Optimization
- **Performance Tuning**: Efficient query structures
- **Result Limiting**: Configurable result counts
- **Score Normalization**: Consistent scoring across query types

---

### MetadataCache.php
**Purpose**: Caching system for computed values

#### Cache Types
- **Hawaiian Ratios**: Computed language ratios
- **NLP Metrics**: Expensive computation results
- **Embedding Vectors**: Generated embeddings

#### Cache Management
- **LRU Eviction**: Least recently used removal
- **Size Limits**: Configurable memory usage
- **Performance Tracking**: Hit/miss statistics
- **Thread Safety**: Concurrent access handling
- **Persistence**: Writes through to elastic search index on eviction, checkpoints and completed indexing

---

### IndexSchemaValidator.php
**Purpose**: Elasticsearch schema validation and auto-repair

#### Validation Features
- **Index Existence**: Verifies required indices exist
- **Field Validation**: Checks field types and mappings
- **Document Structure**: Validates required document fields
- **Metadata Schema**: Ensures proper metadata structure

#### Auto-repair Capabilities
- **Missing Indices**: Creates indices with proper mappings
- **Missing Fields**: Adds required fields with correct types
- **Invalid Metadata**: Fixes corrupted metadata documents
- **Schema Migration**: Updates schemas during upgrades

#### Error Reporting
- **Detailed Diagnostics**: Specific validation failure messages
- **Fix Confirmation**: Reports successful auto-repairs
- **Fatal Errors**: Clear messages for non-repairable issues

---

## Embedding Service
- **Provides vector embedding for a sentence**
- **Provides vector embedding for an array of sentences**
- **Accessible over http**
- **Managed through systemd**
- **Uses system-wide python binary and libraries**
- **Uses memory efficiently**

---

## Utility Scripts (scripts/ directory)

### rebuild_metadata_index.php
**Purpose**: Rebuilds sentence metadata from existing corpus

#### Features
- **Batch Processing**: Configurable batch sizes for memory efficiency
- **Progress Tracking**: Detailed progress with document/sentence counts
- **Metadata Extraction**: Regenerates all NLP metrics
- **Error Recovery**: Handles individual document failures gracefully
- **Performance Timing**: Detailed timing breakdown
- **Verbose Logging**: Optional detailed output

#### Modes
- **Recreate Mode**: Deletes and rebuilds metadata index
- **Update Mode**: Updates existing metadata
- **Dry Run**: Simulates processing without changes

### direct_metadata_test.php
**Purpose**: Validates metadata index integrity

#### Test Coverage
- **Document Counts**: Verifies expected document quantities
- **Sentence Counts**: Validates sentence-level metadata
- **Field Validation**: Checks all required fields exist
- **Data Quality**: Validates metric ranges and formats
- **Index Health**: Overall index status assessment

#### Output
- **Summary Statistics**: Counts and health metrics
- **Verbose Details**: Individual document analysis
- **Error Detection**: Missing or invalid data identification

---

## Configuration Requirements

### Environment Variables
- **ELASTICSEARCH_URL**: Elasticsearch cluster endpoint
- **ELASTICSEARCH_USERNAME**: Authentication username
- **ELASTICSEARCH_PASSWORD**: Authentication password
- **EMBEDDING_SERVICE_URL**: Python embedding service URL

### File Dependencies
- **hawaiian_words.txt**: Hawaiian vocabulary file (14,161+ words)
- **composer.json**: PHP dependencies configuration
- **vendor/autoload.php**: Composer autoloader (correct path: /var/www/html/vendor/autoload.php)

### Index Configuration
- **hawaiian_hybrid**: Main document index
- **hawaiian_hybrid-metadata**: Sentence-level metadata
- **hawaiian_hybrid-source-metadata**: Processing tracking metadata

### External Configuration
- **index_mapping.json**: Defines indices
- **metadata_mapping.json**: Defines the metadata index
- **query_templates.json**: Defines how queries are formulated to elastic search

---

## Performance Requirements

### Timing Benchmarks
- **Document Processing**: ~6-12 seconds per document average
- **Embedding Generation**: Major bottleneck (25-30% of total time)
- **Elasticsearch Indexing**: <2% of total time (very fast)
- **Hawaiian Ratio Calculation**: <1% of total time
- **Metadata Extraction**: <2% of total time

### Memory Management
- **Batch Size**: Default 1 document for memory efficiency
- **Cache Limits**: Configurable cache size limits
- **Periodic Cleanup**: Regular cache clearing to prevent memory issues
- **Chunk Size**: Documents >32K chars automatically chunked

### Scalability Targets
- **Throughput**: 400-600 documents per hour
- **Total Corpus**: Support for 20,000+ documents
- **Concurrent Access**: Multiple query clients supported
- **Index Size**: Efficient storage with proper field mappings

---

## Data Quality Requirements

### Hawaiian Language Processing
- **Vocabulary**: 14,000+ Hawaiian words loaded from hawaiian_words.txt
- **Ratio Accuracy**: Precise Hawaiian vs total word calculations
- **Normalization**: Consistent text normalization (case, diacritics)
- **Quality Filtering**: Remove low-quality or boilerplate content

### NLP Metrics
- **Sentence-level**: Individual sentence analysis and scoring
- **Document-level**: Aggregate document quality metrics
- **Consistency**: Reproducible metrics across runs
- **Performance**: Efficient computation without accuracy loss

### Search Accuracy
- **Regex Support**: Full regex functionality across all document sizes
- **Embedding Quality**: High-quality vector representations
- **Multi-mode Search**: Consistent results across search types
- **Result Relevance**: Proper scoring and ranking

---

## Error Handling Requirements

### Graceful Failures
- **Service Dependencies**: Handle embedding service outages
- **Network Issues**: Elasticsearch connection problems
- **Memory Limits**: Handle out-of-memory conditions
- **Data Corruption**: Invalid or missing data recovery

### User Feedback
- **Clear Messages**: Descriptive error messages
- **Progress Indication**: Real-time processing status
- **Recovery Suggestions**: Actionable error resolution steps
- **Logging**: Comprehensive structured error logging

### System Recovery
- **Checkpoint System**: Regular metadata saves for recovery
- **Partial Processing**: Continue after individual failures
- **Signal Handling**: Clean shutdown on interruption
- **State Preservation**: Maintain processing state across restarts

---

## Integration Requirements

### External Services
- **Embedding Service**: Python-based embedding generation
- **Hawaiian Corpus API**: Source document retrieval
- **Elasticsearch Cluster**: Document storage and search

### File System
- **Read Access**: Hawaiian words file, configuration files
- **Write Access**: Log files, temporary processing files
- **Path Handling**: Correct autoloader path resolution

### Process Management
- **Signal Handling**: SIGINT/SIGTERM for graceful shutdown
- **Memory Monitoring**: Track and limit memory usage
- **Process Isolation**: Safe concurrent execution

---

## Testing Requirements

### Functional Testing
- **Index Creation**: Verify all indices created correctly
- **Document Processing**: End-to-end document indexing
- **Search Functionality**: All search modes working
- **Metadata Accuracy**: Correct NLP metric computation
- **Error Conditions**: Proper error handling

### Performance Testing
- **Timing Benchmarks**: Compare against baseline timings
- **Memory Usage**: Monitor memory consumption patterns
- **Throughput Testing**: Documents processed per hour
- **Search Response**: Query response time measurements

### Regression Testing
- **Feature Preservation**: All documented features working
- **Data Consistency**: Reproducible results across runs
- **API Compatibility**: Consistent interfaces maintained
- **Configuration Handling**: All command-line options working

---

## Security Requirements

### Authentication
- **Elasticsearch**: Secure authentication with credentials
- **SSL/TLS**: Encrypted connections to services
- **Certificate Validation**: Proper certificate handling

### Data Protection
- **Input Validation**: Sanitize all user inputs
- **SQL Injection**: Not applicable (no SQL database)
- **Path Traversal**: Safe file path handling
- **Resource Limits**: Prevent resource exhaustion attacks

---

## Maintenance Requirements

### Regular Operations
- **Index Optimization**: Periodic Elasticsearch maintenance
- **Cache Management**: Regular cache size monitoring
- **Performance Monitoring**: Track processing speed trends
- **Data Validation**: Regular integrity checks

### Updates and Changes
- **Schema Evolution**: Handle mapping updates gracefully
- **Code Changes**: Preserve all documented functionality
- **Dependency Updates**: Maintain compatibility with updates
- **Configuration Changes**: Validate configuration modifications

---

## Monitoring and Logging

### Performance Metrics
- **Processing Speed**: Documents per hour tracking
- **Error Rates**: Failed document processing rates
- **Resource Usage**: Memory and CPU utilization
- **Service Health**: External service availability

### Operational Logging
- **Processing Progress**: Real-time indexing status
- **Error Details**: Comprehensive error information
- **Performance Data**: Detailed timing breakdowns
- **System Events**: Startup, shutdown, configuration changes

---

## Future Considerations

### Scalability Improvements
- **Parallel Processing**: Multi-threaded document processing
- **Distributed Processing**: Multiple indexing nodes
- **Caching Enhancements**: More sophisticated caching strategies
- **Performance Optimization**: Further speed improvements

### Feature Enhancements
- **Advanced NLP**: Enhanced language processing capabilities
- **Search Improvements**: Better ranking and relevance
- **User Interface**: Web-based management interface
- **API Expansion**: RESTful API for external integration

This specification serves as the definitive reference for all Hawaiian Search System functionality and must be consulted before any code modifications to prevent regression issues.

