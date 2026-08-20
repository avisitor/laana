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
                'text'               => 'text',
                'text_chunks'       => 'nested',
                'text_vector_1024'  => 'vector',
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
                'vector'             => 'vector',
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

    /**
     * Expected embedding dimensions per vector field, keyed by field name.
     * These are model-driven (not provider-specific) and identical across backends.
     */
    private const VECTOR_DIMS = [
        'text_vector_1024' => 1024,
        'vector'           => 384,
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
        $sourceExcludes = $mapping['mappings']['_source']['excludes'] ?? [];

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

            // Vector fields: the concrete mapping type is provider-specific
            // (Elasticsearch => dense_vector, OpenSearch => knn_vector). Ask the
            // client for the type it uses rather than assuming one backend.
            $resolvedExpected = ($expectedType === 'vector')
                ? $this->client->getVectorFieldType()
                : $expectedType;

            $actualType = $properties[$fieldName]['type'] ?? null;
            if ($actualType !== $resolvedExpected) {
                $wrongTypeFields[$fieldName] = [
                    'expected' => $resolvedExpected,
                    'actual'   => $actualType ?? 'unknown',
                ];
                continue;
            }

            // For vector fields, verify the declared dimension matches the
            // expected model dimension. Both backends expose the dimension under
            // a provider-specific key (dense_vector => 'dims', knn_vector => 'dimension').
            if ($expectedType === 'vector' && isset(self::VECTOR_DIMS[$fieldName])) {
                $expectedDim = self::VECTOR_DIMS[$fieldName];
                $actualDim = $properties[$fieldName]['dims']
                    ?? $properties[$fieldName]['dimension']
                    ?? null;
                if ($actualDim !== null && $actualDim != $expectedDim) {
                    $wrongTypeFields[$fieldName] = [
                        'expected' => "{$resolvedExpected} dims={$expectedDim}",
                        'actual'   => "{$actualType} dims={$actualDim}",
                    ];
                }
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
        $this->checkSampleData($indexName, $label, $indexType, $properties, $sourceExcludes);

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
     *
     * @param array $properties    The index mapping properties (for vector dims checks)
     * @param array $sourceExcludes Fields excluded from _source (vectors are typically
     *                              excluded, so they cannot be read back from _source)
     */
    private function checkSampleData(string $indexName, string $label, string $indexType, array $properties, array $sourceExcludes): void {
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
                $this->checkDocumentSample($source, $label, $properties, $sourceExcludes);
            } elseif ($indexType === 'sentences') {
                $this->checkSentenceSample($source, $label, $properties, $sourceExcludes);
            }

        } catch (Exception $e) {
            if ($this->verbose) {
                echo "   ⚠️  Could not sample data: {$e->getMessage()}\n";
            }
        }
    }

    /**
     * Validate a vector field. Embedding fields are generally not returned from
     * _source on either backend (OpenSearch excludes them explicitly; Elasticsearch
     * dense_vector/bbq_hnsw does not store them in _source). So we read from _source
     * when available, but fall back to verifying the declared dimension in the
     * mapping rather than warning — a missing _source vector is expected, not an error.
     */
    private function checkVector(string $field, array $source, string $label, array $properties, array $sourceExcludes, int $expectedDim): void {
        $actualDim = $properties[$field]['dims'] ?? $properties[$field]['dimension'] ?? null;

        if (in_array($field, $sourceExcludes, true)) {
            // Explicitly excluded from _source — validate via the mapping.
            $this->validateVectorDim($field, $label, $actualDim, $expectedDim, 'excluded from _source');
            return;
        }

        // Not excluded: prefer reading from _source.
        if (isset($source[$field]) && is_array($source[$field])) {
            $dims = count($source[$field]);
            if ($dims !== $expectedDim) {
                echo "   ⚠️  {$field} has {$dims} dimensions (expected {$expectedDim})\n";
                $this->warnings[] = "{$label} sample doc has {$field} with {$dims} dims (expected {$expectedDim})";
            } else {
                echo "   ✅ {$field}: {$expectedDim} dimensions ✓\n";
            }
            return;
        }

        // Absent from _source without an explicit exclude — the backend simply
        // doesn't return the vector in _source. Fall back to the mapping.
        $this->validateVectorDim($field, $label, $actualDim, $expectedDim, 'not returned in _source');
    }

    private function validateVectorDim(string $field, string $label, $actualDim, int $expectedDim, string $reason): void {
        if ($actualDim === null) {
            echo "   ⚠️  {$field} {$reason} and no dimension declared in mapping\n";
            $this->warnings[] = "{$label} {$field} {$reason} with no declared dimension";
        } elseif ($actualDim != $expectedDim) {
            echo "   ⚠️  {$field} declared dimension {$actualDim} (expected {$expectedDim})\n";
            $this->warnings[] = "{$label} {$field} has {$actualDim} dims (expected {$expectedDim})";
        } else {
            echo "   ✅ {$field}: {$expectedDim} dimensions (verified via mapping; {$reason})\n";
        }
    }

    private function checkDocumentSample(array $source, string $label, array $properties, array $sourceExcludes): void {
        $this->checkVector('text_vector_1024', $source, $label, $properties, $sourceExcludes, 1024);

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

    private function checkSentenceSample(array $source, string $label, array $properties, array $sourceExcludes): void {
        $this->checkVector('vector', $source, $label, $properties, $sourceExcludes, 384);

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
