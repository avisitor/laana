<?php

namespace Noiiolelo\Tests\Indexing;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use HawaiianSearch\SourceRetriever;
use Noiiolelo\Tests\BaseTestCase;

class SourceRetrieverTest extends BaseTestCase
{
    protected function setUp(): void
    {
        if (!isset($_ENV['NOIIOLELO_API_BASE_URL'])) {
            $this->markTestSkipped('NOIIOLELO_API_BASE_URL not set');
        }
    }

    private function createRetrieverWithMockClient(MockHandler $mock): SourceRetriever
    {
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        return new SourceRetriever([
            'httpClient' => $client,
            'client' => null,
        ]);
    }

    public function testApiUrlsDerivedFromEnv(): void
    {
        $apiBaseUrl = $_ENV['NOIIOLELO_API_BASE_URL'];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['sources' => []])),
        ]);
        $retriever = $this->createRetrieverWithMockClient($mock);

        $reflection = new \ReflectionClass($retriever);

        $baseurlProp = $reflection->getProperty('baseurl');
        $baseurlProp->setAccessible(true);
        $baseurl = $baseurlProp->getValue($retriever);
        $this->assertStringStartsWith($apiBaseUrl, $baseurl, 'baseurl should start with NOIIOLELO_API_BASE_URL');
        $this->assertStringContainsString('?path=source/', $baseurl);

        $sourcesUrlProp = $reflection->getProperty('sourcesURL');
        $sourcesUrlProp->setAccessible(true);
        $sourcesUrl = $sourcesUrlProp->getValue($retriever);
        $this->assertStringStartsWith($apiBaseUrl, $sourcesUrl, 'sourcesURL should start with NOIIOLELO_API_BASE_URL');
        $this->assertStringContainsString('provider=MySQL', $sourcesUrl);
    }

    public function testFetchSourcesReturnsList(): void
    {
        $sources = [
            ['sourceid' => '1', 'sourcename' => 'Source A', 'groupname' => 'g'],
            ['sourceid' => '2', 'sourcename' => 'Source B', 'groupname' => 'g'],
        ];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['sources' => $sources])),
        ]);
        $retriever = $this->createRetrieverWithMockClient($mock);
        $result = $retriever->fetchSources();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testFetchSourcesHandlesEmptyResponse(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['sources' => []])),
        ]);
        $retriever = $this->createRetrieverWithMockClient($mock);
        $result = $retriever->fetchSources();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testFetchSourcesHandlesHttpError(): void
    {
        $mock = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);
        $retriever = $this->createRetrieverWithMockClient($mock);

        $result = $retriever->fetchSources();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testFetchSourceReturnsStringForPlainType(): void
    {
        $responseBody = json_encode(['text' => 'This is the document text.']);
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $responseBody),
        ]);
        $retriever = $this->createRetrieverWithMockClient($mock);
        $result = $retriever->fetchSource('12345', 'plain');

        $this->assertIsString($result);
        $this->assertEquals('This is the document text.', $result);
    }

    public function testFetchSourceReturnsStringForAnyType(): void
    {
        // fetchSource returns ?string, keyed by type name
        $responseBody = json_encode(['metadata' => 'some metadata content']);
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $responseBody),
        ]);
        $retriever = $this->createRetrieverWithMockClient($mock);
        $result = $retriever->fetchSource('12345', 'metadata');

        $this->assertIsString($result);
        $this->assertEquals('some metadata content', $result);
    }

    public function testFetchSourceHandlesHttpError(): void
    {
        $mock = new MockHandler([
            new Response(404, [], json_encode(['error' => 'Not found'])),
        ]);
        $retriever = $this->createRetrieverWithMockClient($mock);
        $result = $retriever->fetchSource('99999', 'plain');

        $this->assertNull($result);
    }

    public function testFetchSourceHandlesMissingKey(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['other' => 'data'])),
        ]);
        $retriever = $this->createRetrieverWithMockClient($mock);
        $result = $retriever->fetchSource('12345', 'plain');

        $this->assertNull($result);
    }
}
