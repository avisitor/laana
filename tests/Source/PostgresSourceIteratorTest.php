<?php

namespace Noiiolelo\Tests\Source;

use HawaiianSearch\PostgresSourceIterator;
use Noiiolelo\Tests\BaseTestCase;
use PDO;
use PDOStatement;

class PostgresSourceIteratorTest extends BaseTestCase
{
    private function makeRow(int $id, string $group = 'default'): array
    {
        return [
            'sourceid'   => $id,
            'sourcename' => "Source {$id}",
            'groupname'  => $group,
            'authors'    => "Author {$id}",
            'date'       => "2024-01-0{$id}",
            'link'       => "https://example.com/{$id}",
            'title'      => "Title {$id}",
        ];
    }

    private function buildMockClient(array $rows): object
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $client = new \stdClass();
        $client->conn = $pdo;

        return $client;
    }

    public function testGetSizeAndNextYieldsSources(): void
    {
        $rows = [
            $this->makeRow(1, 'alpha'),
            $this->makeRow(2, 'beta'),
            $this->makeRow(3, 'gamma'),
        ];

        $client = $this->buildMockClient($rows);
        $iterator = new PostgresSourceIterator(null, null, $client);

        $this->assertSame(3, $iterator->getSize());

        // First three calls return sources in order
        $first = $iterator->getNext();
        $this->assertIsArray($first);
        $this->assertCount(1, $first);
        $this->assertSame(1, $first[0]['sourceid']);

        $second = $iterator->getNext();
        $this->assertSame(2, $second[0]['sourceid']);

        $third = $iterator->getNext();
        $this->assertSame(3, $third[0]['sourceid']);

        // Exhausted
        $this->assertNull($iterator->getNext());
    }

    public function testGroupNameFilter(): void
    {
        $rows = [
            $this->makeRow(10, 'kauakukalahale'),
            $this->makeRow(11, 'kauakukalahale'),
        ];

        $client = $this->buildMockClient($rows);
        $iterator = new PostgresSourceIterator(null, 'kauakukalahale', $client);

        $this->assertSame(2, $iterator->getSize());

        $next = $iterator->getNext();
        $this->assertSame('kauakukalahale', $next[0]['groupname']);
        $this->assertSame(10, $next[0]['sourceid']);

        $next2 = $iterator->getNext();
        $this->assertSame('kauakukalahale', $next2[0]['groupname']);
        $this->assertSame(11, $next2[0]['sourceid']);

        $this->assertNull($iterator->getNext());
    }

    public function testSourceIdFilter(): void
    {
        $rows = [
            $this->makeRow(42, 'solo'),
        ];

        $client = $this->buildMockClient($rows);
        $iterator = new PostgresSourceIterator(42, null, $client);

        $this->assertSame(1, $iterator->getSize());

        $next = $iterator->getNext();
        $this->assertSame(42, $next[0]['sourceid']);
        $this->assertSame('solo', $next[0]['groupname']);

        $this->assertNull($iterator->getNext());
    }
}
