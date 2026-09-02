<?php

namespace Noiiolelo\Tests\Indexing;

use HawaiianSearch\CorpusIndexer;
use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\EmbeddingClient;
use HawaiianSearch\MetadataExtractor;
use HawaiianSearch\PostgresSourceIterator;
use HawaiianSearch\PostgresSourceReader;
use HawaiianSearch\SourceCapabilities;
use Noiiolelo\Tests\BaseTestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Stubs — namespaced apart from AssembleDocSourceTest's stubs.
 */
class StoredVectorStubClient extends ElasticsearchClient
{
    public function __construct()
    {
    }

    public function getDocumentsIndexName($indexName = ''): string
    {
        return 'noiiolelo-docs';
    }

    public function getSentencesIndexName($indexName = ''): string
    {
        return 'noiiolelo-sentences';
    }

    public function getDocumentOutline(string $id, string $indexName = ''): ?array
    {
        return null;
    }

    public function getEmbeddingClient(): EmbeddingClient
    {
        return new StoredVectorStubEmbeddingClient();
    }
}

class StoredVectorStubEmbeddingClient extends EmbeddingClient
{
    /** @var array|null Vectors to return from embedSentences, or a Throwable to throw */
    public $nextResult = [];
    public array $calls = [];

    public function __construct()
    {
    }

    public function embedSentences(array $sentences, string $prefix = 'passage: ', string $modelName = self::MODEL_SMALL): ?array
    {
        $this->calls[] = $sentences;
        if ($this->nextResult instanceof \Throwable) {
            throw $this->nextResult;
        }
        return $this->nextResult;
    }
}

class StoredVectorStubMetadataExtractor extends MetadataExtractor
{
    public array $savedBatches = [];
    public float $stubBoilerplateScore = 0.42;

    public function __construct()
    {
    }

    public function analyzeSentence(string $text, string $docId, array $existingMetadata = null): array
    {
        return [
            'boilerplate_score' => $this->stubBoilerplateScore,
            'word_count' => 99,
        ];
    }

    public function bulkSaveSentenceMetadata(array $sentencesData): void
    {
        $this->savedBatches[] = $sentencesData;
    }
}

class StoredVectorStubReader extends PostgresSourceReader
{
    private ?array $nextSource;

    public function __construct(?array $nextSource)
    {
        $this->nextSource = $nextSource;
    }

    public function readSource(int $sourceId): ?array
    {
        return $this->nextSource;
    }
}

class PostgresStoredVectorsTest extends BaseTestCase
{
    private function make1024Vector(float $fill = 0.1): array
    {
        return array_fill(0, 1024, $fill);
    }

    private function make384Vector(float $fill = 0.2): array
    {
        return array_fill(0, 384, $fill);
    }

    /**
     * Build a CorpusIndexer without running its constructor, wiring only the
     * properties the exercised private methods touch.
     */
    private function createIndexer(
        ?StoredVectorStubReader $reader = null,
        ?SourceCapabilities $caps = null
    ): CorpusIndexer {
        $ref = new ReflectionClass(CorpusIndexer::class);
        $indexer = $ref->newInstanceWithoutConstructor();

        $set = function (string $prop, $value) use ($ref, $indexer): void {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($indexer, $value);
        };

        $set('config', ['quiet' => true]);
        $set('client', new StoredVectorStubClient());
        $set('dryrun', false);
        $set('sourceId', null);
        $set('maxDocuments', null);
        $set('sourceMeta', []);
        $set('sourceCapabilities', $caps ?? new SourceCapabilities());
        $set('metadataExtractor', new StoredVectorStubMetadataExtractor());
        if ($reader !== null) {
            $set('postgresReader', $reader);
        }

        return $indexer;
    }

    private function invoke(string $method, CorpusIndexer $indexer, ...$args)
    {
        $ref = new ReflectionClass(CorpusIndexer::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($indexer, ...$args);
    }

    private function setProp(CorpusIndexer $indexer, string $prop, $value): void
    {
        $ref = new ReflectionClass(CorpusIndexer::class);
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($indexer, $value);
    }

    private function makeStoredData(array $sentences): array
    {
        return [
            'sourceid' => 77,
            'sourcename' => 'Ka Nupepa',
            'groupname' => 'newspapers',
            'authors' => 'Editor',
            'date' => '2024-01-15',
            'link' => 'https://example.com/77',
            'title' => 'Real Title',
            'text' => 'Aloha kākou. Mahalo nui loa.',
            'html' => '<p>Aloha</p>',
            'text_vector_1024' => $this->make1024Vector(),
            'hawaiian_word_ratio' => 0.9,
            'sentences' => $sentences,
        ];
    }

    private function makeSourceRow(string $id = '77'): array
    {
        return [
            'sourceid' => $id,
            'sourcename' => 'Ka Nupepa',
            'groupname' => 'newspapers',
            'authors' => 'Editor',
            'date' => '2024-01-15',
            'link' => 'https://example.com/77',
            'title' => 'Real Title',
        ];
    }

    private function indexWithEmbedding(?StoredVectorStubReader $reader, StoredVectorStubEmbeddingClient $embedding): CorpusIndexer
    {
        $indexer = $this->createIndexer($reader, $this->capsWithBoilerplateDisabled());
        $this->setProp($indexer, 'client', new StoredVectorStubClientWithEmbedding($embedding));
        return $indexer;
    }

    // ---------------------------------------------------------------
    // Capability flags
    // ---------------------------------------------------------------

    public function testSentenceBoilerplateScoreCapabilityDefaultsFalse(): void
    {
        $caps = new SourceCapabilities();
        $this->assertFalse($caps->sentenceBoilerplateScore);
    }

    public function testPostgresIteratorDoesNotSupplyBoilerplateScore(): void
    {
        $mockPdo = $this->createMock(\PDO::class);
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn([]);
        $mockPdo->method('prepare')->willReturn($mockStmt);
        $client = new \stdClass();
        $client->conn = $mockPdo;

        $iterator = new PostgresSourceIterator(null, null, $client);
        $caps = $iterator->getCapabilities();

        $this->assertFalse($caps->sentenceBoilerplateScore);
        $this->assertTrue($caps->sentenceVectors);
        $this->assertTrue($caps->hasAnyVector());
    }

    // ---------------------------------------------------------------
    // Source metadata flag helpers
    // ---------------------------------------------------------------

    public function testSourceMetaFlagRoundtripOnRawEntry(): void
    {
        $indexer = $this->createIndexer();
        $this->setProp($indexer, 'sourceMeta', ['9' => ['sourceid' => '9', 'sourcename' => 'x']]);

        $this->assertFalse($this->invoke('getSourceMetaFlag', $indexer, '9', 'vectors_missing'));
        $this->invoke('setSourceMetaFlag', $indexer, '9', 'vectors_missing', true);
        $this->assertTrue($this->invoke('getSourceMetaFlag', $indexer, '9', 'vectors_missing'));
    }

    public function testSourceMetaFlagWritesThroughSourceWrapper(): void
    {
        $indexer = $this->createIndexer();
        // Shape returned by getSourceMetadata(): _id + _source body
        $this->setProp($indexer, 'sourceMeta', ['9' => ['_id' => '9', '_source' => ['sourceid' => '9']]]);

        $this->invoke('setSourceMetaFlag', $indexer, '9', 'vectors_missing', true);

        // Readable via the _source shape...
        $this->assertTrue($this->invoke('getSourceMetaFlag', $indexer, '9', 'vectors_missing'));
        // ...and persisted by saveSourceMetadata, which stores the _source body.
        $meta = (new ReflectionClass(CorpusIndexer::class))->getProperty('sourceMeta');
        $meta->setAccessible(true);
        $this->assertTrue($meta->getValue($indexer)['9']['_source']['vectors_missing']);
    }

    public function testGetSourceMetaFlagMissingSourceReturnsFalse(): void
    {
        $indexer = $this->createIndexer();
        $this->assertFalse($this->invoke('getSourceMetaFlag', $indexer, '404', 'discarded'));
    }

    // ---------------------------------------------------------------
    // Stored-vector source processing: backfill, flags, fill-in
    // ---------------------------------------------------------------

    public function testProcessStoredVectorsBackfillsMissingVectors(): void
    {
        $data = $this->makeStoredData([
            ['text' => 'Ua au i ka wai.', 'vector' => null, 'position' => 3, 'hawaiian_word_ratio' => 0.85, 'word_count' => 5],
        ]);
        $embedding = new StoredVectorStubEmbeddingClient();
        $embedding->nextResult = [$this->make384Vector(0.3)];
        $indexer = $this->indexWithEmbedding(new StoredVectorStubReader($data), $embedding);

        $doc = $this->invoke('processSourceWithStoredVectors', $indexer, $this->makeSourceRow(), 1);

        $this->assertIsArray($doc);
        $this->assertCount(1, $doc['_source']['sentences']);
        $sentence = $doc['_source']['sentences'][0];
        $this->assertCount(384, $sentence['vector']);
        $this->assertSame('Ua au i ka wai.', $sentence['text']);
        $this->assertSame(3, $sentence['position']);
        $this->assertSame(0.85, $sentence['hawaiian_word_ratio']);
        $this->assertSame(5, $sentence['word_count']);
        // The backfill actually went through the embedding service
        $this->assertSame([['Ua au i ka wai.']], $embedding->calls);
        // Everything resolved: no self-heal marker needed
        $this->assertFalse($this->invoke('getSourceMetaFlag', $indexer, '77', 'vectors_missing'));
    }

    public function testProcessStoredVectorsSetsVectorsMissingWhenBackfillFails(): void
    {
        // Sentence 1 has a valid stored vector; sentence 2 needs a backfill
        // that fails (wrong-dim response). Doc still indexes (sentence 1),
        // but vectors_missing must stay set for self-heal re-runs.
        $data = $this->makeStoredData([
            ['text' => 'Ua au i ka wai.', 'vector' => $this->make384Vector(), 'position' => 0],
            ['text' => 'Aloha kākou.', 'vector' => null, 'position' => 1],
        ]);
        $embedding = new StoredVectorStubEmbeddingClient();
        $embedding->nextResult = [array_fill(0, 383, 0.5)];
        $indexer = $this->indexWithEmbedding(new StoredVectorStubReader($data), $embedding);

        $doc = $this->invoke('processSourceWithStoredVectors', $indexer, $this->makeSourceRow(), 1);

        $this->assertIsArray($doc);
        $this->assertCount(1, $doc['_source']['sentences']);
        $this->assertTrue($this->invoke('getSourceMetaFlag', $indexer, '77', 'vectors_missing'));
    }

    public function testProcessStoredVectorsClearsVectorsMissingWhenComplete(): void
    {
        // Previously flagged, but laana now supplies valid vectors for all sentences.
        $data = $this->makeStoredData([
            ['text' => 'Ua au i ka wai.', 'vector' => $this->make384Vector(), 'position' => 0],
            ['text' => 'Aloha kākou.', 'vector' => $this->make384Vector(), 'position' => 1],
        ]);
        $indexer = $this->createIndexer(new StoredVectorStubReader($data), $this->capsWithBoilerplateDisabled());
        $this->setProp($indexer, 'sourceMeta', ['77' => ['_id' => '77', '_source' => ['sourceid' => '77', 'vectors_missing' => true]]]);

        $doc = $this->invoke('processSourceWithStoredVectors', $indexer, $this->makeSourceRow(), 1);

        $this->assertIsArray($doc);
        $this->assertFalse($this->invoke('getSourceMetaFlag', $indexer, '77', 'vectors_missing'));
    }

    public function testProcessStoredVectorsAbortsWhenEmbeddingServiceFails(): void
    {
        // Abort semantics: embedding failures must propagate, not be swallowed.
        $data = $this->makeStoredData([
            ['text' => 'Ua au i ka wai.', 'vector' => null, 'position' => 0],
        ]);
        $embedding = new StoredVectorStubEmbeddingClient();
        $embedding->nextResult = new RuntimeException('embedding service exploded');
        $indexer = $this->indexWithEmbedding(new StoredVectorStubReader($data), $embedding);

        $this->expectException(RuntimeException::class);
        $this->invoke('processSourceWithStoredVectors', $indexer, $this->makeSourceRow(), 1);
    }

    public function testProcessStoredVectorsSkipsDiscardedSource(): void
    {
        $data = $this->makeStoredData([
            ['text' => 'Ua au i ka wai.', 'vector' => $this->make384Vector(), 'position' => 0],
        ]);
        $indexer = $this->createIndexer(new StoredVectorStubReader($data), $this->capsWithBoilerplateDisabled());
        $this->setProp($indexer, 'sourceMeta', ['77' => ['_id' => '77', '_source' => ['sourceid' => '77', 'discarded' => true]]]);

        $doc = $this->invoke('processSourceWithStoredVectors', $indexer, $this->makeSourceRow(), 1);

        $this->assertNull($doc);
    }

    public function testProcessStoredVectorsSkipsWhenNoSentencesAtAll(): void
    {
        $data = $this->makeStoredData([]);
        $indexer = $this->createIndexer(new StoredVectorStubReader($data), $this->capsWithBoilerplateDisabled());

        $doc = $this->invoke('processSourceWithStoredVectors', $indexer, $this->makeSourceRow(), 1);

        $this->assertNull($doc);
        $this->assertTrue($this->invoke('getSourceMetaFlag', $indexer, '77', 'empty'));
        // The misleading english_only flag must NOT be set for this case
        $this->assertFalse($this->invoke('getSourceMetaFlag', $indexer, '77', 'english_only'));
    }

    public function testProcessStoredVectorsMarksVectorsMissingInsteadOfEnglishOnly(): void
    {
        // All sentences lack vectors and the backfill fails: the doc is
        // dropped with vectors_missing (not english_only).
        $data = $this->makeStoredData([
            ['text' => 'Ua au i ka wai.', 'vector' => null, 'position' => 0],
        ]);
        $embedding = new StoredVectorStubEmbeddingClient();
        $embedding->nextResult = [array_fill(0, 383, 0.5)];
        $indexer = $this->indexWithEmbedding(new StoredVectorStubReader($data), $embedding);

        $doc = $this->invoke('processSourceWithStoredVectors', $indexer, $this->makeSourceRow(), 1);

        $this->assertNull($doc);
        $this->assertTrue($this->invoke('getSourceMetaFlag', $indexer, '77', 'vectors_missing'));
        $this->assertFalse($this->invoke('getSourceMetaFlag', $indexer, '77', 'english_only'));
    }

    // ---------------------------------------------------------------
    // Boilerplate fill-in (capability-gated)
    // ---------------------------------------------------------------

    public function testProcessStoredVectorsFillsBoilerplateWhenSourceDoesNotSupply(): void
    {
        $data = $this->makeStoredData([
            // Source supplies boilerplate_score: must be kept untouched
            ['text' => 'Ua au i ka wai.', 'vector' => $this->make384Vector(), 'position' => 0, 'boilerplate_score' => 0.7, 'word_count' => 5],
            // Missing boilerplate_score: must be computed
            ['text' => 'Aloha kākou.', 'vector' => $this->make384Vector(), 'position' => 1, 'word_count' => 2],
        ]);
        $indexer = $this->createIndexer(new StoredVectorStubReader($data), $this->capsWithBoilerplateDisabled());

        $doc = $this->invoke('processSourceWithStoredVectors', $indexer, $this->makeSourceRow(), 1);

        $this->assertIsArray($doc);
        $sentences = $doc['_source']['sentences'];
        $this->assertSame(0.7, $sentences[0]['boilerplate_score']);
        $this->assertSame(0.42, $sentences[1]['boilerplate_score']);
        // Source metric is authoritative — not overwritten by the analyzer
        $this->assertSame(2, $sentences[1]['word_count']);
        // Metadata index kept in sync
        $extractor = (new ReflectionClass(CorpusIndexer::class))->getProperty('metadataExtractor');
        $extractor->setAccessible(true);
        $savedBatches = $extractor->getValue($indexer)->savedBatches;
        $this->assertCount(1, $savedBatches);
        $this->assertSame('Aloha kākou.', $savedBatches[0][0]['text']);
        $this->assertSame(1, $savedBatches[0][0]['position']);
    }

    public function testProcessStoredVectorsSkipsBoilerplateFillWhenCapabilityTrue(): void
    {
        $data = $this->makeStoredData([
            ['text' => 'Ua au i ka wai.', 'vector' => $this->make384Vector(), 'position' => 0],
        ]);
        $caps = $this->capsWithBoilerplateDisabled();
        $caps->sentenceBoilerplateScore = true; // source supplies the values
        $indexer = $this->createIndexer(new StoredVectorStubReader($data), $caps);

        $doc = $this->invoke('processSourceWithStoredVectors', $indexer, $this->makeSourceRow(), 1);

        $this->assertIsArray($doc);
        $this->assertArrayNotHasKey('boilerplate_score', $doc['_source']['sentences'][0]);
        $extractor = (new ReflectionClass(CorpusIndexer::class))->getProperty('metadataExtractor');
        $extractor->setAccessible(true);
        $this->assertCount(0, $extractor->getValue($indexer)->savedBatches);
    }

    public function testProcessStoredVectorsDoesNotSaveMetadataInDryrun(): void
    {
        $data = $this->makeStoredData([
            ['text' => 'Ua au i ka wai.', 'vector' => $this->make384Vector(), 'position' => 0],
        ]);
        $indexer = $this->createIndexer(new StoredVectorStubReader($data), $this->capsWithBoilerplateDisabled());
        $this->setProp($indexer, 'dryrun', true);

        $doc = $this->invoke('processSourceWithStoredVectors', $indexer, $this->makeSourceRow(), 1);

        $this->assertIsArray($doc);
        $this->assertSame(0.42, $doc['_source']['sentences'][0]['boilerplate_score']);
        $extractor = (new ReflectionClass(CorpusIndexer::class))->getProperty('metadataExtractor');
        $extractor->setAccessible(true);
        $this->assertCount(0, $extractor->getValue($indexer)->savedBatches);
    }

    // ---------------------------------------------------------------
    // Split index objects: title + stable sentence IDs
    // ---------------------------------------------------------------

    public function testCreateSplitIndexObjectsCarriesTitleAndStableSentenceIds(): void
    {
        // Sentence at position 1 is missing and its backfill fails; the
        // surviving sentences must keep their source ordinals in _id.
        $data = $this->makeStoredData([
            ['text' => 'Ua au i ka wai.', 'vector' => $this->make384Vector(), 'position' => 0],
            ['text' => 'Aloha kākou.', 'vector' => null, 'position' => 1],
            ['text' => 'Mahalo nui loa.', 'vector' => $this->make384Vector(), 'position' => 2],
        ]);
        $embedding = new StoredVectorStubEmbeddingClient();
        $embedding->nextResult = [array_fill(0, 383, 0.5)];
        $indexer = $this->indexWithEmbedding(new StoredVectorStubReader($data), $embedding);

        $result = $this->invoke('createSplitIndexObjects', $indexer, $this->makeSourceRow(), 1);

        $this->assertIsArray($result);
        // sources.title is carried through, not replaced by sourcename
        $this->assertSame('Real Title', $result['document']['_source']['title']);
        // Sentence _ids use the source ordinal, so the dropped sentence at
        // position 1 does not shift the id of the sentence at position 2.
        $ids = array_map(fn(array $s): string => $s['_id'], $result['sentences']);
        $this->assertSame(['77_0', '77_2'], $ids);
        $this->assertSame(2, $result['sentences'][1]['_source']['chunk_id']);
        $this->assertSame(2, $result['sentences'][1]['_source']['position']);
        $this->assertSame('Real Title', $result['sentences'][0]['_source']['title']);
    }

    public function testCreateSplitIndexObjectsFallsBackToSourcenameTitle(): void
    {
        $data = $this->makeStoredData([
            ['text' => 'Ua au i ka wai.', 'vector' => $this->make384Vector(), 'position' => 0],
        ]);
        // Title comes from the iterator row; absent there, sourcename is used.
        $row = $this->makeSourceRow();
        unset($row['title']);
        $indexer = $this->createIndexer(new StoredVectorStubReader($data), $this->capsWithBoilerplateDisabled());

        $result = $this->invoke('createSplitIndexObjects', $indexer, $row, 1);

        $this->assertIsArray($result);
        $this->assertSame('Ka Nupepa', $result['document']['_source']['title']);
    }

    // ---------------------------------------------------------------
    // assembleDocSource title propagation (public API)
    // ---------------------------------------------------------------

    public function testAssembleDocSourceCarriesTitleWithFallback(): void
    {
        $indexer = $this->createIndexer();
        $text = 'Aloha kākou.';
        $chunks = [['chunk_index' => 0, 'chunk_text' => $text, 'chunk_start' => 0, 'chunk_length' => strlen($text)]];
        $sentences = [['text' => $text, 'vector' => $this->make384Vector(), 'position' => 0, 'doc_id' => '42']];

        $withTitle = $indexer->assembleDocSource(
            ['sourceid' => '42', 'sourcename' => 'Ka Nupepa', 'title' => 'Real Title'],
            $text, $chunks, $this->make1024Vector(), $sentences, 0.9
        );
        $this->assertSame('Real Title', $withTitle['_source']['title']);

        $withoutTitle = $indexer->assembleDocSource(
            ['sourceid' => '42', 'sourcename' => 'Ka Nupepa'],
            $text, $chunks, $this->make1024Vector(), $sentences, 0.9
        );
        $this->assertSame('Ka Nupepa', $withoutTitle['_source']['title']);

        $nullTitle = $indexer->assembleDocSource(
            ['sourceid' => '42', 'sourcename' => 'Ka Nupepa', 'title' => null],
            $text, $chunks, $this->make1024Vector(), $sentences, 0.9
        );
        $this->assertSame('Ka Nupepa', $nullTitle['_source']['title']);
    }

    // ---------------------------------------------------------------

    private function capsWithBoilerplateDisabled(): SourceCapabilities
    {
        // Mirrors PostgresSourceIterator::getCapabilities(): stored vectors
        // available, boilerplate_score not supplied by the source.
        $caps = new SourceCapabilities();
        $caps->sentenceVectors = true;
        $caps->documentVector1024 = true;
        $caps->rawHtml = true;
        $caps->sentenceBoilerplateScore = false;
        return $caps;
    }
}

class StoredVectorStubClientWithEmbedding extends StoredVectorStubClient
{
    private EmbeddingClient $embedding;

    public function __construct(EmbeddingClient $embedding)
    {
        $this->embedding = $embedding;
    }

    public function getEmbeddingClient(): EmbeddingClient
    {
        return $this->embedding;
    }
}
