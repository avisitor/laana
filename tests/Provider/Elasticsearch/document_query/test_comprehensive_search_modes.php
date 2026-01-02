<?php

require_once __DIR__ . '/../TestBase.php';

class TestComprehensiveSearchModes extends TestBase {
    private $documents = [
        [
            'id' => '1',
            'sourceid' => 'test_001',
            'groupname' => 'test_group',
            'sourcename' => 'Hawaiian Cultural Document',
            'date' => '2024-01-01',
            'authors' => 'Test Author',
            'hawaiian_word_ratio' => 0.35,
            'text' => "Ua loaa ia'u ka hanohano nui o ka ike a@a aku, ua hoopaa loa ia ma ko oukou mau puuwai, ke aloha no ko kakou aina, ka lahui a me ko kakou Moiwahine, a ua a-a ko oukou uhane, e hoike mai i ko oukou kulana kupaa, no ke aloha i ko kakou aina makamae.",
        ],
        [
            'id' => '2',
            'sourceid' => 'test_002',
            'groupname' => 'test_group',
            'sourcename' => 'Geography and Islands',
            'date' => '2024-01-02',
            'authors' => 'Geography Author',
            'hawaiian_word_ratio' => 0.6,
            'text' => 'The Hawaiian islands include Hawaiʻi, Maui, Oʻahu, Kauaʻi, Molokaʻi, Lānaʻi, Niʻihau, and Kahoʻolawe.',
        ],
    ];

    protected function indexTestDocuments() {
        $docsToIndex = [];
        foreach ($this->documents as $doc) {
            $docsToIndex[] = [
                '_index' => $this->documentsIndex,
                '_id' => $doc['id'],
                '_source' => $doc,
            ];
        }
        $this->client->bulkIndex($docsToIndex);
    }

    protected function execute() {
        $this->testAllSearchModes();
    }

    private function testAllSearchModes() {
        $this->log("Testing all search modes from QueryBuilder::MODES");
        
        $allModes = HawaiianSearch\QueryBuilder::MODES;
        
        $testCases = [
            'match' => ['aloha aina', 'Should find documents with aloha and aina'],
            'matchsentence' => ['aloha aina', 'Should find sentences with aloha and aina'],
            'matchsentence_all' => ['aloha aina kakou', 'Should find sentences with aloha, aina, and kakou'],
            'term' => ['aloha', 'Should find exact term matches for aloha'],
            'termsentence' => ['aloha', 'Should find exact term sentence matches for aloha'],
            'phrase' => ['aloha aina', 'Should find exact phrase matches for aloha aina'],
            'phrasesentence' => ['aloha aina', 'Should find exact phrase sentence matches for aloha aina'],
            'regexp' => ['.*[Aa]loha.*', 'Should find regexp pattern matches'],
            'regexpsentence' => ['.*[Aa]ina.*', 'Should find sentence-level regexp matches'],
            'vector' => ['aloha aina', 'Should find vector similarity matches'],
            'vectorsentence' => ['aloha aina', 'Should find sentence vector matches'],
            'hybrid' => ['aloha aina', 'Should find hybrid text+vector matches'],
            'hybridsentence' => ['aloha aina', 'Should find hybrid sentence matches'],
            'knn' => ['aloha aina', 'Should find k-nearest neighbor matches'],
            'knnsentence' => ['aloha aina', 'Should find sentence k-NN matches'],
        ];
        
        $passedModes = [];
        $failedModes = [];
        
        foreach ($allModes as $mode) {
            if ($mode === 'wildcard') {
                // Wildcard is not a standard mode, skipping
                continue;
            }
            if (!isset($testCases[$mode])) {
                $failedModes[$mode] = "No test case defined for mode '$mode'";
                continue;
            }
            
            list($query, $description) = $testCases[$mode];
            
            try {
                $this->log("Testing mode '$mode' with query '$query'");
                
                $results = $this->client->search($query, $mode, $this->defaultSearchOptions);
                
                $this->assertTrue(is_array($results), "Results for mode '{$mode}' should be an array.");
                
                $passedModes[$mode] = $description;
                
            } catch (Exception $e) {
                $failedModes[$mode] = "Exception: " . $e->getMessage();
                $this->log("✗ Mode '$mode' failed: " . $e->getMessage());
            }
        }
        
        $this->assertEmpty($failedModes, "Some search modes failed: " . implode(', ', array_keys($failedModes)));
    }
}