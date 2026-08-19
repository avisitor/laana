<?php

namespace HawaiianSearch;

use Exception;

/**
 * Validates that Elasticsearch indices match the schema expected by CorpusIndexer.
 *
 * Used as a mandatory pre-flight check before any indexing operation to catch
 * schema mismatches early rather than failing mid-indexing.
 */
class IndexSchemaValidator {
    private ElasticsearchClient $client;
    private array $errors = [];
    private array $warnings = [];
    private bool $verbose;

    /**
     * Expected fields per index, derived from the *_mapping.json config files.
     * Keys are the mapping JSON filenames under ../config/.
     */
    private const EXPECTED_MAPPINGS = [
        'documents' => [
            'mapping_file' => 'documents_mapping.json',
            'required_fields' => [
                'doc_id'            => 'keyword',
                'sourceid'          => 'keyword',
                'groupname'         => 'keyword',
                'sourcename'        => 'keyword',
                'title'             => 'text',
                'date'              => 'date',
                'authors'           => 'keyword',
                'link'              => 'keyword',
                'text'              => 'text',
                'text_chunks'       => 'nested',
                'text_vector_1024'  => 'dense_vector',
                'hawaiian_word_ratio' => 'float',
                'sentence_count'    => 'integer',
                'word_count'        => 'integer',
                'entity_count'      => 'integer',
                'boilerplate_score' => 'float',
            ],
        ],
        'sentences' => [
            'mapping_file' => 'sentences_mapping.json',
            'required_fields' => [
                'doc_id'             => 'keyword',
                'text'               => 'text',
                'vector'             => 'dense_vector',
                'position'           => 'integer',
                'sentence_hash'      => 'keyword',
                'hawaiian_word_ratio' => 'float',
                'word_count'         => 'integer',
                'entity_count'       => 'integer',
                'length'             => 'integer',
                'boilerplate_score'  => 'float',
                'sourcename'         => 'keyword',
                'sourceid'           => 'keyword',
                'authors'            => 'keyword',
                'date'               => 'date',
                'groupname'          => 'keyword',
            ],
        ],
        'source-metadata' => [
            'mapping_file' => 'source_metadata_mapping.json',
            'required_fields' => [
                'sourceid'   => 'keyword',
                'sourcename' => 'keyword',
                'groupname'  => 'keyword',
                'authors'    => 'keyword',
                'date'       => 'date',
                'link'       => 'keyword',
                'title'      => 'text',
                'discarded'  => 'boolean',
                'empty'      => 'boolean',
                'quality'    => 'float',
            ],
        ],
    ];

    public function __construct(ElasticsearchClient $client, bool $verbose = false) {
        $this->client = $client;
        $this->verbose = $verbose;
    }

    /**
     * Run the full pre-flight validation.
     *
     * @return bool True if all indices pass validation (or don't exist and recreate is set)
     */
    public function validate(bool $recreate = false): bool {
        $this->errors = [];
        $this->warnings = [];

        echo "========================================\n";
        echo " Pre-flight Index Schema Validation\n";
        echo "========================================\n\n";

        $documentsIndex   = $this->client->getDocumentsIndexName();
        $sentencesIndex   = $this->client->getSentencesIndexName();
        $sourceMetaIndex  = $this->client->getSourceMetadataName();

        echo "Documents index:     {$documentsIndex}\n";
        echo "Sentences index:     {$sentencesIndex}\n";
        echo "Source metadata:     {$sourceMetaIndex}\n\n";

        // --- Documents index ---
        $this->validateIndex(
            $documentsIndex,
            'documents',
            $recreate
        );

        // --- Sentences index ---
        $this->validateIndex(
            $sentencesIndex,
            'sentences',
            $recreate
        );

        // --- Source metadata index ---
        $this->validateIndex(
            $sourceMetaIndex,
            'source-metadata',
            $recreate
        );

        // --- Summary ---
        echo "\n" . str_repeat("-", 50) . "\n";

        if (!empty($this->warnings)) {
            echo "⚠️  Warnings (" . count($this->warnings) . "):\n";
            foreach ($this->warnings as $w) {
                echo "   - {$w}\n";
            }
            echo "\n";
        }

        if (empty($this->errors)) {
            echo "✅ Pre-flight check PASSED — all indices are compatible.\n\n";
            return true;
        }

        echo "❌ Pre-flight check FAILED — " . count($this->errors) . " error(s):\n";
        foreach ($this->errors as $e) {
            echo "   - {$e}\n";
        }
        echo "\n";

        if ($recreate) {
            echo "ℹ️  --recreate is set: missing/incompatible indices will be recreated.\n";
            // Filter out errors that are expected with --recreate:
            // - "does not exist" — index will be created
            // - "missing fields" — index will be recreated with correct mapping
            $fatalErrors = array_filter($this->errors, function ($e) {
                return strpos($e, 'does not exist') === false
                    && strpos($e, 'missing fields') === false;
            });
            if (empty($fatalErrors)) {
                echo "✅ No fatal errors — proceeding with recreation.\n\n";
                return true;
            }
            echo "❌ Fatal errors remain even with --recreate:\n";
            foreach ($fatalErrors as $e) {
                echo "   - {$e}\n";
            }
            echo "\n";
        }

        return false;
    }

    /**
     * Validate a single index: check existence, mapping fields, and sample data.
     */
    private function validateIndex(string $indexName, string $indexType, bool $recreate): void {
        $label = ucfirst($indexType);
        echo "--- {$label} Index: {$indexName} ---\n";

        $exists = $this->client->indexExists($indexName);

        if (!$exists) {
            if ($recreate) {
                echo "   ℹ️  Index does not exist — will be created by --recreate.\n\n";
                $this->warnings[] = "{$label} index '{$indexName}' does not exist (will be created)";
            } else {
                echo "   ❌ Index does not exist!\n\n";
                $this->errors[] = "{$label} index '{$indexName}' does not exist. Use --recreate to create it.";
            }
            return;
        }

        echo "   ✅ Index exists\n";

        // Get the mapping from ES
        $mapping = $this->getMapping($indexName);
        if ($mapping === null) {
            $this->errors[] = "Could not retrieve mapping for '{$indexName}'";
            echo "\n";
            return;
        }

        $properties = $mapping['mappings']['properties'] ?? [];

        // Load expected fields from our mapping JSON
        $expectedInfo = self::EXPECTED_MAPPINGS[$indexType] ?? null;
        if ($expectedInfo === null) {
            echo "   ⚠️  No expected field definitions for type '{$indexType}' — skipping field check.\n\n";
            return;
        }

        $requiredFields = $expectedInfo['required_fields'];
        $missingFields = [];
        $wrongTypeFields = [];

        foreach ($requiredFields as $fieldName => $expectedType) {
            if (!isset($properties[$fieldName])) {
                $missingFields[] = $fieldName;
                continue;
            }

            $actualType = $properties[$fieldName]['type'] ?? null;
            // dense_vector doesn't have a 'type' key in all versions; check for 'dims'
            if ($expectedType === 'dense_vector') {
                if (!isset($properties[$fieldName]['dims']) && $actualType !== 'dense_vector') {
                    $wrongTypeFields[$fieldName] = [
                        'expected' => $expectedType,
                        'actual'   => $actualType ?? 'unknown',
                    ];
                }
            } elseif ($actualType !== $expectedType) {
                // Allow keyword sub-fields (text type has .keyword)
                $wrongTypeFields[$fieldName] = [
                    'expected' => $expectedType,
                    'actual'   => $actualType ?? 'unknown',
                ];
            }
        }

        // Report results
        $fieldCount = count($properties);
        $requiredCount = count($requiredFields);
        echo "   📊 Mapping has {$fieldCount} fields, {$requiredCount} required\n";

        if (empty($missingFields) && empty($wrongTypeFields)) {
            echo "   ✅ All required fields present with correct types\n";
        } else {
            if (!empty($missingFields)) {
                echo "   ❌ Missing fields: " . implode(', ', $missingFields) . "\n";
                $this->errors[] = "{$label} index '{$indexName}' missing fields: " . implode(', ', $missingFields);
            }
            if (!empty($wrongTypeFields)) {
                foreach ($wrongTypeFields as $field => $types) {
                    echo "   ⚠️  Field '{$field}': expected {$types['expected']}, got {$types['actual']}\n";
                    $this->warnings[] = "{$label} index '{$indexName}' field '{$field}' type mismatch: expected {$types['expected']}, got {$types['actual']}";
                }
            }
        }

        // Check document count and sample a record
        $this->checkSampleData($indexName, $label, $indexType);

        echo "\n";
    }

    /**
     * Retrieve the mapping for an index via the ES GET _mapping API.
     */
    private function getMapping(string $indexName): ?array {
        try {
            $response = $this->client->getRawClient()->indices()->getMapping([
                'index' => $indexName,
            ]);

            $arr = is_array($response) ? $response : $response->asArray();
            return $arr[$indexName] ?? reset($arr) ?: null;
        } catch (Exception $e) {
            if ($this->verbose) {
                echo "   ⚠️  Could not retrieve mapping: {$e->getMessage()}\n";
            }
            return null;
        }
    }

    /**
     * Check a sample document to verify data structure matches expectations.
     */
    private function checkSampleData(string $indexName, string $label, string $indexType): void {
        try {
            $response = $this->client->getRawClient()->search([
                'index' => $indexName,
                'size'  => 1,
                'body'  => ['query' => ['match_all' => (object)[]]],
            ]);

            $arr = is_array($response) ? $response : $response->asArray();
            $total = $arr['hits']['total']['value'] ?? 0;
            echo "   📊 Documents: {$total}\n";

            if ($total === 0) {
                echo "   ℹ️  Index is empty — no sample data to check.\n";
                return;
            }

            $source = $arr['hits']['hits'][0]['_source'] ?? [];

            if ($indexType === 'documents') {
                $this->checkDocumentSample($source, $label);
            } elseif ($indexType === 'sentences') {
                $this->checkSentenceSample($source, $label);
            }

        } catch (Exception $e) {
            if ($this->verbose) {
                echo "   ⚠️  Could not sample data: {$e->getMessage()}\n";
            }
        }
    }

    private function checkDocumentSample(array $source, string $label): void {
        // Check that text_vector_1024 is present and has correct dimensions
        if (isset($source['text_vector_1024']) && is_array($source['text_vector_1024'])) {
            $dims = count($source['text_vector_1024']);
            if ($dims !== 1024) {
                echo "   ⚠️  text_vector_1024 has {$dims} dimensions (expected 1024)\n";
                $this->warnings[] = "{$label} sample doc has text_vector_1024 with {$dims} dims (expected 1024)";
            } else {
                echo "   ✅ text_vector_1024: 1024 dimensions ✓\n";
            }
        } else {
            echo "   ⚠️  text_vector_1024 missing or empty in sample document\n";
            $this->warnings[] = "{$label} sample doc missing text_vector_1024";
        }

        // Check sourceid field
        if (empty($source['sourceid'])) {
            echo "   ⚠️  sourceid field missing or empty in sample document\n";
            $this->warnings[] = "{$label} sample doc missing sourceid";
        }

        // Check sentence_count
        if (!isset($source['sentence_count'])) {
            echo "   ⚠️  sentence_count field missing in sample document\n";
            $this->warnings[] = "{$label} sample doc missing sentence_count";
        }
    }

    private function checkSentenceSample(array $source, string $label): void {
        // Check vector dimensions (should be 384 for e5-small)
        if (isset($source['vector']) && is_array($source['vector'])) {
            $dims = count($source['vector']);
            if ($dims !== 384) {
                echo "   ⚠️  vector has {$dims} dimensions (expected 384)\n";
                $this->warnings[] = "{$label} sample doc has vector with {$dims} dims (expected 384)";
            } else {
                echo "   ✅ vector: 384 dimensions ✓\n";
            }
        } else {
            echo "   ⚠️  vector missing or empty in sample sentence\n";
            $this->warnings[] = "{$label} sample doc missing vector";
        }

        // Check sourceid is present (critical for linking back to documents)
        if (empty($source['sourceid'])) {
            echo "   ⚠️  sourceid field missing in sample sentence\n";
            $this->warnings[] = "{$label} sample doc missing sourceid";
        }
    }

    public function getErrors(): array {
        return $this->errors;
    }

    public function getWarnings(): array {
        return $this->warnings;
    }
}
