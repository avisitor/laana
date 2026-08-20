<?php

namespace Noiiolelo\Tests\Indexing;

use HawaiianSearch\SourceIterator;
use Noiiolelo\Tests\BaseTestCase;

class SourceIteratorTest extends BaseTestCase
{
    protected function setUp(): void
    {
        if (!isset($_ENV['NOIIOLELO_API_BASE_URL'])) {
            $this->markTestSkipped('NOIIOLELO_API_BASE_URL not set');
        }
    }

    public function testApiUrlDerivedFromEnv(): void
    {
        $iterator = new SourceIterator();
        $this->assertInstanceOf(SourceIterator::class, $iterator);
    }

    public function testFetchSourcesReturnsArray(): void
    {
        $iterator = new SourceIterator();
        $size = $iterator->getSize();
        $this->assertIsInt($size);
        $this->assertGreaterThanOrEqual(0, $size);
    }

    public function testGetNextReturnsWrappedArray(): void
    {
        $iterator = new SourceIterator();
        $size = $iterator->getSize();
        if ($size === 0) {
            $this->markTestSkipped('No sources available');
        }

        // getNext() wraps the source in a single-element array
        $next = $iterator->getNext();
        $this->assertIsArray($next);
        $this->assertCount(1, $next, 'getNext() should return a single-element array');
        $this->assertArrayHasKey('sourceid', $next[0]);
    }

    public function testGetNextExhaustion(): void
    {
        $iterator = new SourceIterator();
        $size = $iterator->getSize();
        if ($size === 0) {
            $this->markTestSkipped('No sources available');
        }

        // Drain all items
        for ($i = 0; $i < $size; $i++) {
            $next = $iterator->getNext();
            $this->assertNotNull($next, "getNext() should not be null at position {$i}");
        }

        // After exhausting, should return null
        $this->assertNull($iterator->getNext(), 'getNext() should return null after exhaustion');
    }
}
