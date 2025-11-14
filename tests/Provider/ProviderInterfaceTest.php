<?php

namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../lib/provider.php';

class ProviderInterfaceTest extends BaseTestCase
{
    protected function setUp(): void
    {
        resetRequest();
    }

    /**
     * @dataProvider providerNamesProvider
     */
    public function testProviderLoads(string $providerName): void
    {
        $provider = getTestProvider($providerName);
        $this->assertNotNull($provider);
        $this->assertEquals($providerName, $provider->getName());
    }

    public function testDefaultProviderFromEnv(): void
    {
        resetRequest();
        $provider = getTestProvider();
        $this->assertNotNull($provider);
        $this->assertContains($provider->getName(), $this->getValidProviders());
    }

    public function testProviderSwitching(): void
    {
        $providers = $this->getValidProviders();
        if (count($providers) < 2) {
            $this->markTestSkipped('Need at least 2 providers for switching test');
        }

        $first = getTestProvider($providers[0]);
        $this->assertEquals($providers[0], $first->getName());

        resetRequest();
        $second = getTestProvider($providers[1]);
        $this->assertEquals($providers[1], $second->getName());
    }

    public static function providerNamesProvider(): array
    {
        return array_map(fn($name) => [$name], self::$validProviders);
    }

    /**
     * @dataProvider providerNamesProvider
     */
    public function testProviderHasRequiredMethods(string $providerName): void
    {
        $provider = getTestProvider($providerName);
        
        $this->assertTrue(method_exists($provider, 'getSentences'));
        $this->assertTrue(method_exists($provider, 'getMatchingSentenceCount'));
        $this->assertTrue(method_exists($provider, 'getSources'));
        $this->assertTrue(method_exists($provider, 'getAvailableSearchModes'));
        $this->assertTrue(method_exists($provider, 'getCorpusStats'));
    }

    public function testLaanaSearchModes(): void
    {
        if (!$this->isValidProvider('Laana')) {
            $this->markTestSkipped('Laana provider not in valid provider list');
        }

        $provider = getTestProvider('Laana');
        $modes = $provider->getAvailableSearchModes();
        
        $this->assertIsArray($modes);
        $this->assertArrayHasKey('exact', $modes);
        $this->assertArrayHasKey('any', $modes);
        $this->assertArrayHasKey('all', $modes);
        $this->assertArrayHasKey('regex', $modes);
    }

    public function testElasticsearchSearchModes(): void
    {
        if (!$this->isValidProvider('Elasticsearch')) {
            $this->markTestSkipped('Elasticsearch provider not in valid provider list');
        }

        $provider = getTestProvider('Elasticsearch');
        $modes = $provider->getAvailableSearchModes();
        
        $this->assertIsArray($modes);
        $this->assertArrayHasKey('match', $modes);
        $this->assertArrayHasKey('phrase', $modes);
        $this->assertArrayHasKey('hybrid', $modes);
    }

    /**
     * @dataProvider providerNamesProvider
     */
    public function testCorpusStatsStructure(string $providerName): void
    {
        $provider = getTestProvider($providerName);
        $stats = $provider->getCorpusStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('sentence_count', $stats);
        $this->assertArrayHasKey('source_count', $stats);
    }
}
