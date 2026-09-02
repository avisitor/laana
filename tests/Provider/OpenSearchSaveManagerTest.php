<?php
namespace Noiiolelo\Tests\Provider;

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\OpenSearchClient;
use Noiiolelo\Tests\BaseTestCase;

class OpenSearchSaveManagerTest extends BaseTestCase
{
    public function testUsesOpenSearchClient(): void
    {
        if (!getenv('OS_HOST')) {
            $this->markTestSkipped('OS_HOST must be set for OpenSearchSaveManager tests');
        }
        $mgr = new \Noiiolelo\Providers\OpenSearch\OpenSearchSaveManager(['verbose' => false]);
        $client = $mgr->getClient();
        $this->assertInstanceOf(OpenSearchClient::class, $client);
        $this->assertInstanceOf(ElasticsearchClient::class, $client);
    }
}
