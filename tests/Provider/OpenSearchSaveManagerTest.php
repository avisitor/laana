<?php
namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Tests\BaseTestCase;

class OpenSearchSaveManagerTest extends BaseTestCase
{
    public function testUsesOpenSearchClient(): void
    {
        if (!getenv('OS_HOST')) {
            $this->markTestSkipped('OS_HOST must be set for OpenSearchSaveManager tests');
        }
        $mgr = new \Noiiolelo\Providers\OpenSearch\OpenSearchSaveManager(['verbose' => false]);
        $this->assertInstanceOf(\HawaiianSearch\OpenSearchClient::class, $mgr->getClient());
        $this->assertInstanceOf(\HawaiianSearch\ElasticsearchClient::class, $mgr->getClient());
    }
}
