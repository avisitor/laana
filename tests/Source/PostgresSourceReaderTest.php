<?php

namespace Noiiolelo\Tests\Source;

use HawaiianSearch\PostgresSourceReader;
use Noiiolelo\Tests\BaseTestCase;

class PostgresSourceReaderTest extends BaseTestCase
{
    public function testPgvectorToArrayParsesBracketsAndSpaces(): void
    {
        $result = PostgresSourceReader::pgvectorToArray('[0.1, 0.2, 0.3]');

        $this->assertSame([0.1, 0.2, 0.3], $result);
    }

    public function testPgvectorToArrayEmpty(): void
    {
        $this->assertSame([], PostgresSourceReader::pgvectorToArray('[]'));
        $this->assertSame([], PostgresSourceReader::pgvectorToArray(null));
        $this->assertSame([], PostgresSourceReader::pgvectorToArray(''));
    }

    public function testPgvectorToArray1024Dims(): void
    {
        $values = array_fill(0, 1024, '0.5');
        $vectorString = '[' . implode(', ', $values) . ']';

        $result = PostgresSourceReader::pgvectorToArray($vectorString);

        $this->assertCount(1024, $result);
        foreach ($result as $value) {
            $this->assertSame(0.5, $value);
        }
    }

    public function testPgvectorToArraySingleElement(): void
    {
        $result = PostgresSourceReader::pgvectorToArray('[0.5]');

        $this->assertSame([0.5], $result);
    }

    public function testPgvectorToArrayNegativeValues(): void
    {
        $result = PostgresSourceReader::pgvectorToArray('[-0.1, -0.2]');

        $this->assertSame([-0.1, -0.2], $result);
    }
}
