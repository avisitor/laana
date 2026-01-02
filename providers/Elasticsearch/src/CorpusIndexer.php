<?php

namespace HawaiianSearch;

use GuzzleHttp\Client as HttpClient;
use NlpTools\Tokenizers\WhitespaceTokenizer;
use HawaiianSearch\SourceRetriever;
use HawaiianSearch\SourceIterator;
use HawaiianSearch\ElasticsearchScrollIterator;

class CorpusIndexer
{
    private const MIN_DOC_HAWAIIAN_WORD_RATIO = 0.1;
    private const MIN_SENTENCE_HAWAIIAN_WORD_RATIO = 0.5;
    
    // Pre-compiled regex patterns for better performance
    private const SENTENCE_SPLIT_PATTERN = '/(?<=[.?!])\s+/';
    private const WORD_SPLIT_PATTERN = '/\s+/';
    private const DIACRITIC_PATTERN = '/[āĀēĒīĪōŌūŪ\‘]/u';

    private const HAWAIIAN_WORDS_FILE = __DIR__ . '/../../hawaiian_words.txt';
    
    private ElasticsearchClient $client;
    private CorpusScanner $scanner;
    private MetadataExtractor $metadataExtractor;
    private array $config;                  // Configuration array
    private bool $recreate;
    private bool $dryrun;
    private int $batchSize;
    private int $sentenceBatchSize = 100; // Max sentences per embedding request
    private int $checkpointInterval;
    private array $sources = [];
    private array $sourceMeta;
    private HttpClient $httpClient;
    private SourceRetriever $sourceRetriever;
    private ?int $maxDocuments = null;
    private ?int $sourceId = null;
    private ?string $groupName = null;
    private int $processedDocuments = 0;
    private int $actuallyIndexedDocuments = 0;
    private ?string $sourceIndexForReindex = null;
    private ?string $sourceIndex = null;
    private ?string $targetIndex = null;
    private int $totalDocuments;
    
    // Cache for expensive operations
    private array $hawaiianWordSet = []; // Hash set for O(1) lookups
    private array $ratioCache = [];      // Cache Hawaiian word ratios
    private int $cacheHits = 0;
    
    private static $instance = null;
    private bool $updateProperties;
    private bool $updateMetadata;
    private bool $updateSourceMetadata;
    private bool $importRaw;
    private bool $done = false;
    private bool $useSplitIndices;

    public function __construct(array $config, bool $recreate = false, bool $dryrun = false, ?string $sourceIndexForReindex = null)
    {
        self::$instance = $this; // For signal handler
        $config['dryrun'] = $dryrun;
        $this->config = $config;
        $this->recreate = $recreate;
        $this->dryrun = $dryrun;
        $this->sourceIndexForReindex = $sourceIndexForReindex;
        $this->verbose = $config['verbose'] ?? false;
        $this->config['quiet'] = $config['quiet'] ?? false; // Ensure quiet is always defined
        $this->batchSize = $config['BATCH_SIZE'] ?? 1;
        $this->sentenceBatchSize = $config['SENTENCE_BATCH_SIZE'] ?? 100;
        $this->checkpointInterval = $config['CHECKPOINT_INTERVAL'] ?? 50;
        $this->maxDocuments = $config['MAX_DOCUMENTS'] ?? null;
        $this->sourceId = $config['SOURCE_ID'] ?? null;
        $this->groupName = $config['groupName'] ?? null;
        $this->updateProperties = $config['updateProperties'] ?? false;
        $this->updateMetadata = $config['updateMetadata'] ?? false;
        $this->updateSourceMetadata = $config['updateSourceMetadata'] ?? false;
        $this->importRaw = $config['importRaw'] ?? false;
        $this->useSplitIndices = $config['SPLIT_INDICES'] ?? true; // Always use split indices by default

        // Initialize timing arrays - always track performance
        $this->timings = [
            'fetch_sources' => 0,
            'fetch_text' => 0,
            'parallel_fetch' => 0,
            'tokenization' => 0,
            'hawaiian_ratio_calc' => 0,
            'sentence_splitting' => 0,
            'sentence_embedding' => 0,
            'document_embedding' => 0,
            'elasticsearch_indexing' => 0,
            'document_indexing' => 0,
            'sentence_indexing' => 0,
            'document_verification' => 0,
            'checkpoint_operations' => 0,
            'final_checkpoint' => 0,
            'source_iterator_setup' => 0,
            'source_batch_fetching' => 0,
            'metadata_operations' => 0,
            'metadata_extraction' => 0,
            'cache_operations' => 0,
            // More granular document prep breakdown
            'source_validation' => 0,
            'document_chunking' => 0,
            'sentence_filtering' => 0,
            'vector_validation' => 0,
            'document_assembly' => 0,
            'text_processing' => 0,
            'retry_operations' => 0,
            'sentence_object_construction' => 0,
            'sentence_processing_other' => 0,
            'total_document_processing' => 0,
            'split_index_creation' => 0,
            'individual_sentence_metadata' => 0
        ];

        // Also register shutdown function as fallback

        $client_config = [
            'verbose' => $config['verbose'] ?? false
        ];
        $client_config['indexName'] = $config['COLLECTION_NAME'] ?? '';
        $this->client = new ElasticsearchClient($client_config);
        
        // Use centralized Hawaiian word loading
        $this->hawaiianWordSet =
            HawaiianWordLoader::loadAsHashSetWithOutput(self::HAWAIIAN_WORDS_FILE, true);
        
        // Initialize metadata extractor
        $this->metadataExtractor = new MetadataExtractor($this->client, $this->hawaiianWordSet);
        
        if (!($this->updateProperties ||
              $this->updateMetadata ||
              $this->importRaw ||
              $this->updateSourceMetadata)
        ) {
            if ($this->dryrun && $recreate ) {
                $this->print( "Skipping index deletion because dryrun" );
            } else {
                $this->client->createIndex($recreate);
                //$this->metadataExtractor->createMetadataIndex($recreate);
            }
        }

        // For testing if a document is present
        $this->sourceIndex = $this->sourceIndexForReindex ?? $this->client->getIndexName();
        // Where to put processed documents
        $this->targetIndex = $this->client->getIndexName();

        $this->scanner = new CorpusScanner($this->client, $config);
        $this->scanner->setHawaiianWords($this->hawaiianWordSet);
        
        $this->sourceMeta = $this->initializeSourceMetadata();
        $this->httpClient = new HttpClient();

        $this->sourceRetriever = new SourceRetriever([
            'httpClient' => $this->httpClient,
            'client' => $this->client,
            'config' => $this->config
        ]);

        if ($this->dryrun) {
            $count = count(array_keys($this->sourceMeta));
            $this->print( "Source metadata loaded: $count processed IDs" );
        }
        
        $this->print( "⏱️  Performance timing enabled" );
        $this->print( "🧠 Metadata extraction enabled" );
        
        if ($this->maxDocuments) {
            $this->print( "🎯 Limited to maximum {$this->maxDocuments} documents" );
        }
        //$this->print( "config: " . var_export( $this->config, true ) );
    }

    protected function print( $msg ) {
        if (!isset($this->config["quiet"]) || !$this->config["quiet"]) {
            echo "CorpusIndexer: " . "$msg\n";
        }
    }

    /**
     * Retry embedding operations on timeout/connection failures
     * Not working - it takes more like 30 seconds for the embedding service
     * to restart, so short retries are futile
     */
    private function retryEmbeddingOperation(callable $operation, string $operationName, string $context, int $maxRetries = 3): mixed
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
            try {
                if ($attempt > 1) {
                    // Progressive delay: 2s, 4s, 6s, 8s, 10s for subsequent retries
                    $delay = min(2 * $attempt, 10);
                    $this->print( "🔄 Retry attempt {$attempt} for {$operationName} ({$context}) - waiting {$delay}s..." );
                    sleep($delay);
                    
                    // For connection refused errors, check if service is back up
                    if ($attempt > 2 && isset($this->embeddingClient)) {
                        try {
                            $health = $this->embeddingClient->getHealth();
                            if ($health && isset($health['status']) && $health['status'] === 'healthy') {
                               $this->print( "✅ Embedding service is healthy - proceeding with retry" );
                            } else {
                                $this->print( "⚠️  Embedding service health check failed - continuing anyway" );
                            }
                        } catch (Exception $e) {
                            $this->print( "⚠️  Could not check embedding service health: " . $e->getMessage() );
                        }
                    }
                }
                
                $result = $operation();
                
                return $result;
                
            } catch (\GuzzleHttp\Exception\ConnectException | \GuzzleHttp\Exception\RequestException $e) {
                $lastException = $e;
                $errorMsg = $e->getMessage();
                
                // Check if this is a timeout or connection error that we should retry
                $isConnectionRefused = strpos($errorMsg, 'cURL error 7') !== false && strpos($errorMsg, 'Connection refused') !== false;
                $isRetriableError = strpos($errorMsg, 'cURL error 52') !== false ||
                    strpos($errorMsg, 'cURL error 7') !== false ||
                    strpos($errorMsg, 'timeout') !== false ||
                    strpos($errorMsg, 'Empty reply') !== false;
                
                if ($isRetriableError) {
                    // For connection refused, give more detailed feedback
                    if ($isConnectionRefused) {
                        $this->print( "🔌 Embedding service connection refused - service may be overloaded or restarting" );
                    }
                    
                    if ($attempt <= $maxRetries) {
                        $this->print( "⚠️  {$operationName} timeout/connection error for {$context} (attempt {$attempt}/{$maxRetries}): retrying..." );
                        continue;
                    } else {
                        $this->print( "❌ {$operationName} failed after {$maxRetries} retry attempts for {$context}" );
                    }
                } else {
                    // Not a retryable error, fail immediately
                    $this->print( "❌ {$operationName} failed with non-retryable error for {$context}: {$errorMsg}" );
                    break;
                }
            } catch (Exception $e) {
                $lastException = $e;
                $this->print( "❌ {$operationName} failed with unexpected error for {$context}: " . $e->getMessage() );
                break;
            }
        }
        
        // If we get here, all attempts failed
        throw $lastException;
    }

    private function initializeSourceMetadata(): array
    {
        $sourceMetadataIndexName = $this->client->getSourceMetadataName();
        $this->print( "initializeSourceMetadata from  $sourceMetadataIndexName" );

        if ($this->recreate) {
            $this->print( "Recreate flag is set. Initializing with empty metadata." );
            if ($this->dryrun ) {
                $this->print( "Skipping deletion because dryrun" );
            } else {
                $this->client->deleteIndex( $sourceMetadataIndexName );
            }
        }
        
        $sourceMeta = $this->client->getSourceMetadata();
        $this->print( "Read " . count($sourceMeta) . " source metadata records" );
        // Turn array into map
        $this->sourceMeta = [];
        foreach( $sourceMeta as $source ) {
            $this->sourceMeta[$source['_id']] = $source;
        }
        return $this->sourceMeta;
    }

    private function fetchSourceIterator( $sourceid = 0 )
    {
        if ($this->sourceIndexForReindex) {
            return new ElasticsearchScrollIterator( $this->client, $this->sourceIndexForReindex );
        } else {
            return new SourceIterator( $sourceid, $this->groupName );
        }
    }
    
    private function fetchSource(string $sourceid, string $type): ?string
    {
        $result = $this->sourceRetriever->fetchSource( $sourceid, $type );
        return $result;
    }
    
    private function fetchText(string $sourceid): ?string
    {
        return $this->fetchSource( $sourceid, 'plain' );
    }
    
    private function fetchRaw(string $sourceid): ?string
    {
        return $this->fetchSource( $sourceid, 'html' );
    }
    
    private function checkpointSourceMetadata(): void
    {
        $sources = [];
        foreach( array_keys( $this->sourceMeta ) as $sourceid ) {
            $source = $this->sourceMeta[$sourceid];
            $source['sourceid'] = $sourceid;
            //$sources[] = ['_source' => $source];
            $sources[] = $source;
        }
        $this->client->saveSourceMetadata($sources);
        $this->print( "💾 Checkpointing source metadata at document #" . $this->processedDocuments );
        
        // Clear ratio cache periodically to prevent memory growth
        if (count($this->ratioCache) > 1000) {
            $this->ratioCache = [];
            $this->print( "🧹 Cleared ratio cache" );
        }
        
        gc_collect_cycles();
        $this->print( "🔄 Source metadata checkpoint saved. Cache hits: {$this->cacheHits}" );
    }

    // Optimized Hawaiian word ratio calculation with caching - now delegates to CorpusScanner
    private function calculateHawaiianWordRatio(string $text): float
    {
        $hash = md5($text);
        if (isset($this->ratioCache[$hash])) {
            $this->cacheHits++;
            return $this->ratioCache[$hash];
        }
        
        // Delegate to CorpusScanner for actual calculation
        $ratio = $this->scanner->calculateHawaiianWordRatio($text);
        $this->ratioCache[$hash] = $ratio;
        
        return $ratio;
    }

    // Process sentences in batches for embedding efficiency AND extract metadata
    private function processSentencesInBatches(array $sentenceTexts, string $sourceid): array
    {
        $count = count($sentenceTexts);
        $this->print( "processSentencesInBatches [$count] [$sourceid]" );
        
        $validSentences = [];
        $sentencesToEmbed = [];
        $sentencesMetadata = []; // For bulk metadata extraction
        
        // Filter sentences first and prepare metadata extraction
        foreach ($sentenceTexts as $idx => $sText) {
            $hawaiianRatio = $this->calculateHawaiianWordRatio($sText);
            if ($hawaiianRatio >= self::MIN_SENTENCE_HAWAIIAN_WORD_RATIO) {
                $validSentences[$idx] = $sText;
                $sentencesToEmbed[] = $sText;
                
                // Prepare for metadata extraction
                $sentencesMetadata[] = [
                    'text' => $sText,
                    'doc_id' => $sourceid,
                    'position' => $idx
                ];
            }
        }
        
        if (empty($sentencesToEmbed)) {
            return [];
        }
        
        // Extract and save sentence metadata in bulk
        if (!$this->dryrun) {
            $this->metadataExtractor->bulkSaveSentenceMetadata($sentencesMetadata);
        }
        
        // Batch embedding calls to prevent overwhelming the embedding service
        $timerSystemSentenceEmbedding = TimerFactory::timer('sentence_embedding'); // Timer system equivalent
        $embeddingClient = $this->client->getEmbeddingClient();
        
        $sentenceVectors = [];
        $totalSentences = count($sentencesToEmbed);
        
        if ($totalSentences > $this->sentenceBatchSize) {
            $this->print( "⚡ Processing {$totalSentences} sentences in batches of {$this->sentenceBatchSize}" );
        }
        
        // Process sentences in batches
        for ($i = 0; $i < $totalSentences; $i += $this->sentenceBatchSize) {
            $batch = array_slice($sentencesToEmbed, $i, $this->sentenceBatchSize);
            $batchSize = count($batch);
            
            if ($totalSentences > $this->sentenceBatchSize) {
                $batchNum = intval($i / $this->sentenceBatchSize) + 1;
                $totalBatches = ceil($totalSentences / $this->sentenceBatchSize);
                $this->print( "   📦 Processing batch {$batchNum}/{$totalBatches} ({$batchSize} sentences)" );
            }
            
            // Retry embedding sentences on timeout
            $batchVectors = $this->retryEmbeddingOperation(function() use ($embeddingClient, $batch) {
                return $embeddingClient->embedSentences($batch);
            }, "embedSentences", count($batch) . " sentences");
            
            // Validate batch vectors before merging
            if (!is_array($batchVectors)) {
                $this->print("⚠️ Embedding service returned invalid result for batch of " . count($batch) . " sentences");
                $this->print("   Expected array, got: " . gettype($batchVectors));
                $this->print("   Value: " . json_encode($batchVectors));
                continue; // Skip this batch
            }
            
            $this->print("🔍 Debug: Batch " . (intval($i / $this->sentenceBatchSize) + 1) . " returned " . count($batchVectors) . " vectors for " . count($batch) . " sentences");
            
            // Filter out any null/invalid vectors
            $validBatchVectors = [];
            foreach ($batchVectors as $idx => $vector) {
                if (is_array($vector) && !empty($vector)) {
                    $validBatchVectors[] = $vector;
                } else {
                    $this->print("⚠️ Skipping invalid vector at batch index {$idx}: type=" . gettype($vector) . ", value=" . json_encode($vector));
                }
            }
            
            // Append valid batch vectors to main array
            $sentenceVectors = array_merge($sentenceVectors, $validBatchVectors);
        }
        
        // Timer should automatically destruct here when going out of scope
        
        // Debug: Check vector count vs sentence count
        $this->print("🔍 Debug: validSentences=" . count($validSentences) . ", sentenceVectors=" . count($sentenceVectors));
        if (count($validSentences) !== count($sentenceVectors)) {
            $this->print("⚠️ Mismatch: " . count($validSentences) . " valid sentences but " . count($sentenceVectors) . " vectors returned");
        }
        
        // Sentence object construction and validation
        $sentenceObjects = [];
        $vectorIdx = 0;
        foreach ($validSentences as $originalIdx => $sText) {
            // Validate vector before using it
            if (!isset($sentenceVectors[$vectorIdx]) || !is_array($sentenceVectors[$vectorIdx]) || empty($sentenceVectors[$vectorIdx])) {
                $this->print("⚠️ Skipping sentence at index {$vectorIdx} - invalid or missing vector for doc {$sourceid}");
                $this->print("   Vector type: " . gettype($sentenceVectors[$vectorIdx] ?? 'undefined'));
                $this->print("   Vector value: " . json_encode($sentenceVectors[$vectorIdx] ?? null));
                $vectorIdx++;
                continue;
            }

            // Check vector dimensions
            $vectorDims = count($sentenceVectors[$vectorIdx]);
            if ($vectorDims !== 384) {
                $this->print("⚠️ Vector dimension mismatch at index {$vectorIdx} for doc {$sourceid}: expected 384, got {$vectorDims}");
                $this->print("   First 5 values: " . json_encode(array_slice($sentenceVectors[$vectorIdx], 0, 5)));
                $vectorIdx++;
                continue;
            }

            // Extract metadata for this sentence
            $sentenceMetadata = null;
            if (!$this->dryrun) {
                $sentenceMetadata = $this->metadataExtractor->analyzeSentence($sText, $sourceid);
            }
            
            // Create sentence object with both vector and metadata
            $sentenceObject = [
                "text" => $sText,
                "vector" => $sentenceVectors[$vectorIdx],
                "position" => $originalIdx,
                "doc_id" => $sourceid
            ];
            
            // Debug: Validate the vector format before adding to sentence object
            if (!is_array($sentenceObject["vector"])) {
                $this->print("❌ CRITICAL: Sentence vector is not an array!");
                $this->print("   Type: " . gettype($sentenceObject["vector"]));
                $this->print("   Value: " . json_encode($sentenceObject["vector"]));
                $this->print("   This will cause Elasticsearch mapping error!");
                // Skip this sentence to prevent the error
                $vectorIdx++;
                continue;
            }
            
            if (count($sentenceObject["vector"]) !== 384) {
                $this->print("❌ CRITICAL: Sentence vector has wrong dimensions: " . count($sentenceObject["vector"]));
                $this->print("   First 5 values: " . json_encode(array_slice($sentenceObject["vector"], 0, 5)));
                // Skip this sentence to prevent the error
                $vectorIdx++;
                continue;
            }
            //echo count($sentenceObject['vector']) . " values in vector\n";

            // Merge metadata fields if available
            if ($sentenceMetadata) {
                $sentenceObject["hawaiian_word_ratio"] = $sentenceMetadata["hawaiian_word_ratio"] ?? null;
                $sentenceObject["word_count"] = $sentenceMetadata["word_count"] ?? null; 
                $sentenceObject["entity_count"] = $sentenceMetadata["entity_count"] ?? null;
                $sentenceObject["boilerplate_score"] = $sentenceMetadata["boilerplate_score"] ?? null;
                $sentenceObject["length"] = $sentenceMetadata["length"] ?? null;
                $sentenceObject["frequency"] = $sentenceMetadata["frequency"] ?? null;
            }
            
            $sentenceObjects[] = $sentenceObject;
            $vectorIdx++;
        }
        
        // Note: Not recording 'total_document_prep' here to avoid double-counting 
        // Individual operations (embedding, filtering, etc.) are already timed separately
        return $sentenceObjects;
    }

    private function processSource(array $source, int $indexCounter): ?array
    {
        $this->print( "processSource: $indexCounter / {$source['sourcename']}" );
        // Check if we've reached the document limit
        if ($this->checkMax()) {
            $this->print( "🎯 Reached maximum indexed document limit ({$this->maxDocuments}), stopping." );
            $this->done = true;
            return null;
        }

        
        $sourceid = (string)($source['sourceid'] ?? $source['doc_id']) ?? ''; // Handle doc_id from ES source
        if ( !$this->dryrun ) {
            if ( isset( $this->sourceMeta[$sourceid] ) ) {
                if( !$this->groupName && !$this->sourceId ) {
                    $meta = $this->sourceMeta[$sourceid];

                    //$this->print( "source metadata record: " . json_encode( $meta ) );
                    if( isset( $meta['_source']['discarded'] ) ) {
                        $this->print( "Skipping discarded {$sourceid}" );
                    } else {
                        $this->print( "Preset sourceid: {$this->sourceId}, Preset group: {$this->groupName}" );
                        $this->print( "Skipping already indexed {$sourceid}" );
                    }
                    return null;
                }
            } else {
                $this->sourceMeta[$sourceid] = $source;
            }
        }

        $this->print( "[ $indexCounter / {$this->totalDocuments}] " .
                      "Reviewing sourceid=$sourceid (" . ($source['sourcename'] ?? 'N/A') );

        $doc = $this->client->getDocumentOutline( $sourceid, $this->targetIndex );
        if( $doc && sizeof($doc) && !$this->groupName && !$this->sourceId ) {
            $this->print( "Document found, skipping already indexed {$sourceid} {$source['sourcename']} in {$this->targetIndex}" );
            return null;
        }
        

        $text = '';
        $sentenceObjects = [];
        $docHawaiianWordRatio = 0.0;
        $originalText = '';
        $chunks = [];
        $textVector = null;

        if ($this->sourceIndexForReindex) {
            // Re-indexing from existing ES index: fetch full document and regenerate vectors
            $this->print("  Fetching full document for re-indexing: $sourceid from {$this->sourceIndex}...");
            $fullDoc = $this->client->getDocument($sourceid, $this->sourceIndex);

            if (!$fullDoc) {
                $this->print( "⚠️ Skipping: Could not retrieve full document for $sourceid from {$this->sourceIndex}." );
                $this->sourceMeta[$sourceid]['discarded'] = true;
                return null;
            }

            $originalText = $fullDoc['text'] ?? '';
            $text = $originalText; // Use full text for re-embedding
            $sentenceObjects = $fullDoc['sentences'] ?? []; // Get existing sentence objects
            $docHawaiianWordRatio = $fullDoc['hawaiian_word_ratio'] ?? 0.0;
            $chunks = $fullDoc['text_chunks'] ?? []; // Keep existing chunks if any

            // Regenerate text_vector
            if (!empty($text)) {
                $this->print("  Regenerating text vector for doc_id: {$sourceid}...");
                $textVector = $this->retryEmbeddingOperation(function() use ($text) {
                    return $this->client->getEmbeddingClient()->embedText($text, 'passage: ');
                }, "embedText", "text (" . strlen($text) . " chars)");
            }

            // Regenerate sentence vectors
            if (!empty($sentenceObjects)) {
                $sentenceTexts = array_column($sentenceObjects, 'text');
                if (!empty($sentenceTexts)) {
                    $this->print("  Regenerating " . count($sentenceTexts) . " sentence vectors for doc_id: {$sourceid}...");
                    $newSentenceVectors = $this->retryEmbeddingOperation(function() use ($sentenceTexts) {
                        return $this->client->getEmbeddingClient()->embedSentences($sentenceTexts, 'passage: ');
                    }, "embedSentences", count($sentenceTexts) . " sentences");
                    
                    $vectorIdx = 0;
                    $validSentenceObjects = [];
                    foreach ($sentenceObjects as $s) { // Remove reference to avoid modifications
                        if (isset($s['text']) && isset($newSentenceVectors[$vectorIdx]) && 
                            is_array($newSentenceVectors[$vectorIdx]) && !empty($newSentenceVectors[$vectorIdx])) {
                            $s['vector'] = $newSentenceVectors[$vectorIdx];
                            $validSentenceObjects[] = $s;
                        } else {
                            $this->print("⚠️ Skipping sentence with missing/invalid vector at index {$vectorIdx} for doc {$sourceid}");
                        }
                        $vectorIdx++;
                    }
                    $sentenceObjects = $validSentenceObjects; // Replace with valid sentences only
                }
            }

        } else {
            // Original indexing from external API
            $text = $this->fetchText($sourceid);
            if (!$text) {
                $this->print( "⚠️ Skipping: No text for {$sourceid}" );
                $this->sourceMeta[$sourceid]['discarded'] = true;
                return null;
            }

            // Use cached Hawaiian word ratio calculation
            $docHawaiianWordRatio = $this->calculateHawaiianWordRatio($text);

            if ($docHawaiianWordRatio < self::MIN_DOC_HAWAIIAN_WORD_RATIO) {
                $this->print( "⚠️ Skipping: Document {$sourceid} has a low Hawaiian word ratio ({$docHawaiianWordRatio})." );
                $this->sourceMeta[$sourceid]['english_only'] = true;
                return null;
            }

            // Split sentences using pre-compiled regex
            $sentenceTexts = preg_split(self::SENTENCE_SPLIT_PATTERN, $text, -1, PREG_SPLIT_NO_EMPTY);
            
            // Process sentences in batch for embedding efficiency AND metadata extraction
            // Note: processSentencesInBatches() has its own internal timers for sentence_embedding
            $sentenceObjects = $this->processSentencesInBatches($sentenceTexts, $sourceid);

            if (empty($sentenceObjects)) {
                $this->print( "⚠️ Skipping: No Hawaiian sentences found for {$sourceid}" );
                $this->sourceMeta[$sourceid]['english_only'] = true;
                return null;
            }

            $timerSystemDocEmbedding = TimerFactory::timer('document_embedding'); // Timer system equivalent
            $embeddingClient = $this->client->getEmbeddingClient();
            // Retry embedding text on timeout
            $textVector = $this->retryEmbeddingOperation(function() use ($embeddingClient, $text) {
                return $embeddingClient->embedText($text, 'passage: ');
            }, "embedText", "text (" . strlen($text) . " chars)");
            // Timer should automatically destruct here when going out of scope

            // Handle very long text by chunking for full regex support
            $originalText = $text;
            $chunks = [];
            $chunkSize = 30000; // Safe size under 32K limit
            
            if (strlen($text) > $chunkSize) {
                $this->print(  "⚠️  Document {$sourceid} has long text (" . number_format(strlen($originalText)) . " chars), creating chunks for full regex support" );
                
                // Split text into overlapping chunks for full regex support
                $overlapSize = 500; // Smaller overlap
                $position = 0;
                $chunkIndex = 0;
                $maxChunks = 20; // Reasonable limit
                
                while ($position < strlen($text) && $chunkIndex < $maxChunks) {
                    $remainingText = strlen($text) - $position;
                    $currentChunkSize = min($chunkSize, $remainingText);
                    
                    $chunk = substr($text, $position, $currentChunkSize);
                    
                    $chunks[] = [
                        "chunk_index" => $chunkIndex,
                        "chunk_text" => $chunk,
                        "chunk_start" => $position,
                        "chunk_length" => strlen($chunk)
                    ];
                    
                    // Move position forward, accounting for overlap
                    $position += $currentChunkSize - ($position + $currentChunkSize < strlen($text) ? $overlapSize : 0);
                    $chunkIndex++;
                }
                
                if ($position < strlen($text)) {
                    $this->print( "⚠️  Document {$sourceid}: Large document truncated to {$maxChunks} chunks" );
                }
            } else {
                // Document is short enough, use as single chunk
                $chunks[] = [
                    "chunk_index" => 0,
                    "chunk_text" => $text,
                    "chunk_start" => 0,
                    "chunk_length" => strlen($text)
                ];
            }

        }
            
        // Document assembly
        $doc = [
            "_index" => $this->client->getDocumentsIndexName(),
            "_id" => $sourceid,
            "_source" => [
                "doc_id" => $sourceid,
                "groupname" => isset($source['groupname']) ? $source['groupname'] : 'N/A',
                "sourcename" => isset($source['sourcename']) ? $source['sourcename'] : 'N/A',
                "text" => strlen($originalText) > 32000 ? substr($originalText, 0, 32000) . "..." : $originalText,  // Truncate main text field for keyword compatibility
                "text_chunks" => $chunks,  // Store chunks for regex searching (ONLY way to do regex on long docs)
                "text_vector" => $textVector,
                
                "sentences" => $sentenceObjects,
                "date" => isset($source['date']) ? $source['date'] : '',
                "authors" => isset($source['authors']) ? $source['authors'] : '',
                "link" => isset($source['link']) ? $source['link'] : '',
                "hawaiian_word_ratio" => $docHawaiianWordRatio
            ],
        ];

        // Final validation: Check all vectors in the document before indexing
        if (!is_array($doc['_source']['text_vector']) || empty($doc['_source']['text_vector'])) {
            $this->print("❌ CRITICAL: Document text_vector is invalid for {$sourceid}!");
            $this->print("   Type: " . gettype($doc['_source']['text_vector']));
            return null;
        }
        
        if (count($doc['_source']['text_vector']) !== 384) {
            $this->print("❌ CRITICAL: Document text_vector has wrong dimensions for {$sourceid}: " . count($doc['_source']['text_vector']));
            return null;
        }
        
        foreach ($doc['_source']['sentences'] as $idx => $sentence) {
            if (!is_array($sentence['vector']) || empty($sentence['vector'])) {
                $this->print("❌ CRITICAL: Sentence {$idx} vector is invalid for {$sourceid}!");
                $this->print("   Type: " . gettype($sentence['vector']));
                $this->print("   Value: " . json_encode($sentence['vector']));
                return null; // Fail the entire document to prevent the error
            }
            
            if (count($sentence['vector']) !== 384) {
                $this->print("❌ CRITICAL: Sentence {$idx} vector has wrong dimensions for {$sourceid}: " . count($sentence['vector']));
                return null; // Fail the entire document to prevent the error
            }
        }
        
        $this->print("✅ All vectors validated for document {$sourceid}: text_vector=" . count($doc['_source']['text_vector']) . "D, " . count($doc['_source']['sentences']) . " sentences with 384D vectors");

        $this->processedDocuments++;
        
        // Clear text from memory immediately
        unset($text);
        
        // Record total document processing time (includes all operations)
        return $doc;
    }

    private function verifyDocumentIndexed(string $docId): bool
    {
        // Force a refresh to ensure document is searchable
        //$this->client->refreshIndex();
        
        // Small delay to ensure refresh completes
        usleep(100000); // 100ms
        
        $doc = $this->client->getDocumentOutline($docId);
        return ($doc && count($doc) > 0);        
    }

    // Updates the source metadata index from Laana, only for documents
    // already in elastic search
    private function updateProperties(): void
    {
        $this->print("Updating properties for existing documents based on external sources...");
        
        $sourceMetadataIndexName = $this->client->getSourceMetadataName();
        $iterator = new SourceIterator(); // Iterate through all external sources
        $this->totalDocuments = $iterator->getSize();
        $this->print( "{$this->totalDocuments} external sources found." );
        $updatedCount = 0;
        $records = [];
        $allRecords = [];

        while( $sources = $iterator->getNext() ) {
            foreach( $sources as $source ) {
                //$this->print( "Source: " . var_export( $source, true ) );
                $sourceid = $source['sourceid'];
;
                if (!$sourceid) {
                    $this->print( "Source without sourceid" );
                    continue;
                }

                $properties = [
                    'sourceid' => $source['sourceid'],
                    'sourcename' => $source['sourcename'],
                    'groupname' => $source['groupname'],
                    'authors' => $source['authors'],
                    'date' => $source['date'],
                    'link' => $source['link'] ?? '',
                    'title' => $source['title'] ?? '',
                ];
                // Check if the document exists in our index
                if ($this->client->documentExists($sourceid)) {
                    $this->print("Updating properties for existing document {$sourceid}...");
                    $allRecords[] = $properties;
                }
                $discarded = ((int)($source['sentencecount']) < 1);
                $properties['discarded'] = $discarded;
                $properties['quality'] = 1.0;
                $records[] = ["_source"=>$properties];
                $updatedCount++;
                $discardedText = ($discarded) ? "(discarded)" : "";
                $this->print( $updatedCount . " / {$this->totalDocuments} : $sourceid $discardedText" );
            }
            //}
        }
        if (!$this->dryrun) {
            if( count($allRecords) > 0 ) {
                $this->client->updateManyDocumentProperties($allRecords);
            }
            $this->client->saveSourceMetadata( $records );
        }
        $this->print("Finished updating properties. Total documents updated: {$updatedCount}");
    }
    
    public function updateSourceMetadata(): void
    {
        $this->print("Updating source metadata...");
        // Source metadata
        $sourceMetadataIndexName = $this->client->getSourceMetadataName();
        $iterator = new ElasticsearchScrollIterator( $this->client, $this->client->getIndexName() );
        $i = 0;
        $records = [];
        while( $sources = $iterator->getNext() ) {
            foreach( $sources as $source ) {
                //$this->print( json_encode( $source ) );
                //$this->print( "Source: " . var_export( $source, true ) );
                $sourceid = (string)($source['doc_id'] ?? '');
                if (empty($sourceid)) continue;
                $discarded = ((int)($source['sentencecount']) < 1);
                $this->print("Updating properties for document {$sourceid}...");
                $properties = [
                    'sourceid' => $sourceid,
                    'sourcename' => $source['sourcename'],
                    'groupname' => $source['groupname'],
                    'authors' => $source['authors'],
                    'date' => $source['date'],
                    'link' => $source['link'] ?? '',
                    'title' => $source['title'] ?? '',
                    'quality' => 1.0,
                    'discarded' => $discarded,
                    'empty' => $discarded,
                ];
                $records[] = $properties;
                $i++;
            }
        }
        $this->client->saveSourceMetadata( $records );

        $this->print("Updated source metadata with $i processed source IDs.");
    }

    public function importOneRaw( $sourceid ) {
        $text = $this->fetchRaw( $sourceid );
        if( $text && strlen( $text ) > 0 ) {
            $this->print("Adding raw html for document {$sourceid}...");
            $this->client->indexRaw( $sourceid, $text );
        } else {
            $this->print("No raw html for document {$sourceid}");
        }
    }
    
    public function importRaw() {
        $index = $this->client->getContentName();
        $iterator = new SourceIterator( $this->sourceId, $this->groupName );
        $processed = 0;
        $already = 0;
        while( $sources = $iterator->getNext() ) {
            foreach( $sources as $source ) {
                //$this->print( "Source: " . var_export( $source, true ) );
                $sourceid = (string)($source['sourceid'] ?? '');
                if (empty($sourceid)) continue;
                if( !$this->sourceId && !$this->groupName ) {
                    if( isset( $this->sourceMeta[$sourceid] ) ) {
                        $meta = $this->sourceMeta[$sourceid];
                        //$this->print( "source metadata record: " . json_encode( $meta ) );
                        if( isset( $meta['_source']['discarded'] ) ) {
                            $this->print( "Skipping discarded {$sourceid}" );
                            continue;
                        } else {
                            $this->print( "Skipping already indexed {$sourceid}" );
                            continue;
                        }
                    } 
                    if( $this->client->documentExists( $sourceid, $index ) ) {
                        $this->print( "HTML for $sourceid already present" );
                        $already++;
                    }
                } else {
                    $this->importOneRaw( $sourceid );
                    $processed++;
                }
            }
        }
        $this->print( "Already present: $already\n" .
                      "Added: $processed" );
    }

    public function checkMax(): bool
    {
        return ($this->maxDocuments && $this->actuallyIndexedDocuments >= $this->maxDocuments);
    }
    
    public function runIndexing(): void
    {
        $this->print("Starting indexing run...");
        
        // Reset Timer system to clear any accumulated timings from previous runs
        TimerFactory::getInstance()->reset();
        
        if ($this->updateProperties) {
            $this->updateProperties();
            return;
        }

        if ($this->updateMetadata) {
            $this->metadataExtractor->recalculateSentenceMetadata();
            return;
        }

        if ($this->updateSourceMetadata) {
            $this->updateSourceMetadata();
        }
        
        if ($this->importRaw) {
            $this->importRaw();
        }
        
        if ($this->updateProperties ||
            $this->updateMetadata ||
            $this->importRaw ||
            $this->updateSourceMetadata) {
            return;
        }
        
        $this->print( "runIndexing" );
        
        // Handle pcntl signals during execution
        if (function_exists('pcntl_signal_dispatch')) {
            declare(ticks = 1);
        }

        $dryRunLimit = $this->maxDocuments ?: 3;

        ////$this->fetchSources();
        ////$sourcesToProcess = $this->sources;
        $sourceid = ($this->sourceId) ? ($this->sourceId) : 0;

        $timerSourceIterator = TimerFactory::timer('source_iterator_setup');
        $iterator = $this->fetchSourceIterator( $sourceid );
        $timerSourceIterator->stop();

        if ($this->dryrun) {
            $this->print( "--- DRY RUN MODE: Processing up to {$dryRunLimit} sources ---" );
            ////$sourcesToProcess = array_slice($this->sources, 0, $dryRunLimit);
        } elseif ($this->maxDocuments) {
            $this->print( "--- LIMITED RUN: Will stop after indexing {$this->maxDocuments} new documents ---" );
            ////$sourcesToProcess = $this->sources;
        }

        $indexedTotal = 0;
        $global_i = 0;
        $checkpointCount = 0;
        $considered = 0;
        $this->totalDocuments = $iterator->getSize();
        $this->print( "{$this->totalDocuments} documents estimated." );

        while( $sources = $iterator->getNext() ) {
            $timerSourceFetch = TimerFactory::timer('source_batch_fetching');
            $this->print("Processing batch of " . count($sources) . " sources.");
            $timerSourceFetch->stop();
            //print_r( $source );
            // Check signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Check if we've reached document limit
            if ($this->checkMax() ) {
                $this->print( "Reached max documents ({$this->maxDocuments}), finishing." );
                break;
            }

            for ($i = 0; $i < count($sources); $i += $this->batchSize) {
                $chunk = array_slice($sources, $i, $this->batchSize);
                $actions = [];

                
                $this->print( sizeof($chunk) . " chunks" );
                
                if ($this->useSplitIndices) {
                    // Split indices approach - separate documents and sentences
                    $this->print( "Separate indices for documents and sentences" );
                    
                    $documentActions = [];
                    $sentenceActions = [];
                    
                    foreach ($chunk as $idx => $source) {
                        $global_i++;
                        $checkpointCount++;
                    
                        // Check limits again at document level
                        if ($this->maxDocuments && $considered >= $this->maxDocuments) {
                            $considered = 0;
                            break 2; // Break out of both loops
                        }
                        
                        $splitObjects = $this->createSplitIndexObjects($source, $global_i);
                        if ($splitObjects) {
                            $documentActions[] = $splitObjects['document'];
                            $sentenceActions = array_merge($sentenceActions, $splitObjects['sentences']);
                        }
                    }
                    
                    // Index documents and sentences separately
                    if (!empty($documentActions) && !empty($sentenceActions)) {
                        if ($this->dryrun) {
                            $this->print("--- DRY RUN: Would index " . count($documentActions) . " documents and " . count($sentenceActions) . " sentences ---");
                        } else {
                            // Split timing for documents vs sentences
                            $timerDocIndexing = TimerFactory::timer('document_indexing');
                            $this->bulkIndexDocuments($documentActions);
                            $timerDocIndexing->stop(); // Explicit stop for precise timing
                            
                            $timerSentenceIndexing = TimerFactory::timer('sentence_indexing');
                            $this->bulkIndexSentences($sentenceActions);
                            $timerSentenceIndexing->stop(); // Explicit stop for precise timing
                            
                            // Update counters
                            $indexedTotal += count($documentActions);
                        }
                    }
                } else {
                    // Traditional single index approach  
                    foreach ($chunk as $idx => $source) {
                        $global_i++;
                    
                        // Check limits again at document level
                        if ($this->checkMax()) {
                            $considered = 0;
                            break 2; // Break out of both loops
                        }
                        
                        $doc = $this->processSource($source, $global_i);
                        if ($doc) {
                            $actions[] = $doc;
                        }
                    }
                    if (!empty($actions)) {
                        if ($this->dryrun) {
                            $this->print( "--- DRY RUN: Found " . count($actions) . " documents to index in this batch ---" );
                            foreach ($actions as $action) {
                                $this->print( "  - Would index doc id: " . $action['_id'] . ", sourcename: " . $action['_source']['sourcename'] );
                            }
                        } else {
                            $this->print( "Indexing " . count($actions) . " documents into combined index with sentences" );
                            $this->client->bulkIndex($actions);
                            
                            // Verify each document was actually indexed
                            $timerVerification = TimerFactory::timer('document_verification');
                            $actuallyIndexed = 0;
                            foreach ($actions as $action) {
                                $docId = $action['_source']['doc_id'];
                                if ($this->verifyDocumentIndexed($docId)) {
                                    $actuallyIndexed++;
                                    $this->actuallyIndexedDocuments++;
                                    $this->print( "✅ Verified: {$docId}" );
                                } else {
                                    $this->print( "❌ Failed to verify: {$docId}" );
                                }
                                $considered++;
                            }
                            $timerVerification->stop();
                            
                            $this->print( "📦 Bulk indexed " . count($actions) .
                                          " documents, verified " . $actuallyIndexed .
                                          " actually indexed. " . $this->actuallyIndexedDocuments .
                                          " total added in this run." );
                            if ($actuallyIndexed < count($actions)) {
                                $this->print( "⚠️  WARNING: " . (count($actions) - $actuallyIndexed) .
                                              " documents failed to index!" );
                            }
                            
                            $indexedTotal += $actuallyIndexed;
                        }
                    }
                }
            }
            if ($checkpointCount >= $this->checkpointInterval) {
                $timerCheckpoint = TimerFactory::timer('checkpoint_operations');
                $this->checkpointSourceMetadata();
                $timerCheckpoint->stop();
                $checkpointCount = 0;
            }
            
            if( $this->done ) {
                break;
            }
            
            // Force garbage collection after each batch
            unset($actions);
            gc_collect_cycles();
        }

        if (!$this->dryrun) {
            $timerFinalCheckpoint = TimerFactory::timer('final_checkpoint');
            $this->checkpointSourceMetadata();
            $this->scanner->finish();
            $timerFinalCheckpoint->stop();
        }

        $this->print( "✅ Indexing complete. Total indexed: {$indexedTotal}" );
        $this->print( "📊 Cache hits: {$this->cacheHits}, Cache size: " . count($this->ratioCache) );
        
        // Print Timer system report
        $this->printTimerSystemReport();
        
        $this->print("Finished indexing run.");
    }

    /**
     * Print Timer system report for comparison with manual timing
     */
    public function printTimerSystemReport(): void
    {
        $this->print( "\n" . str_repeat("=", 60) );
        $this->print( "⏱️  TIMER SYSTEM BREAKDOWN (for comparison)" );
        $this->print( str_repeat("=", 60) );
        
        $factory = TimerFactory::getInstance();
        $timings = $factory->getTimings();
        $counts = $factory->getTimerCounts();
        
        if (empty($timings)) {
            $this->print( "No Timer system data available." );
            $this->print( str_repeat("=", 60) );
            return;
        }
        
        $totalTime = array_sum($timings);
        
        // Sort by time spent (descending)
        arsort($timings);
        
        foreach ($timings as $operation => $time) {
            if ($time > 0) {
                $percentage = ($time / $totalTime) * 100;
                $count = $counts[$operation] ?? 1;
                $countStr = $count > 1 ? " [{$count}x, avg " . number_format($time / $count, 3) . "s]" : "";
                $this->print( sprintf("%-25s: %8.3fs (%5.1f%%)%s\n", 
                    ucwords(str_replace('_', ' ', $operation)), 
                    $time, 
                    $percentage,
                    $countStr
                ) );
            }
        }
        
        $this->print( str_repeat("-", 60) );
        $this->print( sprintf("%-25s: %8.3fs (100.0%%)\n", "TOTAL", $totalTime) );
        $this->print( str_repeat("=", 60) );
    }

    /**
     * Create separate document and sentence objects for split indices
     * Asks ElasticsearchClient for the appropriate index names dynamically
     */
    private function createSplitIndexObjects(array $source, int $indexCounter): ?array
    {
        $this->print("createSplitIndexObjects: $indexCounter / {$source['sourcename']}");
        
        // Check document limits
        if ($this->checkMax()) {
            $this->print("🎯 Reached maximum indexed document limit ({$this->maxDocuments}), stopping.");
            $this->done = true;
            return null;
        }

        // Note: Timer system will measure individual components, not overlapping total
        
        $sourceid = (string)($source['sourceid'] ?? $source['doc_id']) ?? '';
        if( isset( $this->sourceMeta[$sourceid] ) && !$this->sourceId && !$this->groupName ) {
            $discarded = $this->sourceMeta[$sourceid]['discarded'] ?? false;
            if( $discarded ) {
                $this->print( "Skipping discarded {$sourceid}" );
            } else {
                $this->print( "Skipping already indexed {$sourceid}" );
            }
            if (!$this->dryrun) {
                $this->print("Skipping already indexed or discarded {$sourceid}");
                return null;
            }
        }

        // Check if document already exists in documents index (ask client for index name)
        $documentsIndexName = $this->client->getDocumentsIndexName();
        $doc = $this->client->getDocumentOutline($sourceid, $documentsIndexName);
        if ($doc && sizeof($doc)) {
            $this->print("Skipping already indexed {$sourceid} {$source['sourcename']} in documents index");
            return null;
        }

        // Process the source to get text and sentences (reuse existing logic)
        // Note: processSource() has its own internal timers, no need for broad timer here
        $docData = $this->processSource($source, $indexCounter);
        if (!$docData) {
            return null;
        }
        $this->actuallyIndexedDocuments++;

        // NOW start timing the actual split index creation work
        $timerSplitCreation = TimerFactory::timer('split_index_creation');

        // Extract sentence objects and document data
        $sentenceObjects = $docData['_source']['sentences'] ?? [];
        
        // Create document object (without nested sentences) - ask client for documents index name
        $documentObj = [
            "_index" => $documentsIndexName,
            "_id" => $sourceid,
            "_source" => [
                "doc_id" => $sourceid,
                "groupname" => $docData['_source']['groupname'],
                "sourcename" => $docData['_source']['sourcename'],
                "text" => $docData['_source']['text'],
                "text_chunks" => $docData['_source']['text_chunks'],
                "text_vector" => $docData['_source']['text_vector'],
                "date" => $docData['_source']['date'],
                "authors" => $docData['_source']['authors'],
                "link" => $docData['_source']['link'],
                "hawaiian_word_ratio" => $docData['_source']['hawaiian_word_ratio'],
                "sentence_count" => count($sentenceObjects)
            ]
        ];

        // Create individual sentence objects for sentences index - ask client for sentences index name
        $sentencesIndexName = $this->client->getSentencesIndexName();
        $sentenceIndexObjects = [];
        foreach ($sentenceObjects as $idx => $sentence) {
            $sentenceIndexObjects[] = [
                "_index" => $sentencesIndexName,
                "_id" => $sourceid . "_" . $idx,
                "_source" => [
                    "doc_id" => $sourceid,
                    "text" => $sentence['text'],
                    "vector" => $sentence['vector'],
                    "position" => $sentence['position'],
                    "chunk_id" => $idx,
                    // Add quality metadata
                    "quality_score" => $sentence['hawaiian_word_ratio'] ?? 0,
                    "hawaiian_word_ratio" => $sentence['hawaiian_word_ratio'] ?? null,
                    "word_count" => $sentence['word_count'] ?? null,
                    "entity_count" => $sentence['entity_count'] ?? null,
                    "boilerplate_score" => $sentence['boilerplate_score'] ?? null,
                    "length" => $sentence['length'] ?? null,
                    "frequency" => $sentence['frequency'] ?? null,
                    // Include document metadata for search
                    "sourcename" => $docData['_source']['sourcename'],
                    "sourceid" => $sourceid,
                    "authors" => $docData['_source']['authors'],
                    "date" => $docData['_source']['date'],
                    "groupname" => $docData['_source']['groupname'],
                    "title" => $docData['_source']['sourcename'] // Use sourcename as title
                ]
            ];
        }

        // Record time for split index object creation (separate from document processing)
        $timerSplitCreation->stop(); // Explicit stop for precise timing
        
        return [
            'document' => $documentObj,
            'sentences' => $sentenceIndexObjects
        ];
    }

    /**
     * Bulk index documents to documents index
     */
    private function bulkIndexDocuments(array $documentActions): void
    {
        $this->print( "bulkIndexDocuments(" . count($documentActions) . ")" );
        if (empty($documentActions)) {
            return;
        }
        
        $this->print("Bulk indexing " . count($documentActions) . " documents to documents index");
        $this->client->bulkIndex($documentActions);
    }

    /**
     * Bulk index sentences to sentences index  
     */
    private function bulkIndexSentences(array $sentenceActions): void
    {
        if (empty($sentenceActions)) {
            return;
        }
        
        $this->print("Bulk indexing " . count($sentenceActions) . " sentences to sentences index");
        $this->client->bulkIndex($sentenceActions);
    }
}
