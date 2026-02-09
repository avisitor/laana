<?php

namespace Noiiolelo\Tests\API;

use Noiiolelo\Tests\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class APIEndpointTest extends BaseTestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $baseUrl = getenv('NOIIOLELO_TEST_BASE_URL');
        if (!$baseUrl) {
            throw new \RuntimeException('NOIIOLELO_TEST_BASE_URL must be set for API tests.');
        }
        $this->baseUrl = rtrim((string)$baseUrl, '/') . '/';
    }

    /**
     * Execute API endpoint via HTTP request
     */
    private function executeApiRequest(string $endpoint, array $params = []): string
    {
        // Use query parameter routing (path=endpoint) for compatibility with servers
        // that don't have AcceptPathInfo enabled
        $params['path'] = $endpoint;
        $url = $this->baseUrl . 'api.php?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $output = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($output === false) {
            return '';
        }

        if ($output === '' && $error) {
            return '';
        }

        return $output;
    }

    /**
     * Execute ops endpoint via HTTP request
     */
    private function executeOpsRequest(string $endpoint, array $params = []): string
    {
        $url = $this->baseUrl . 'ops/' . $endpoint . '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $output = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($output === false) {
            return '';
        }

        if ($output === '' && $error) {
            return '';
        }

        return $output;
    }

    public function testApiSourcesEndpoint(): void
    {
        $output = $this->executeApiRequest('sources');
        
        $this->assertNotEmpty($output, 'API should return output');
        
        $data = json_decode($output, true);
        $this->assertNotNull($data, 'API response should be valid JSON');
        $this->assertIsArray($data, 'Response should be an array/object');
        $this->assertArrayHasKey('sourceids', $data, 'Response should have sourceids key');
        $this->assertIsArray($data['sourceids'], 'sourceids should be an array');
        $this->assertNotEmpty($data['sourceids'], 'sourceids array should not be empty');
        $this->assertIsNumeric($data['sourceids'][0], 'sourceids should contain numeric IDs');
    }

    #[DataProvider('providerNamesProvider')]
    public function testApiSourcesWithProvider(string $providerName): void
    {
        $output = $this->executeApiRequest('sources', [
            'provider' => $providerName
        ]);
        
        $this->assertNotEmpty($output);
        $data = json_decode($output, true);
        $this->assertNotNull($data);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data, "$providerName provider should return sources");
    }

    #[DataProvider('providerNamesProvider')]
    public function testResultCountEndpoint(string $providerName): void
    {
        $searchPattern = ($providerName === 'Elasticsearch') ? 'match' : 'exact';
        
        $output = $this->executeOpsRequest('resultcount.php', [
            'search' => 'aloha',
            'searchpattern' => $searchPattern,
            'provider' => $providerName
        ]);
        
        $this->assertNotEmpty($output);
        $this->assertIsNumeric(trim($output), 'Result count should be numeric');
        $count = intval(trim($output));
        $this->assertGreaterThan(0, $count, "Searching for \"aloha\" with $providerName should return results");
    }

    public function testResultCountWithHybridMode(): void
    {
        if (!$this->isValidProvider('Elasticsearch')) {
            $this->markTestSkipped('Elasticsearch provider not in valid provider list');
        }

        $output = $this->executeOpsRequest('resultcount.php', [
            'search' => 'aloha',
            'searchpattern' => 'hybrid',
            'provider' => 'Elasticsearch'
        ]);
        
        $this->assertNotEmpty($output);
        $count = intval(trim($output));
        $this->assertEquals(-1, $count, 'Hybrid search should return -1 for count');
    }

    public function testInvalidProviderParameter(): void
    {
        // This should fall back to default provider from .env
        $output = $this->executeOpsRequest('resultcount.php', [
            'search' => 'aloha',
            'searchpattern' => 'exact',
            'provider' => 'InvalidProvider'
        ]);
        
        // Should still return a result (using default provider)
        $this->assertNotEmpty($output);
    }

    public static function providerNamesProvider(): array
    {
        return array_map(fn($name) => [$name], self::$validProviders);
    }
}
