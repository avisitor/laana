<?php

namespace Noiiolelo\Tests\Search;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../lib/provider.php';

class SearchFunctionalityTest extends BaseTestCase
{
    protected function setUp(): void
    {
        resetRequest();
    }

    public function testLaanaExactSearch(): void
    {
        if (!$this->isValidProvider('Laana')) {
            $this->markTestSkipped('Laana provider not in valid provider list');
        }

        $provider = getTestProvider('Laana');
        $results = $provider->getSentences('aloha', 'exact', 1);
        
        $this->assertIsArray($results);
        $this->assertNotEmpty($results, 'Should find results for "aloha"');
        
        foreach ($results as $result) {
            $this->assertArrayHasKey('hawaiiantext', $result, 'Result must have hawaiiantext');
            $this->assertArrayHasKey('sourcename', $result, 'Result must have sourcename');
            $this->assertArrayHasKey('sentenceid', $result, 'Result must have sentenceid');
            
            // Verify search term appears in result
            $text = strtolower($result['hawaiiantext']);
            $this->assertStringContainsString('aloha', $text, 'Result should contain search term');
        }
    }

    public function testLaanaAnyWordSearch(): void
    {
        if (!$this->isValidProvider('Laana')) {
            $this->markTestSkipped('Laana provider not in valid provider list');
        }

        $provider = getTestProvider('Laana');
        $results = $provider->getSentences('aloha mahalo', 'any', 1);
        
        $this->assertIsArray($results);
        $this->assertNotEmpty($results, 'Should find results for "aloha mahalo" with any mode');
        $this->assertLessThanOrEqual($provider->pageSize, count($results));
        
        // Each result should contain at least one of the words
        foreach ($results as $result) {
            $text = strtolower($result['hawaiiantext']);
            $hasAloha = strpos($text, 'aloha') !== false;
            $hasMahalo = strpos($text, 'mahalo') !== false;
            $this->assertTrue($hasAloha || $hasMahalo, 'Result should contain at least one search term');
        }
    }

    public function testLaanaAllWordsSearch(): void
    {
        if (!$this->isValidProvider('Laana')) {
            $this->markTestSkipped('Laana provider not in valid provider list');
        }

        $provider = getTestProvider('Laana');
        $results = $provider->getSentences('aloha mahalo', 'all', 1);
        
        $this->assertIsArray($results);
        // Each result should contain both words (case insensitive)
        foreach ($results as $result) {
            $text = strtolower($result['hawaiiantext']);
            $this->assertStringContainsString('aloha', $text);
            $this->assertStringContainsString('mahalo', $text);
        }
    }

    public function testLaanaRegexSearch(): void
    {
        if (!$this->isValidProvider('Laana')) {
            $this->markTestSkipped('Laana provider not in valid provider list');
        }

        $provider = getTestProvider('Laana');
        $results = $provider->getSentences('^aloha', 'regex', 1);
        
        $this->assertIsArray($results);
        
        // Verify regex pattern - each result should start with 'aloha'
        foreach ($results as $result) {
            $text = strtolower(trim($result['hawaiiantext']));
            $this->assertStringStartsWith('aloha', $text, 'Regex ^aloha should match text starting with aloha');
        }
    }

    public function testElasticsearchMatchSearch(): void
    {
        if (!$this->isValidProvider('Elasticsearch')) {
            $this->markTestSkipped('Elasticsearch provider not in valid provider list');
        }

        $provider = getTestProvider('Elasticsearch');
        $results = $provider->getSentences('aloha', 'match', 1);
        
        $this->assertIsArray($results);
        $this->assertNotEmpty($results, 'Elasticsearch should find results for "aloha"');
        
        foreach ($results as $result) {
            $this->assertArrayHasKey('hawaiiantext', $result);
            $this->assertArrayHasKey('sourcename', $result);
            
            // ES match should find the term (potentially stemmed/fuzzy)
            $text = strtolower($result['hawaiiantext']);
            $this->assertStringContainsString('aloha', $text);
        }
    }

    public function testElasticsearchPhraseSearch(): void
    {
        if (!$this->isValidProvider('Elasticsearch')) {
            $this->markTestSkipped('Elasticsearch provider not in valid provider list');
        }

        $provider = getTestProvider('Elasticsearch');
        $results = $provider->getSentences('aloha mai', 'phrase', 1);
        
        $this->assertIsArray($results);
        
        // Phrase search should find the exact phrase
        foreach ($results as $result) {
            $text = strtolower($result['hawaiiantext']);
            $this->assertStringContainsString('aloha mai', $text, 'Phrase search should find exact phrase');
        }
    }

    public function testElasticsearchHybridSearch(): void
    {
        if (!$this->isValidProvider('Elasticsearch')) {
            $this->markTestSkipped('Elasticsearch provider not in valid provider list');
        }

        $provider = getTestProvider('Elasticsearch');
        $results = $provider->getSentences('aloha', 'hybrid', 1);
        
        $this->assertIsArray($results);
        $this->assertLessThanOrEqual($provider->pageSize, count($results));
    }

    /**
     * @dataProvider providerNamesProvider
     */
    public function testSearchResultStructure(string $providerName): void
    {
        $provider = getTestProvider($providerName);
        $searchMode = $providerName === 'Laana' ? 'exact' : 'match';
        $results = $provider->getSentences('aloha', $searchMode, 1);
        
        if (count($results) > 0) {
            $result = $results[0];
            $this->assertIsArray($result);
            $this->assertArrayHasKey('hawaiiantext', $result);
            $this->assertArrayHasKey('sourcename', $result);
            $this->assertArrayHasKey('authors', $result);
        }
    }

    /**
     * @dataProvider providerNamesProvider
     */
    public function testEmptySearch(string $providerName): void
    {
        $provider = getTestProvider($providerName);
        $searchMode = $providerName === 'Laana' ? 'exact' : 'match';
        $results = $provider->getSentences('', $searchMode, 1);
        
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * @dataProvider providerNamesProvider
     */
    public function testPagination(string $providerName): void
    {
        $provider = getTestProvider($providerName);
        $searchMode = $providerName === 'Laana' ? 'any' : 'match';
        
        $page1 = $provider->getSentences('a', $searchMode, 1);
        $page2 = $provider->getSentences('a', $searchMode, 2);
        
        $this->assertIsArray($page1);
        $this->assertIsArray($page2);
        
        // Pages should be different (unless there's only one page of results)
        if (count($page1) >= $provider->pageSize) {
            $this->assertNotEquals($page1, $page2);
        }
    }

    /**
     * @dataProvider providerNamesProvider
     */
    public function testMatchCount(string $providerName): void
    {
        $provider = getTestProvider($providerName);
        $searchMode = $providerName === 'Laana' ? 'exact' : 'match';
        
        $count = $provider->getMatchingSentenceCount('aloha', $searchMode, -1, []);
        
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testElasticsearchHybridCountReturnsNegativeOne(): void
    {
        if (!$this->isValidProvider('Elasticsearch')) {
            $this->markTestSkipped('Elasticsearch provider not in valid provider list');
        }

        $provider = getTestProvider('Elasticsearch');
        $count = $provider->getMatchingSentenceCount('aloha', 'hybrid', -1, []);
        
        // Hybrid/vector search modes return -1 for count
        $this->assertEquals(-1, $count);
    }

    public static function providerNamesProvider(): array
    {
        return array_map(fn($name) => [$name], self::$validProviders);
    }
}
