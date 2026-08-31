<?php

namespace Noiiolelo\Tests\Indexing;

use HawaiianSearch\CorpusIndexer;
use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\PostgresSourceIterator;
use HawaiianSearch\SourceCapabilities;
use HawaiianSearch\SourceIterator;
use Noiiolelo\Tests\BaseTestCase;
use ReflectionClass;

class StubElasticsearchClient extends ElasticsearchClient
{
    public function __construct()
    {
    }

    public function getDocumentsIndexName($indexName = ''): string
    {
        return 'noiiolelo-docs';
    }
}

class AssembleDocSourceTest extends BaseTestCase
{
    private function createIndexerStub(): CorpusIndexer
    {
        $ref = new ReflectionClass(CorpusIndexer::class);
        $indexer = $ref->newInstanceWithoutConstructor();

        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($indexer, ['quiet' => false]);

        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($indexer, new StubElasticsearchClient());

        $processedProp = $ref->getProperty('processedDocuments');
        $processedProp->setAccessible(true);
        $processedProp->setValue($indexer, 0);

        return $indexer;
    }

    private function make1024Vector(float $fill = 0.1): array
    {
        return array_fill(0, 1024, $fill);
    }

    private function make384Vector(float $fill = 0.2): array
    {
        return array_fill(0, 384, $fill);
    }

    private function makeSentenceObject(string $text = 'aloha kākou', int $position = 0, string $docId = '1'): array
    {
        return [
            'text' => $text,
            'vector' => $this->make384Vector(),
            'position' => $position,
            'doc_id' => $docId,
            'hawaiian_word_ratio' => 0.8,
            'word_count' => 2,
            'entity_count' => 0,
            'boilerplate_score' => 0.0,
            'length' => strlen($text),
            'frequency' => 1,
        ];
    }

    // ---------------------------------------------------------------
    // assembleDocSource tests
    // ---------------------------------------------------------------

    public function testAssembleDocSourceValidReturnsDoc(): void
    {
        $indexer = $this->createIndexerStub();

        $source = [
            'sourceid' => '42',
            'sourcename' => 'Ka Nupepa',
            'groupname' => 'newspapers',
            'authors' => 'Editor',
            'date' => '2024-01-15',
            'link' => 'https://example.com/42',
        ];
        $originalText = 'Aloha kākou. Mahalo nui loa.';
        $chunks = [
            [
                'chunk_index' => 0,
                'chunk_text' => $originalText,
                'chunk_start' => 0,
                'chunk_length' => strlen($originalText),
            ],
        ];
        $textVector1024 = $this->make1024Vector();
        $sentenceObjects = [
            $this->makeSentenceObject('Aloha kākou.', 0, '42'),
            $this->makeSentenceObject('Mahalo nui loa.', 1, '42'),
        ];

        $result = $indexer->assembleDocSource(
            $source,
            $originalText,
            $chunks,
            $textVector1024,
            $sentenceObjects,
            0.95
        );

        $this->assertIsArray($result);
        $this->assertSame('noiiolelo-docs', $result['_index']);
        $this->assertSame('42', $result['_id']);

        $src = $result['_source'];
        $this->assertSame('42', $src['doc_id']);
        $this->assertSame('newspapers', $src['groupname']);
        $this->assertSame('Ka Nupepa', $src['sourcename']);
        $this->assertSame($originalText, $src['text']);
        $this->assertCount(1, $src['text_chunks']);
        $this->assertCount(1024, $src['text_vector_1024']);
        $this->assertCount(2, $src['sentences']);
        $this->assertSame('2024-01-15', $src['date']);
        $this->assertSame('Editor', $src['authors']);
        $this->assertSame('https://example.com/42', $src['link']);
        $this->assertSame(0.95, $src['hawaiian_word_ratio']);
        // chunk_index 0
        $this->assertSame(0, $src['text_chunks'][0]['chunk_index']);
    }

    public function testAssembleDocSourceWrongDim1024ReturnsNull(): void
    {
        $indexer = $this->createIndexerStub();

        $source = ['sourceid' => '1', 'sourcename' => 'Test', 'groupname' => 'g', 'authors' => '', 'date' => '', 'link' => ''];
        $text = 'Aloha';
        $chunks = [['chunk_index' => 0, 'chunk_text' => $text, 'chunk_start' => 0, 'chunk_length' => strlen($text)]];
        // 384-dim instead of 1024
        $badVector = $this->make384Vector();
        $sentences = [$this->makeSentenceObject('Aloha', 0, '1')];

        $result = $indexer->assembleDocSource($source, $text, $chunks, $badVector, $sentences, 0.5);
        $this->assertNull($result);
    }

    public function testAssembleDocSourceSentenceWith1024DimReturnsNull(): void
    {
        $indexer = $this->createIndexerStub();

        $source = ['sourceid' => '2', 'sourcename' => 'Test', 'groupname' => 'g', 'authors' => '', 'date' => '', 'link' => ''];
        $text = 'Aloha kākou';
        $chunks = [['chunk_index' => 0, 'chunk_text' => $text, 'chunk_start' => 0, 'chunk_length' => strlen($text)]];
        $vector1024 = $this->make1024Vector();
        // Sentence with 1024-dim vector instead of 384
        $badSentence = [
            'text' => 'Aloha kākou',
            'vector' => $this->make1024Vector(),
            'position' => 0,
            'doc_id' => '2',
        ];

        $result = $indexer->assembleDocSource($source, $text, $chunks, $vector1024, [$badSentence], 0.5);
        $this->assertNull($result);
    }

    public function testAssembleDocSourceMissingSentenceVectorReturnsNull(): void
    {
        $indexer = $this->createIndexerStub();

        $source = ['sourceid' => '3', 'sourcename' => 'Test', 'groupname' => 'g', 'authors' => '', 'date' => '', 'link' => ''];
        $text = 'Aloha';
        $chunks = [['chunk_index' => 0, 'chunk_text' => $text, 'chunk_start' => 0, 'chunk_length' => strlen($text)]];
        $vector1024 = $this->make1024Vector();
        // Sentence with null vector
        $badSentence = [
            'text' => 'Aloha',
            'vector' => null,
            'position' => 0,
            'doc_id' => '3',
        ];

        $result = $indexer->assembleDocSource($source, $text, $chunks, $vector1024, [$badSentence], 0.5);
        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // buildTextChunks tests
    // ---------------------------------------------------------------

    public function testBuildTextChunksShortTextReturnsOneChunk(): void
    {
        $indexer = $this->createIndexerStub();
        $text = 'Aloha kākou, this is a short text.';

        $chunks = $indexer->buildTextChunks($text);

        $this->assertCount(1, $chunks);
        $this->assertSame(0, $chunks[0]['chunk_index']);
        $this->assertSame(0, $chunks[0]['chunk_start']);
        $this->assertSame(strlen($text), $chunks[0]['chunk_length']);
        $this->assertSame($text, $chunks[0]['chunk_text']);
    }

    public function testBuildTextChunksLongTextReturnsMultipleChunks(): void
    {
        $indexer = $this->createIndexerStub();
        // Create text > 30000 chars
        $text = str_repeat('Aloha kākou. ', 3000); // ~39000 chars

        $chunks = $indexer->buildTextChunks($text);

        $this->assertGreaterThan(1, count($chunks));
        // Each chunk ≤ 30000
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(30000, $chunk['chunk_length']);
        }
        // Overlap: chunk N+1 start < chunk N end (for non-final chunks)
        for ($i = 0; $i < count($chunks) - 1; $i++) {
            $chunkEnd = $chunks[$i]['chunk_start'] + $chunks[$i]['chunk_length'];
            $this->assertLessThan(
                $chunkEnd,
                $chunks[$i + 1]['chunk_start'],
                "Chunk {$i}+1 should start before chunk {$i} ends (overlap)"
            );
        }
    }

    public function testBuildTextChunksMaxTwentyChunks(): void
    {
        $indexer = $this->createIndexerStub();
        // Create extremely long text that would produce > 20 chunks
        $text = str_repeat('Aloha kākou. ', 80000); // ~1M chars

        $chunks = $indexer->buildTextChunks($text);

        $this->assertLessThanOrEqual(20, count($chunks));
    }

    // ---------------------------------------------------------------
    // Capability tests
    // ---------------------------------------------------------------

    public function testPostgresSourceIteratorCapabilities(): void
    {
        // PostgresSourceIterator ctor requires a client with ->conn
        // We provide a mock that has a conn prop (never touched by getCapabilities)
        $mockPdo = $this->createMock(\PDO::class);
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn([]);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $client = new \stdClass();
        $client->conn = $mockPdo;

        $iterator = new PostgresSourceIterator(null, null, $client);
        $caps = $iterator->getCapabilities();

        $this->assertTrue($caps->sentenceVectors);
        // The legacy 384-dim document vector (contents.embedding) is no longer
        // populated; the authoritative document vector is 1024-dim.
        $this->assertFalse($caps->documentVector384);
        $this->assertTrue($caps->documentVector1024);
        $this->assertTrue($caps->rawHtml);
        $this->assertTrue($caps->hasAnyVector());
    }

    public function testSourceIteratorCapabilities(): void
    {
        // SourceIterator ctor does HTTP I/O — guard with env check
        $apiUrl = $_ENV['NOIIOLELO_API_BASE_URL'] ?? getenv('NOIIOLELO_API_BASE_URL') ?? '';
        if (!$apiUrl) {
            $this->markTestSkipped('NOIIOLELO_API_BASE_URL not set; SourceIterator ctor requires HTTP');
        }

        try {
            $iterator = new SourceIterator();
        } catch (\Throwable $e) {
            $this->markTestSkipped('SourceIterator ctor failed (API unreachable): ' . $e->getMessage());
        }

        $caps = $iterator->getCapabilities();

        $this->assertFalse($caps->sentenceVectors);
        $this->assertFalse($caps->documentVector384);
        $this->assertFalse($caps->documentVector1024);
        $this->assertTrue($caps->rawHtml);
        $this->assertFalse($caps->hasAnyVector());
    }

    public function testSourceCapabilitiesHasAnyVector(): void
    {
        $caps = new SourceCapabilities();
        $this->assertFalse($caps->hasAnyVector());

        $caps->sentenceVectors = true;
        $this->assertTrue($caps->hasAnyVector());

        $caps2 = new SourceCapabilities();
        $caps2->documentVector384 = true;
        $this->assertTrue($caps2->hasAnyVector());

        $caps3 = new SourceCapabilities();
        $caps3->documentVector1024 = true;
        $this->assertTrue($caps3->hasAnyVector());
    }
}
