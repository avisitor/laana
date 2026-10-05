<?php

namespace Noiiolelo\Tests\Indexing;

use HawaiianSearch\CorpusIndexer;
use HawaiianSearch\ElasticsearchClient;
use Noiiolelo\Tests\BaseTestCase;
use ReflectionClass;
use ReflectionMethod;

class StubSplitIndexClient extends ElasticsearchClient
{
    public function __construct()
    {
    }

    public function getDocumentsIndexName($indexName = ''): string
    {
        return 'test-documents';
    }

    public function getSentencesIndexName($indexName = ''): string
    {
        return 'test-sentences';
    }

    public function calculateGrammarPatterns(string $text): array
    {
        return str_contains(strtolower($text), 'aloha') ? ['pattern_a', 'pattern_b'] : [];
    }
}

/**
 * Guards the sentence _source built by CorpusIndexer::buildSplitSentenceSource()
 * — the single choke point through which every sentence doc enters the
 * sentences index in the split-indices path. getGrammarPatterns() term-
 * aggregates the grammar_patterns field, so this build must populate it.
 */
class SplitIndexSentenceFieldsTest extends BaseTestCase
{
    private const DOC_DATA = [
        '_source' => [
            'sourcename' => 'Ka Nupepa',
            'authors' => 'Editor',
            'date' => '2024-01-15',
            'groupname' => 'newspapers',
            'title' => '',
        ],
    ];

    private function createIndexer(): CorpusIndexer
    {
        $ref = new ReflectionClass(CorpusIndexer::class);
        $indexer = $ref->newInstanceWithoutConstructor();

        foreach ([
            'config' => ['quiet' => true],
            'client' => new StubSplitIndexClient(),
            'dryrun' => false,
            'sourceMeta' => [],
        ] as $propName => $value) {
            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $prop->setValue($indexer, $value);
        }

        return $indexer;
    }

    private function makeSentenceObject(string $text): array
    {
        return [
            'text' => $text,
            'vector' => array_fill(0, 384, 0.1),
            'position' => 0,
            'doc_id' => '42',
            'hawaiian_word_ratio' => 0.8,
            'word_count' => 2,
            'entity_count' => 0,
            'boilerplate_score' => 0.0,
            'length' => strlen($text),
            'frequency' => 1,
        ];
    }

    private function callBuildSentenceSource(CorpusIndexer $indexer, array $sentence): array
    {
        $method = new ReflectionMethod(CorpusIndexer::class, 'buildSplitSentenceSource');
        $method->setAccessible(true);
        return $method->invoke($indexer, $sentence, '42', 0, self::DOC_DATA);
    }

    public function testSentenceSourceCarriesGrammarPatterns(): void
    {
        $indexer = $this->createIndexer();
        $sentence = $this->makeSentenceObject('Aloha kākou.');

        $source = $this->callBuildSentenceSource($indexer, $sentence);

        $this->assertArrayHasKey(
            'grammar_patterns',
            $source,
            'Sentence docs must carry grammar_patterns — getGrammarPatterns() aggregates this field'
        );
        $this->assertSame(['pattern_a', 'pattern_b'], $source['grammar_patterns']);
    }

    public function testSentenceSourceCarriesEmptyGrammarPatternsWhenNoMatch(): void
    {
        $indexer = $this->createIndexer();
        $sentence = $this->makeSentenceObject('Ua hele mai au.');

        $source = $this->callBuildSentenceSource($indexer, $sentence);

        $this->assertArrayHasKey('grammar_patterns', $source);
        $this->assertSame([], $source['grammar_patterns']);
    }

    public function testSentenceSourceKeepsQualityMetadataAndDocFields(): void
    {
        $indexer = $this->createIndexer();
        $sentence = $this->makeSentenceObject('Aloha kākou.');

        $source = $this->callBuildSentenceSource($indexer, $sentence);

        // Quality metadata copied from the sentence object
        $this->assertSame(0.8, $source['hawaiian_word_ratio']);
        $this->assertSame(2, $source['word_count']);
        $this->assertSame(0, $source['entity_count']);
        $this->assertSame(0.0, $source['boilerplate_score']);
        $this->assertSame(strlen('Aloha kākou.'), $source['length']);
        $this->assertSame(1, $source['frequency']);
        // Document metadata passthrough (empty title falls back to sourcename)
        $this->assertSame('42', $source['doc_id']);
        $this->assertSame('42', $source['sourceid']);
        $this->assertSame('Ka Nupepa', $source['sourcename']);
        $this->assertSame('Editor', $source['authors']);
        $this->assertSame('2024-01-15', $source['date']);
        $this->assertSame('newspapers', $source['groupname']);
        $this->assertSame('Ka Nupepa', $source['title']);
    }
}
