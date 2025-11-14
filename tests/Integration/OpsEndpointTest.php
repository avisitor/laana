<?php

namespace Noiiolelo\Tests\Integration;

use Noiiolelo\Tests\BaseTestCase;

class OpsEndpointTest extends BaseTestCase
{
    /**
     * Execute endpoint via HTTP request
     */
    private function executeEndpoint(string $endpoint, array $params): string
    {
        $baseUrl = 'https://noiiolelo.worldspot.org/';
        $url = $baseUrl . $endpoint . '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $output = curl_exec($ch);
        curl_close($ch);
        
        return $output !== false ? $output : '';
    }

    /**
     * @dataProvider providerNamesProvider
     */
    public function testGetPageHtmlWithPagination(string $providerName): void
    {
        $pattern = $providerName === 'Laana' ? 'exact' : 'match';
        
        $output = $this->executeEndpoint('ops/getPageHtml.php', [
            'word' => 'aloha',
            'pattern' => $pattern,
            'page' => '1',
            'order' => 'rand',
            'provider' => $providerName
        ]);
        
        $this->assertNotEmpty($output, 'Should return HTML output');
        $this->assertStringContainsString('hawaiiansentence', $output, 'Should contain sentence div');
        $this->assertStringContainsString('aloha', strtolower($output), 'Should contain search term "aloha"');
        $this->assertStringContainsString('<a', $output, 'Should contain links');
    }

    public function testGetPageHtmlWithDifferentOrdering(): void
    {
        if (!$this->isValidProvider('Laana')) {
            $this->markTestSkipped('Laana provider not in valid provider list');
        }

        $output1 = $this->executeEndpoint('ops/getPageHtml.php', [
            'word' => 'aloha',
            'pattern' => 'exact',
            'page' => '1',
            'order' => 'date',
            'provider' => 'Laana'
        ]);
        
        $output2 = $this->executeEndpoint('ops/getPageHtml.php', [
            'word' => 'aloha',
            'pattern' => 'exact',
            'page' => '1',
            'order' => 'source',
            'provider' => 'Laana'
        ]);
        
        $this->assertNotEmpty($output1, 'Date ordering should return results');
        $this->assertNotEmpty($output2, 'Source ordering should return results');
        $this->assertStringContainsString('aloha', strtolower($output1));
        $this->assertStringContainsString('aloha', strtolower($output2));
        
        // Different orderings should potentially return different results (though content overlaps)
        $this->assertNotEquals($output1, $output2, 'Different sort orders should produce different output');
    }

    /**
     * @dataProvider providerNamesProvider
     */
    public function testResultCountCommandLine(string $providerName): void
    {
        $pattern = $providerName === 'Laana' ? 'exact' : 'match';
        
        $output = $this->executeEndpoint('ops/resultcount.php', [
            'search' => 'aloha',
            'searchpattern' => $pattern,
            'provider' => $providerName
        ]);
        
        $this->assertIsNumeric(trim($output), 'Result count should be numeric');
        $count = intval(trim($output));
        $this->assertGreaterThan(0, $count, "Should find results for common word \"aloha\" with $providerName");
        $this->assertLessThan(1000000, $count, 'Result count should be reasonable (< 1 million)');
    }

    /**
     * @dataProvider providerNamesProvider
     */
    public function testMultipleConsecutiveRequests(string $providerName): void
    {
        $pattern = $providerName === 'Laana' ? 'exact' : 'match';
        
        $count1 = intval(trim($this->executeEndpoint('ops/resultcount.php', [
            'search' => 'aloha',
            'searchpattern' => $pattern,
            'provider' => $providerName
        ])));
        
        $count2 = intval(trim($this->executeEndpoint('ops/resultcount.php', [
            'search' => 'mahalo',
            'searchpattern' => $pattern,
            'provider' => $providerName
        ])));
        
        $this->assertGreaterThan(0, $count1, "Should find results for \"aloha\" with $providerName");
        $this->assertGreaterThan(0, $count2, "Should find results for \"mahalo\" with $providerName");
        $this->assertNotEquals($count1, $count2, 'Different search terms should return different counts');
    }

    public function testVectorSearchMode(): void
    {
        if (!$this->isValidProvider('Elasticsearch')) {
            $this->markTestSkipped('Elasticsearch provider not in valid provider list');
        }

        $output = $this->executeEndpoint('ops/getPageHtml.php', [
            'word' => 'aloha',
            'pattern' => 'hybrid',
            'page' => '1',
            'order' => 'random',
            'provider' => 'Elasticsearch'
        ]);
        
        $this->assertNotEmpty($output);
    }

    public function testProviderSwitchingDuringSession(): void
    {
        $providers = $this->getValidProviders();
        if (count($providers) < 2) {
            $this->markTestSkipped('Need at least 2 providers for switching test');
        }

        $pattern1 = $providers[0] === 'Laana' ? 'exact' : 'match';
        $pattern2 = $providers[1] === 'Laana' ? 'exact' : 'match';
        
        $count1 = trim($this->executeEndpoint('ops/resultcount.php', [
            'search' => 'aloha',
            'searchpattern' => $pattern1,
            'provider' => $providers[0]
        ]));
        
        $count2 = trim($this->executeEndpoint('ops/resultcount.php', [
            'search' => 'aloha',
            'searchpattern' => $pattern2,
            'provider' => $providers[1]
        ]));
        
        $this->assertNotEmpty($count1);
        $this->assertNotEmpty($count2);
    }

    public static function providerNamesProvider(): array
    {
        return array_map(fn($name) => [$name], self::$validProviders);
    }
}
