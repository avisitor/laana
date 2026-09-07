<?php

namespace Noiiolelo\Tests\Provider;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../lib/provider.php';

/**
 * Provider resolution precedence and the "remembered provider" cookie.
 *
 * Precedence: explicit request parameter > remembered-provider cookie >
 * .env PROVIDER > MySQL default. The cookie lets a selection made on one
 * tab (e.g. via the error page dropdown or the top-left selector) carry to
 * other tabs and later visits without threading ?provider= through links.
 */
final class ProviderResolutionTest extends BaseTestCase
{
    private array $savedRequest = [];
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedRequest = $_REQUEST;
        $_REQUEST = [];
        $this->savedEnv = $_ENV;
        $_COOKIE['noiiolelo_provider'] = null;
        unset($_COOKIE['noiiolelo_provider']);
    }

    protected function tearDown(): void
    {
        $_REQUEST = $this->savedRequest;
        $_ENV = $this->savedEnv;
        unset($_COOKIE['noiiolelo_provider']);
        parent::tearDown();
    }

    public function testRequestParameterBeatsCookie(): void
    {
        $_COOKIE['noiiolelo_provider'] = 'Postgres';
        $_REQUEST['provider'] = 'MySQL';
        $this->assertSame('MySQL', resolveProviderName());
    }

    public function testCookieBeatsEnvDefault(): void
    {
        $_COOKIE['noiiolelo_provider'] = 'Postgres';
        $this->assertSame('Postgres', resolveProviderName());
    }

    public function testUnknownCookieValueFallsBackToMySQL(): void
    {
        $_COOKIE['noiiolelo_provider'] = 'NotARealProvider';
        $this->assertSame('MySQL', resolveProviderName());
    }

    public function testResolutionIsCaseInsensitiveAndMapsLaana(): void
    {
        $this->assertSame('Elasticsearch', resolveProviderName('elasticsearch'));
        $this->assertSame('MySQL', resolveProviderName('Laana'));
        $this->assertSame('OpenSearch', resolveProviderName('OS'));
    }

    public function testExplicitProviderArgumentIgnoresCookie(): void
    {
        $this->skipIfProviderUnavailable('MySQL');
        $_COOKIE['noiiolelo_provider'] = 'Postgres';
        $provider = getProvider('MySQL');
        $this->assertSame('MySQL', $provider->getName());
    }

    /**
     * PostgresLaana::connect() swallows connection failures into a null PDO,
     * so constructing the provider is NOT proof the backend is reachable.
     * The availability probe must look at the connection itself — test
     * guards rely on this to skip (not error) when Postgres is down.
     */
    public function testAvailabilityProbeDetectsDeadPostgresConnection(): void
    {
        $_ENV['PG_HOST'] = '127.0.0.1';
        $_ENV['PG_PORT'] = '1';
        $_ENV['PG_DATABASE'] = 'noiiolelo';
        $_ENV['PG_USER'] = 'nobody';
        $_ENV['PG_PASSWORD'] = 'nothing';

        $this->assertFalse(isProviderAvailable('Postgres'));
    }
}
