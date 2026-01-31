<?php

namespace Noiiolelo\Tests\Integration;

use Noiiolelo\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class OpsEndpointTest extends BaseTestCase
{
    /**
     * Execute endpoint via HTTP request
     */
    private function executeEndpoint(string $endpoint, array $params): string
    {
        $baseUrl = $_ENV['OPS_TEST_BASE_URL'] ?? '';
        if ($baseUrl) {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $output = curl_exec($ch);
            curl_close($ch);

            return $output !== false ? $output : '';
        }

        return $this->executeEndpointLocal($endpoint, $params);
    }

    /**
     * Execute endpoint locally (no HTTP dependency)
     */
    private function executeEndpointLocal(string $endpoint, array $params): string
    {
        $rootDir = dirname(__DIR__, 2);
        $path = $rootDir . '/' . ltrim($endpoint, '/');
        if (!file_exists($path)) {
            return '';
        }
        $query = http_build_query($params);
        $phpCode = 'parse_str(' . var_export($query, true) . ', $_GET);'
            . '$_REQUEST = $_GET;'
            . '$_SERVER["REQUEST_METHOD"] = "GET";'
            . 'include ' . var_export($path, true) . ';';

        $command = [PHP_BINARY, '-r', $phpCode];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $rootDir);
        if (!is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0) {
            return $errorOutput ?: '';
        }

        return $output;
    }

    #[DataProvider('providerNamesProvider')]
    public function testGetPageHtmlWithPagination(string $providerName): void
    {
        $pattern = ($providerName === 'Elasticsearch') ? 'phrase' : 'exact';

        $countOutput = $this->executeEndpoint('ops/resultcount.php', [
            'search' => 'aloha',
            'searchpattern' => ($providerName === 'Elasticsearch') ? 'match' : 'exact',
            'provider' => $providerName
        ]);

        if (!is_numeric(trim($countOutput)) || intval(trim($countOutput)) <= 0) {
            $this->markTestSkipped("No data available for provider $providerName");
        }
        
        $output = $this->executeEndpoint('ops/getPageHtml.php', [
            'word' => 'aloha',
            'pattern' => $pattern,
            'page' => '1',
            'order' => 'rand',
            'provider' => $providerName
        ]);
        
        $this->assertNotEmpty($output, 'Should return HTML output');
        $this->assertStringContainsString('aloha', strtolower($output), 'Should contain search term "aloha"');
    }

    public function testGetPageHtmlWithDifferentOrdering(): void
    {
        if (!$this->isValidProvider('MySQL')) {
            $this->markTestSkipped('MySQL provider not in valid provider list');
        }

        $output1 = $this->executeEndpoint('ops/getPageHtml.php', [
            'word' => 'aloha',
            'pattern' => 'exact',
            'page' => '1',
            'order' => 'date',
            'provider' => 'MySQL'
        ]);
        
        $output2 = $this->executeEndpoint('ops/getPageHtml.php', [
            'word' => 'aloha',
            'pattern' => 'exact',
            'page' => '1',
            'order' => 'source',
            'provider' => 'MySQL'
        ]);
        
        $this->assertNotEmpty($output1, 'Date ordering should return results');
        $this->assertNotEmpty($output2, 'Source ordering should return results');
        $this->assertStringContainsString('aloha', strtolower($output1));
        $this->assertStringContainsString('aloha', strtolower($output2));
    }

    #[DataProvider('providerNamesProvider')]
    public function testResultCountCommandLine(string $providerName): void
    {
        $pattern = ($providerName === 'Elasticsearch') ? 'match' : 'exact';
        
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

    #[DataProvider('providerNamesProvider')]
    public function testMultipleConsecutiveRequests(string $providerName): void
    {
        $pattern = ($providerName === 'Elasticsearch') ? 'match' : 'exact';
        
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

        $pattern1 = $providers[0] === 'MySQL' ? 'exact' : 'match';
        $pattern2 = $providers[1] === 'MySQL' ? 'exact' : 'match';
        
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
