<?php
namespace Noiiolelo\Tests\Cli;

use PHPUnit\Framework\TestCase;

class SaveManagerFactoryTest extends TestCase
{
    public function testUnknownProviderThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Noiiolelo\SaveManagerFactory::create('bogus', []);
    }

    public function testProviderNameNormalization(): void
    {
        $this->assertSame('elasticsearch', \Noiiolelo\SaveManagerFactory::normalize('ES'));
        $this->assertSame('opensearch', \Noiiolelo\SaveManagerFactory::normalize('os'));
        $this->assertSame('postgres', \Noiiolelo\SaveManagerFactory::normalize('Postgres'));
        $this->assertSame('mysql', \Noiiolelo\SaveManagerFactory::normalize(''));
        $this->assertSame('mysql', \Noiiolelo\SaveManagerFactory::normalize('  '));
    }

    public function testSupportedList(): void
    {
        $this->assertSame(
            ['mysql', 'postgres', 'elasticsearch', 'opensearch'],
            \Noiiolelo\SaveManagerFactory::supported()
        );
    }
}
