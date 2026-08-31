<?php

namespace Noiiolelo\Tests\Source;

use HawaiianSearch\PostgresSourceReader;
use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../db/PostgresFuncs.php';

/**
 * fetchRaw(): raw HTML lookup against laana.contents for --import-raw.
 */
class PostgresRawTest extends BaseTestCase
{
    public function testFetchRawReturnsHtml(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['html' => '<p>aloha kakou</p>']);

        $pg = $this->createMock(\PostgresLaana::class);
        $pg->conn = $this->createMock(\PDO::class);
        $pg->conn->method('prepare')->willReturn($stmt);

        $reader = new PostgresSourceReader($pg);

        $this->assertSame('<p>aloha kakou</p>', $reader->fetchRaw(50260));
    }

    public function testFetchRawReturnsNullWhenNoRow(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $pg = $this->createMock(\PostgresLaana::class);
        $pg->conn = $this->createMock(\PDO::class);
        $pg->conn->method('prepare')->willReturn($stmt);

        $reader = new PostgresSourceReader($pg);

        $this->assertNull($reader->fetchRaw(999));
    }

    public function testFetchRawReturnsNullForEmptyHtml(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['html' => '']);

        $pg = $this->createMock(\PostgresLaana::class);
        $pg->conn = $this->createMock(\PDO::class);
        $pg->conn->method('prepare')->willReturn($stmt);

        $reader = new PostgresSourceReader($pg);

        $this->assertNull($reader->fetchRaw(1));
    }
}
