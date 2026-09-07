<?php

namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Tests\BaseTestCase;
use Noiiolelo\ProviderUnavailableException;

require_once __DIR__ . '/../../lib/provider.php';
require_once __DIR__ . '/../../lib/web_error.php';

/**
 * When a provider's backend cannot be reached, getProvider() must throw
 * Noiiolelo\ProviderUnavailableException (wrapping the underlying cause)
 * instead of letting the raw transport exception fatal the request with a
 * blank screen. Web entry points catch it and render a clear
 * "provider unavailable" response (see lib/web_error.php).
 */
final class ProviderUnavailableTest extends BaseTestCase
{
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Snapshot so overrides can't leak into other tests in this process.
        $this->savedEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->savedEnv;
        putenv('SKIP_EMBEDDING_VALIDATION');
        parent::tearDown();
    }

    /**
     * Simulate a dead Elasticsearch backend with a closed loopback port:
     * connection refused is immediate and deterministic (no live service
     * needed). PHPUNIT_RUNNING makes the .env Loader preserve $_ENV
     * overrides instead of clobbering them with file values.
     */
    public function testUnreachableElasticsearchThrowsProviderUnavailable(): void
    {
        $_ENV['ES_HOST'] = '127.0.0.1';
        $_ENV['ES_PORT'] = '1';
        $_ENV['ES_SCHEME'] = 'http';
        $_ENV['SKIP_EMBEDDING_VALIDATION'] = '1';
        unset($_ENV['SKIP_FILTER_CHECKS']);

        try {
            getProvider('Elasticsearch');
            $this->fail('Expected ProviderUnavailableException for an unreachable Elasticsearch backend');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame('Elasticsearch', $e->getProviderName());
            $this->assertStringContainsStringIgnoringCase('unavailable', $e->getMessage());
            $this->assertStringContainsString('Elasticsearch', $e->getMessage());
            // The underlying transport failure must stay reachable for logs.
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function testUnknownProviderStillFallsBackToMySQL(): void
    {
        try {
            $provider = getProvider('BogusProvider');
            $this->assertSame('MySQL', $provider->getName());
        } catch (ProviderUnavailableException $e) {
            // getProvider('BogusProvider') resolves to MySQL; if the local
            // MySQL backend happens to be down the fallback itself cannot be
            // demonstrated in this environment.
            $this->assertSame('MySQL', $e->getProviderName());
            $this->markTestSkipped('MySQL backend unreachable: ' . $e->getMessage());
        }
    }

    public function testRendererMessageAndStatus(): void
    {
        $down = new ProviderUnavailableException('Elasticsearch', "Search provider 'Elasticsearch' is unavailable: no alive nodes");

        // User-facing message is friendly, names the provider, and never
        // leaks the raw transport error.
        $msg = noiiolelo_user_message($down);
        $this->assertStringContainsString('Elasticsearch', $msg);
        $this->assertStringContainsString('temporarily unavailable', $msg);
        $this->assertStringNotContainsString('no alive nodes', $msg);

        // Backend outage -> 503 Service Unavailable, anything else -> 500.
        $this->assertSame(503, noiiolelo_error_status($down));
        $this->assertSame(500, noiiolelo_error_status(new \RuntimeException('boom')));

        $generic = noiiolelo_user_message(new \RuntimeException('boom'));
        $this->assertStringContainsString('Something went wrong', $generic);
        $this->assertStringNotContainsString('boom', $generic);
    }

    public function testIsProviderAvailableReflectsBackendState(): void
    {
        // Elasticsearch is forced unreachable: closed loopback port refuses
        // instantly, so the probe is deterministic without any live service.
        $_ENV['ES_HOST'] = '127.0.0.1';
        $_ENV['ES_PORT'] = '1';
        $_ENV['ES_SCHEME'] = 'http';
        $_ENV['SKIP_EMBEDDING_VALIDATION'] = '1';
        unset($_ENV['SKIP_FILTER_CHECKS']);

        $this->assertFalse(
            isProviderAvailable('Elasticsearch'),
            'Elasticsearch must report unavailable while its backend refuses connections'
        );
        $this->assertTrue(
            isProviderAvailable('MySQL'),
            'MySQL is a live suite dependency and must report available'
        );
    }

    public function testErrorPageOffersAvailableProviders(): void
    {
        $_ENV['ES_HOST'] = '127.0.0.1';
        $_ENV['ES_PORT'] = '1';
        $_ENV['ES_SCHEME'] = 'http';
        $_ENV['SKIP_EMBEDDING_VALIDATION'] = '1';
        unset($_ENV['SKIP_FILTER_CHECKS']);

        $_SERVER['REQUEST_URI'] = '/index.php?search=aloha&searchpattern=match&provider=Elasticsearch';
        $_GET = ['search' => 'aloha', 'searchpattern' => 'match', 'provider' => 'Elasticsearch'];

        $e = new ProviderUnavailableException('Elasticsearch', "Search provider 'Elasticsearch' is unavailable: no alive nodes");
        $html = noiiolelo_error_page_html($e);

        // The page still explains which provider is down.
        $this->assertStringContainsString('Elasticsearch', $html);
        $this->assertStringContainsString('temporarily unavailable', $html);

        // The dropdown replaces the "please try again" / "back to search"
        // fallbacks as the action offered to the visitor.
        $this->assertStringNotContainsStringIgnoringCase('try again', $html);
        $this->assertStringNotContainsString('Back to search', $html);

        // ...and offers the available providers in a dropdown that keeps the
        // current page context (search term etc.) except the provider itself.
        $this->assertStringContainsString('name="provider"', $html);
        $this->assertStringContainsString('value="MySQL"', $html, 'Live MySQL provider must be offered');
        $this->assertStringNotContainsString('value="Elasticsearch"', $html, 'Down provider must not be offered');
        $this->assertStringContainsString('name="search" value="aloha"', $html, 'Current search context must be preserved');
    }
}
