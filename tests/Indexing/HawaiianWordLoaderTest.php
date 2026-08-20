<?php

namespace Noiiolelo\Tests\Indexing;

use HawaiianSearch\HawaiianWordLoader;
use Noiiolelo\Tests\BaseTestCase;

class HawaiianWordLoaderTest extends BaseTestCase
{
    private string $wordsFile;

    protected function setUp(): void
    {
        $this->wordsFile = dirname(__DIR__, 2) . '/hawaiian_words.txt';
        if (!file_exists($this->wordsFile)) {
            $this->markTestSkipped("hawaiian_words.txt not found at {$this->wordsFile}");
        }
    }

    public function testLoadAsHashSetReturnsArray(): void
    {
        $wordSet = HawaiianWordLoader::loadAsHashSet($this->wordsFile);
        $this->assertIsArray($wordSet);
        $this->assertNotEmpty($wordSet, 'Word set should not be empty');
    }

    public function testLoadAsHashSetContainsSubstantialWords(): void
    {
        $wordSet = HawaiianWordLoader::loadAsHashSet($this->wordsFile);
        $this->assertGreaterThan(1000, count($wordSet), 'Should load a substantial number of Hawaiian words');
    }

    public function testWordsAreNormalizedLowercase(): void
    {
        $wordSet = HawaiianWordLoader::loadAsHashSet($this->wordsFile);
        foreach (array_keys($wordSet) as $word) {
            $this->assertEquals(
                strtolower($word),
                $word,
                "Word key should be lowercase: {$word}"
            );
        }
    }

    public function testWordsHaveNoMacrons(): void
    {
        $wordSet = HawaiianWordLoader::loadAsHashSet($this->wordsFile);
        $macronChars = ['ā', 'ē', 'ī', 'ō', 'ū', 'Ā', 'Ē', 'Ī', 'Ō', 'Ū'];
        foreach (array_keys($wordSet) as $word) {
            foreach ($macronChars as $macron) {
                $this->assertStringNotContainsString(
                    $macron,
                    $word,
                    "Word should not contain macron: {$word}"
                );
            }
        }
    }

    public function testWordsAreHashSetsWithTrueValues(): void
    {
        $wordSet = HawaiianWordLoader::loadAsHashSet($this->wordsFile);
        foreach ($wordSet as $key => $value) {
            $this->assertTrue($value, "Hash set value should be true for key: {$key}");
        }
    }

    public function testLoadAsHashSetWithOutputVerbose(): void
    {
        ob_start();
        $wordSet = HawaiianWordLoader::loadAsHashSetWithOutput($this->wordsFile, true);
        $output = ob_get_clean();
        $this->assertNotEmpty($output, 'Verbose mode should produce output');
        $this->assertStringContainsString('Loading Hawaiian words', $output);
    }

    public function testLoadAsHashSetWithOutputQuiet(): void
    {
        ob_start();
        $wordSet = HawaiianWordLoader::loadAsHashSetWithOutput($this->wordsFile, false);
        $output = ob_get_clean();
        $this->assertNotEmpty($wordSet);
        $this->assertEmpty($output, 'Quiet mode should produce no output');
    }

    public function testLoadAsHashSetDeterministic(): void
    {
        $wordSet1 = HawaiianWordLoader::loadAsHashSet($this->wordsFile);
        $wordSet2 = HawaiianWordLoader::loadAsHashSet($this->wordsFile);
        $this->assertEquals(
            count($wordSet1),
            count($wordSet2),
            'Word count should be deterministic across loads'
        );
    }

    public function testLoadAsHashSetMissingFile(): void
    {
        ob_start();
        $wordSet = HawaiianWordLoader::loadAsHashSet('/nonexistent/path/words.txt');
        ob_end_clean();
        $this->assertIsArray($wordSet);
        $this->assertEmpty($wordSet, 'Missing file should return empty array');
    }
}
