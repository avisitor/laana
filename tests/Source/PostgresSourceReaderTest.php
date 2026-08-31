<?php

namespace Noiiolelo\Tests\Source;

use HawaiianSearch\PostgresSourceReader;
use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../db/PostgresFuncs.php';

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

    public function testReadSourceMapsRowsToNormalizedShape(): void
    {
        $docRow = [
            'sourceid' => 1, 'sourcename' => 'N', 'groupname' => 'g',
            'authors' => 'a', 'date' => '2020-01-01', 'link' => 'l',
            'title' => 't', 'text' => 'aloha kakou', 'html' => '<p>aloha</p>',
            'embedding_1024' => '[' . implode(',', array_fill(0, 1024, '0.1')) . ']',
            'doc_ratio' => 0.9,
        ];
        $sentRows = [
            [
                'sentenceid' => 10, 'hawaiiantext' => 'aloha',
                'embedding' => '[' . implode(',', array_fill(0, 384, '0.1')) . ']',
                'sent_ratio' => 0.8, 'word_count' => 2,
                'entity_count' => 1, 'frequency' => 3, 'length' => 5,
            ],
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($docRow);
        $stmt->method('fetchAll')->willReturn($sentRows);

        $pg = $this->createMock(\PostgresLaana::class);
        $pg->conn = $this->createMock(\PDO::class);
        $pg->conn->method('prepare')->willReturn($stmt);

        $reader = new \HawaiianSearch\PostgresSourceReader($pg);
        $out = $reader->readSource(1);

        $this->assertSame(1, $out['sourceid']);
        $this->assertSame('N', $out['sourcename']);
        $this->assertSame('g', $out['groupname']);
        $this->assertSame('a', $out['authors']);
        $this->assertSame('2020-01-01', $out['date']);
        $this->assertSame('l', $out['link']);
        $this->assertSame('t', $out['title']);
        $this->assertSame('aloha kakou', $out['text']);
        $this->assertSame('<p>aloha</p>', $out['html']);
        $this->assertCount(1024, $out['text_vector_1024']);
        $this->assertSame(0.9, $out['hawaiian_word_ratio']);

        $this->assertCount(1, $out['sentences']);
        $this->assertSame('aloha', $out['sentences'][0]['text']);
        $this->assertSame(384, count($out['sentences'][0]['vector']));
        $this->assertSame(0, $out['sentences'][0]['position']);
        $this->assertSame(0.8, $out['sentences'][0]['hawaiian_word_ratio']);
        $this->assertSame(2, $out['sentences'][0]['word_count']);
        $this->assertSame(1, $out['sentences'][0]['entity_count']);
        $this->assertSame(3, $out['sentences'][0]['frequency']);
        $this->assertSame(5, $out['sentences'][0]['length']);
    }

    public function testReadSourceReturnsNullWhenNoContent(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $pg = $this->createMock(\PostgresLaana::class);
        $pg->conn = $this->createMock(\PDO::class);
        $pg->conn->method('prepare')->willReturn($stmt);

        $reader = new \HawaiianSearch\PostgresSourceReader($pg);
        $out = $reader->readSource(999);

        $this->assertNull($out);
    }

    public function testReadSourceOrdersMultipleSentencesBySentenceid(): void
    {
        $docRow = [
            'sourceid' => 5, 'sourcename' => 'M', 'groupname' => 'h',
            'authors' => 'b', 'date' => '2021-06-15', 'link' => 'k',
            'title' => 'u', 'text' => ' sentence text ', 'html' => '<p>hi</p>',
            'embedding_1024' => null,
            'doc_ratio' => null,
        ];
        $sentRows = [
            [
                'sentenceid' => 20, 'hawaiiantext' => 'second',
                'embedding' => '[' . implode(',', array_fill(0, 384, '0.2')) . ']',
                'sent_ratio' => null, 'word_count' => null,
                'entity_count' => null, 'frequency' => null, 'length' => null,
            ],
            [
                'sentenceid' => 10, 'hawaiiantext' => 'first',
                'embedding' => null,
                'sent_ratio' => 0.6, 'word_count' => 3,
                'entity_count' => 2, 'frequency' => 1, 'length' => 7,
            ],
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($docRow);
        $stmt->method('fetchAll')->willReturn($sentRows);

        $pg = $this->createMock(\PostgresLaana::class);
        $pg->conn = $this->createMock(\PDO::class);
        $pg->conn->method('prepare')->willReturn($stmt);

        $reader = new \HawaiianSearch\PostgresSourceReader($pg);
        $out = $reader->readSource(5);

        $this->assertSame(5, $out['sourceid']);
        $this->assertNull($out['text_vector_1024']);
        $this->assertNull($out['hawaiian_word_ratio']);

        $this->assertCount(2, $out['sentences']);

        $this->assertSame('first', $out['sentences'][0]['text']);
        $this->assertSame(0, $out['sentences'][0]['position']);
        $this->assertNull($out['sentences'][0]['vector']);
        $this->assertSame(0.6, $out['sentences'][0]['hawaiian_word_ratio']);

        $this->assertSame('second', $out['sentences'][1]['text']);
        $this->assertSame(1, $out['sentences'][1]['position']);
        $this->assertCount(384, $out['sentences'][1]['vector']);
        $this->assertNull($out['sentences'][1]['hawaiian_word_ratio']);
    }
}
