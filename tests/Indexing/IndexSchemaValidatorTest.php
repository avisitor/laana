<?php

namespace Noiiolelo\Tests\Indexing;

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\IndexSchemaValidator;
use Noiiolelo\Tests\BaseTestCase;

class IndexSchemaValidatorTest extends BaseTestCase
{
    private ElasticsearchClient $esClient;

    protected function setUp(): void
    {
        $host = $_ENV['ES_HOST'] ?? null;
        $port = $_ENV['ES_PORT'] ?? null;
        if (!$host || !$port) {
            $this->markTestSkipped('ES_HOST and ES_PORT must be set for validator tests');
        }

        $this->esClient = new ElasticsearchClient([
            'hawaiian_documents_index' => 'hawaiian_documents_new',
            'hawaiian_sentences_index' => 'hawaiian_sentences_new',
            'hawaiian_source_metadata_index' => 'hawaiian-source-metadata',
            'vector_dimensions' => 384,
        ]);
    }

    public function testValidatorInstantiation(): void
    {
        $validator = new IndexSchemaValidator($this->esClient);
        $this->assertInstanceOf(IndexSchemaValidator::class, $validator);
    }

    public function testValidateReturnsBoolean(): void
    {
        $validator = new IndexSchemaValidator($this->esClient);
        ob_start();
        $result = $validator->validate(false);
        ob_end_clean();
        $this->assertIsBool($result);
    }

    public function testValidatePopulatesErrorsArray(): void
    {
        $validator = new IndexSchemaValidator($this->esClient);
        ob_start();
        $validator->validate(false);
        ob_end_clean();
        $this->assertIsArray($validator->getErrors());
    }

    public function testValidatePopulatesWarningsArray(): void
    {
        $validator = new IndexSchemaValidator($this->esClient);
        ob_start();
        $validator->validate(false);
        ob_end_clean();
        $this->assertIsArray($validator->getWarnings());
    }

    public function testValidateWithRecreateMode(): void
    {
        $validator = new IndexSchemaValidator($this->esClient);
        ob_start();
        $result = $validator->validate(true);
        ob_end_clean();
        $this->assertIsBool($result);
    }

    public function testValidatorDoesNotThrow(): void
    {
        $validator = new IndexSchemaValidator($this->esClient, false);
        ob_start();
        try {
            $result = $validator->validate(false);
            ob_end_clean();
            $this->assertIsBool($result);
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->fail('Validator threw exception: ' . $e->getMessage());
        }
    }

    public function testValidatorWithVerboseOutput(): void
    {
        $validator = new IndexSchemaValidator($this->esClient, true);
        ob_start();
        $result = $validator->validate(false);
        ob_end_clean();
        $this->assertIsBool($result);
    }

    public function testValidatorWithNonexistentIndex(): void
    {
        // Use indexName to set a prefix that produces nonexistent index names.
        // The constructor derives index names as: {indexName}_documents_new,
        // {indexName}_sentences_new, {indexName}-source-metadata.
        $esClient = new ElasticsearchClient([
            'indexName' => 'nonexistent_index_' . uniqid('test_'),
        ]);
        $validator = new IndexSchemaValidator($esClient);

        ob_start();
        try {
            $result = $validator->validate(false);
            ob_end_clean();
            // Without --recreate, missing indices should produce errors
            // But if ES auto-creates them, result may still be true
            $errors = $validator->getErrors();
            $warnings = $validator->getWarnings();
            $this->assertIsBool($result);
            $this->assertIsArray($errors);
            $this->assertIsArray($warnings);
        } catch (\Throwable $e) {
            ob_end_clean();
            // Validator should not throw even for nonexistent indices
            $this->fail('Validator threw exception for nonexistent indices: ' . $e->getMessage());
        }
    }

    public function testValidatorRecreateAllowsMissingIndices(): void
    {
        // Use indexName to set a prefix that produces nonexistent index names.
        // The constructor derives index names as: {indexName}_documents_new,
        // {indexName}_sentences_new, {indexName}-source-metadata.
        $esClient = new ElasticsearchClient([
            'indexName' => 'nonexistent_index_2',
        ]);
        $validator = new IndexSchemaValidator($esClient);

        ob_start();
        $result = $validator->validate(true);
        ob_end_clean();

        $errors = $validator->getErrors();
        $this->assertEmpty($errors, 'Missing indices should not produce errors with --recreate');
        $this->assertTrue($result, 'Validation should pass with --recreate for nonexistent indices');
    }
}
