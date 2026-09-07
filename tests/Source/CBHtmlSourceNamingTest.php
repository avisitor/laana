<?php

namespace Noiiolelo\Tests\Source;

use Noiiolelo\Tests\BaseTestCase;

require_once __DIR__ . '/../../db/parsehtml.php';

/**
 * CBHtml (kaulanapilina / Civil Beat) source identity. Same-day articles
 * exist (the series' first date published several), so the date alone is
 * not a unique identity: the sourcename carries the article title, giving
 * one sourcename per article. Articles without an extractable title keep
 * the date-only identity.
 */
class CBHtmlSourceNamingTest extends BaseTestCase
{
    private \CBHtml $parser;

    protected function setUp(): void
    {
        $this->parser = new \CBHtml();
    }

    /**
     * Run extractMetadata over a synthetic article page — no network.
     */
    private function extract(string $innerHead, string $body = ''): array
    {
        $head = $innerHead !== '' ? "<head>{$innerHead}</head>" : '';
        $html = "<!DOCTYPE html><html>{$head}<body>{$body}</body></html>";
        ob_start();
        $this->parser->extractMetadata($this->parser->getDOMFromString($html));
        ob_end_clean();
        return $this->parser->metadata;
    }

    public function testTitledArticleGetsTitleInSourcename(): void
    {
        $meta = $this->extract(
            '<meta property="article:published_time" content="2021-04-29T06:05:00-10:00"/>',
            '<h1 class="page-title">  ʻŌlelo Nā Kamaʻāina
               No Oʻahu Komohana </h1>'
        );

        $this->assertSame('2021-04-29', $meta['date']);
        // h1 whitespace runs collapse; nothing leaks into the sourcename
        $this->assertSame('ʻŌlelo Nā Kamaʻāina No Oʻahu Komohana', $meta['title']);
        $this->assertSame(
            'Ka Ulana Pilina: 2021-04-29 ʻŌlelo Nā Kamaʻāina No Oʻahu Komohana',
            $meta['sourcename']
        );
    }

    public function testUntitledArticleKeepsDateOnlyIdentity(): void
    {
        $meta = $this->extract(
            '<meta property="article:published_time" content="2021-04-29T06:05:00-10:00"/>'
        );

        $this->assertSame('2021-04-29', $meta['date']);
        // Pre-enhancement fallback behavior for title-less pages
        $this->assertSame('Ka Ulana Pilina: 2021-04-29', $meta['title']);
        $this->assertSame('Ka Ulana Pilina: 2021-04-29', $meta['sourcename']);
    }

    public function testBuildSourceNameEdgeCases(): void
    {
        $parser = $this->parser;
        $this->assertSame(
            'Ka Ulana Pilina: 2021-04-29 He Aha Keia?',
            $parser->buildSourceName('2021-04-29', 'He Aha Keia?')
        );
        // Empty title: date-only form, no trailing artifacts
        $this->assertSame(
            'Ka Ulana Pilina: 2021-04-29',
            $parser->buildSourceName('2021-04-29', '')
        );
        // Title already equal to the date-only form: not appended twice
        $this->assertSame(
            'Ka Ulana Pilina: 2021-04-29',
            $parser->buildSourceName('2021-04-29', 'Ka Ulana Pilina: 2021-04-29')
        );
    }
}
