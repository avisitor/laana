<?php

namespace HawaiianSearch;

class MetadataCache {
    private ElasticsearchClient $esClient;
    private int $maxSize;
    private int $flushInterval; // in seconds
    private array $cache = [];
    private float $lastFlushTime;
    private bool $dryrun;

    public function __construct(ElasticsearchClient $esClient, array $options = []) {
        $this->esClient = $esClient;
        $this->maxSize = isset($options['maxSize']) ? $options['maxSize'] : 1000;
        $this->flushInterval = isset($options['flushInterval']) ? $options['flushInterval'] : 60;
        $this->dryrun = isset($options['dryrun']) ? $options['dryrun'] : false;
        $this->lastFlushTime = microtime(true);
    }

    public function set(string $hash, array $metadata): void {
        $this->cache[$hash] = $metadata;
        if (!$this->dryrun && (count($this->cache) >= $this->maxSize || (microtime(true) - $this->lastFlushTime) >= $this->flushInterval)) {
            $this->flush();
        }
    }

    public function get(string $hash): ?array {
        // For simplicity in this context, we don't read from the index here.
        // The cache is write-through. The main CorpusScanner will pre-warm it if needed.
        return $this->cache[$hash] ?? null;
    }

    public function flush(): void {
        if (empty($this->cache)) {
            return;
        }

        echo "Flushing metadata cache (" . count($this->cache) . " items)..." . PHP_EOL;
        try {
            $this->esClient->updateMetadata($this->cache);
            $this->cache = []; // Clear the cache after successful flush
            $this->lastFlushTime = microtime(true);
        } catch (\Exception $e) {
            echo "⚠️ Failed to flush metadata cache: " . $e->getMessage() . PHP_EOL;
        }
    }

    // Call this at the end of the script to ensure any remaining items are saved.
    public function finish(): void {
        $this->flush();
    }
}