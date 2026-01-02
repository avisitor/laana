<?php

use HawaiianSearch\QueryBuilder;
use HawaiianSearch\EmbeddingClient;

class TestQueryBuilderHighlighting extends BaseTest {
    private $queryBuilder;

    protected function setUp() {
    // Use a real EmbeddingClient with a dummy endpoint
    $embeddingClient = new EmbeddingClient('http://localhost:5000');
    $this->queryBuilder = new QueryBuilder($embeddingClient);
    }

    protected function execute() {
        $this->testHighlightingForMatchQuery();
    }

    public function testHighlightingForMatchQuery() {
        $options = [
            'sentence_highlight' => false,
            'offset' => 0,
            'k' => 10,
        ];
        $params = $this->queryBuilder->build('match', 'test', $options);
        $this->assertArrayHasKey('highlight', $params['body'], "Should have highlight configuration for match query");
    }
}