<?php
namespace Noiiolelo\Tests\Minimal;

use PHPUnit\Framework\TestCase;

class MinimalTest extends TestCase
{
    public function testSimpleAssertion(): void
    {
        $this->assertTrue(true);
    }
    
    public function testAnotherAssertion(): void
    {
        $this->assertEquals(2, 1 + 1);
    }
}
