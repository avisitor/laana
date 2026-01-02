<?php

require_once __DIR__ . '/../TestBase.php';

class TestMetadataFiltering extends TestBase {
    private $documentsIndex;

    protected function setUp() {
        parent::setUp();
        $this->documentsIndex = $this->client->getDocumentsIndexName();
        $this->indexTestDocuments();
        $this->client->refresh($this->documentsIndex);
    }

    private function indexTestDocuments() {
        $documents = [
            [
                '_index' => $this->documentsIndex,
                '_id' => 'doc1',
                '_source' => [
                    'sourceid' => 'doc1',
                    'text' => 'Test document 1',
                    'groupname' => 'groupA',
                    'sourcename' => 'sourceX',
                ]
            ],
            [
                '_index' => $this->documentsIndex,
                '_id' => 'doc2',
                '_source' => [
                    'sourceid' => 'doc2',
                    'text' => 'Test document 2',
                    'groupname' => 'groupB',
                    'sourcename' => 'sourceY',
                ]
            ],
        ];

        $this->client->bulkIndex($documents);
    }

    protected function execute() {
        $this->testFilterByGroupname();
    }

    private function testFilterByGroupname() {
        $this->log("Testing filtering by groupname");
        
        $queryBuilder = new \HawaiianSearch\QueryBuilder($this->client->getEmbeddingClient());
        $query = $queryBuilder->build('match', 'Test', [
            'documentsIndex' => $this->documentsIndex,
            'sentencesIndex' => $this->client->getSentencesIndexName(),
            'offset' => 0,
            'k' => 10,
        ]);

        $query['body']['query'] = [
            'bool' => [
                'must' => $query['body']['query'],
                'filter' => [
                    ['term' => ['groupname' => 'groupA']]
                ]
            ]
        ];

        $results = $this->client->getRawClient()->search($query)->asArray();
        
        $this->assertEquals(1, $results['hits']['total']['value'], "Should find one document in groupA");
        $this->assertEquals('doc1', $results['hits']['hits'][0]['_source']['sourceid'], "Should find the correct document in groupA");
    }
}