<?php

require_once __DIR__ . '/../TestBase.php';

class TestSearchRelevance extends TestBase {
    private $documentsIndex;
    private $sentencesIndex;
    private $syntheticDocs;

    protected function setUp() {
        parent::setUp();
        $this->documentsIndex = $this->client->getDocumentsIndexName();
        $this->sentencesIndex = $this->client->getSentencesIndexName();
        $this->indexTestDocuments();
        $this->client->refresh($this->documentsIndex);
        $this->client->refresh($this->sentencesIndex);
    }

    private function indexTestDocuments() {
        $this->createSyntheticDocuments();
        
        $documents = [];
        $sentences = [];

        foreach ($this->syntheticDocs as $key => $doc) {
            $docSource = $doc['_source'];
            $documents[] = [
                '_index' => $this->documentsIndex,
                '_id' => $doc['_id'],
                '_source' => [
                    'sourceid' => $docSource['sourceid'],
                    'text' => $docSource['text'],
                    'hawaiian_word_ratio' => $docSource['hawaiian_word_ratio'],
                ]
            ];

            foreach ($docSource['sentences'] as $sentence) {
                $sentences[] = [
                    '_index' => $this->sentencesIndex,
                    '_id' => $doc['_id'] . '_' . $sentence['position'],
                    '_source' => [
                        'sourceid' => $docSource['sourceid'],
                        'text' => $sentence['text'],
                        'hawaiian_word_ratio' => $sentence['hawaiian_word_ratio'],
                    ]
                ];
            }
        }

        $this->client->bulkIndex($documents);
        $this->client->bulkIndex($sentences);
    }

    protected function execute() {
        $this->testTermSearchRelevance();
        $this->testPhraseSearchRelevance();
    }

    private function createSyntheticDocuments() {
        $this->syntheticDocs = [
            'hawaiian_greeting' => [
                '_id' => 'syn_001',
                '_source' => [
                    'sourceid' => 'synthetic_hawaiian_greeting',
                    'text' => 'Aloha kakahiaka! E komo mai i ka hale. Pehea oe i keia la?',
                    'sentences' => [
                        ['text' => 'Aloha kakahiaka!', 'position' => 0, 'hawaiian_word_ratio' => 1.0],
                    ],
                    'hawaiian_word_ratio' => 1.0,
                ]
            ],
            'exact_phrase' => [
                '_id' => 'syn_005',
                '_source' => [
                    'sourceid' => 'synthetic_exact_phrase',
                    'text' => 'Welcome to paradise island. This exact phrase should match perfectly.',
                    'sentences' => [
                        ['text' => 'This exact phrase should match perfectly.', 'position' => 0, 'hawaiian_word_ratio' => 0.0],
                    ],
                    'hawaiian_word_ratio' => 0.0,
                ]
            ]
        ];
    }

    private function testTermSearchRelevance() {
        $this->log("Testing term search relevance");
        $results = $this->client->search('aloha', 'term', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 0, "Term search for 'aloha' should return results");
        $this->assertEquals('synthetic_hawaiian_greeting', $results[0]['sourceid'], "Term search for 'aloha' should find the correct document");
    }

    private function testPhraseSearchRelevance() {
        $this->log("Testing phrase search relevance");
        $results = $this->client->search('exact phrase', 'phrase', $this->defaultSearchOptions);
        $this->assertTrue(count($results) > 0, "Phrase search for 'exact phrase' should return results");
        $this->assertEquals('synthetic_exact_phrase', $results[0]['sourceid'], "Phrase search for 'exact phrase' should find the correct document");
    }
}
