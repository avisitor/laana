<?php

namespace Noiiolelo\Tests\Provider\Neo4j;

use Noiiolelo\Providers\Neo4j\NameListEntityExtractor;

/**
 * Unit tests for the curated name-list entity extractor. These run against
 * the real lists in data/name_lists (indexed via data/entity_sources) and
 * touch no backend services.
 */
class NameListEntityExtractorTest extends \Noiiolelo\Tests\BaseTestCase
{
    private const REAL_SOURCES_FILE = __DIR__ . '/../../../data/entity_sources';
    private const REAL_LIST_DIR = __DIR__ . '/../../../data/name_lists';

    protected function setUp(): void
    {
        parent::setUp();
        // The full index (~130k records) needs headroom beyond the default.
        ini_set('memory_limit', '768M');
        NameListEntityExtractor::reset();
    }

    protected function tearDown(): void
    {
        NameListEntityExtractor::setSourcePaths(self::REAL_SOURCES_FILE, self::REAL_LIST_DIR);
        NameListEntityExtractor::reset();
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return array<string, array<string, mixed>> entity name -> entity
     */
    private static function byName(array $entities): array
    {
        $byName = [];
        foreach ($entities as $entity) {
            $byName[(string)$entity['name']] = $entity;
        }
        return $byName;
    }

    public function testDiacriticVariantsCollapseToTheSameEntity(): void
    {
        $modern = NameListEntityExtractor::extractEntities("Aia ʻo Hāloa ma Waipio.");
        $legacy = NameListEntityExtractor::extractEntities("Aia o Haloa ma Waipio.");

        $modernByName = self::byName($modern);
        $legacyByName = self::byName($legacy);

        // Same entries, same ids — only the document orthography differs.
        $this->assertSame(array_keys($modernByName), array_keys($legacyByName));
        foreach (array_keys($modernByName) as $name) {
            $this->assertSame($modernByName[$name]['id'], $legacyByName[$name]['id']);
        }

        // "Hāloa" resolves to the literary record (canonical display name
        // comes from the list, not the document text).
        $this->assertArrayHasKey('Hāloa', $modernByName);
        $this->assertSame('Person', $modernByName['Hāloa']['type']);
        $this->assertStringStartsWith('PERSON_', $modernByName['Hāloa']['id']);

        // "Waipio" is a place and a wind name; both senses stay distinct and
        // display the canonical list form (first-claimed record wins).
        $waipioTypes = array_map(
            static fn (array $e): string => $e['type'],
            array_filter($modern, static fn (array $e): bool => $e['name'] === 'Waipiʻo')
        );
        $this->assertContains('Place', $waipioTypes);
        $this->assertContains('Wind', $waipioTypes);

        // Sentence-initial "Aia" (an SSA given name) and the copula "ʻo"
        // must not leak in as entities.
        $this->assertArrayNotHasKey('Aia', $modernByName);
        $this->assertArrayNotHasKey('ma', $modernByName);
    }

    public function testAliasMatchResolvesToCoreEntity(): void
    {
        // "Aʻala-mist" is an alias of the rain record "Aʻala"; the match must
        // emit the core entity, not an alias-named node.
        $entities = NameListEntityExtractor::extractEntities("Pili ka Aʻala-mist i ka uka.");

        $this->assertCount(1, $entities);
        $this->assertSame('Aʻala', $entities[0]['name']);
        $this->assertSame('Rain', $entities[0]['type']);
        $this->assertStringStartsWith('RAIN_', $entities[0]['id']);
        $this->assertSame('rain.json', $entities[0]['source']);
        $this->assertSame('Hawaiʻi', $entities[0]['island']);
    }

    public function testLongestMatchWinsOverShorterHomonyms(): void
    {
        // "Haʻena-Kauaʻi" is the full name of a wind record; matching it must
        // consume both tokens instead of stopping at the homonymous "Haʻena"
        // place/wind entries.
        $entities = NameListEntityExtractor::extractEntities("Aia ʻo Haʻena-Kauaʻi ma Kauaʻi.");
        $names = array_column($entities, 'name');

        $this->assertContains('Haʻena-Kauaʻi', $names);
        $this->assertNotContains('Haʻena', $names);
        $this->assertNotContains('Haʻena-wind', $names);

        $winds = array_filter($entities, static fn (array $e): bool => $e['name'] === 'Haʻena-Kauaʻi');
        $wind = array_values($winds)[0];
        $this->assertSame('Wind', $wind['type']);
        $this->assertSame('Kauaʻi', $wind['island']);
    }

    public function testSentenceInitialGivenNameIsRejected(): void
    {
        // "Mele" is a given name (hawaiian_given_names + SSA) but here it is
        // a sentence-initial greeting word, not an entity mention.
        $entities = NameListEntityExtractor::extractEntities("Mele Kalikimaka me ka hauʻoli makahiki hou.");
        $this->assertSame([], $entities);
    }

    public function testCapitalizedParticlesAndCalendarWordsAreSkipped(): void
    {
        // "Ke" precedes the proper noun; "June" comes from a dateline yet is
        // also an SSA given name — neither may become an entity.
        $entities = NameListEntityExtractor::extractEntities("Ma June 1896, hele akula ʻo Kaʻahumanu.");

        $this->assertCount(1, $entities);
        $this->assertSame('Kaʻahumanu', $entities[0]['name']);
        $this->assertSame('Person', $entities[0]['type']);
    }

    public function testParticleBeforeProperNounIsNotAnEntity(): void
    {
        // Mid-sentence capitalized "Ke" (Hawaiian article) must be dropped
        // even though "ke" is an SSA given name.
        $entities = NameListEntityExtractor::extractEntities("Aia ʻo Ke Aolama kēia.");
        $names = array_column($entities, 'name');

        $this->assertNotContains('Ke', $names);
        $this->assertNotContains('Aia', $names);
    }

    public function testSsaOnlyNameWithoutSurnameContextIsRejected(): void
    {
        // "Akua" is an SSA given name and the Hawaiian word for God,
        // conventionally capitalized mid-sentence ("ke Akua") — without a
        // following capitalized token it must not become a person.
        $entities = NameListEntityExtractor::extractEntities("Ua aloha mai ke Akua iā kākou.");
        $this->assertSame([], $entities);
    }

    public function testSsaOnlyNameAtSentenceEndDoesNotBorrowTheNextSentence(): void
    {
        // Sentence-final "Akua." is followed by the next sentence's
        // capitalized first word — that must not count as surname context.
        $entities = NameListEntityExtractor::extractEntities("Ua hana mai ke Akua.\nAole lakou palapala.");
        $this->assertSame([], $entities);
    }

    public function testSsaOnlyNameInsideCapitalizedRunIsAccepted(): void
    {
        $entities = NameListEntityExtractor::extractEntities("Ua ʻike aku au iā Hannah Kobayashi.");

        $this->assertCount(1, $entities);
        $this->assertSame('hannah', $entities[0]['name']);
        $this->assertSame('Person', $entities[0]['type']);
    }

    public function testGivenNameMatchedInModernDocument(): void
    {
        $entities = NameListEntityExtractor::extractEntities("ʻO Hannah Kobayashi ka inoa o ua wahine lā.");

        $this->assertCount(1, $entities);
        $this->assertSame('hannah', $entities[0]['name']);
        $this->assertSame('Person', $entities[0]['type']);
        $this->assertStringStartsWith('PERSON_', $entities[0]['id']);
    }

    public function testCoOccurrenceRelationshipsConnectSameSentenceEntities(): void
    {
        $text = "Aia ʻo Hāloa ma Waipio.";
        $entities = NameListEntityExtractor::extractEntities($text);
        $relationships = NameListEntityExtractor::extractRelationships($text, $entities);

        $haloaId = null;
        $waipioId = null;
        foreach ($entities as $entity) {
            if ($entity['name'] === 'Hāloa' && $entity['type'] === 'Person') {
                $haloaId = $entity['id'];
            }
            if ($entity['name'] === 'Waipiʻo' && $entity['type'] === 'Place') {
                $waipioId = $entity['id'];
            }
        }
        $this->assertNotNull($haloaId);
        $this->assertNotNull($waipioId);

        $this->assertNotEmpty($relationships);
        $entityIds = array_column($entities, 'id');
        $connected = false;
        foreach ($relationships as $rel) {
            $this->assertSame('CO_OCCURS_WITH', $rel['relation']);
            $this->assertContains($rel['source'], $entityIds);
            $this->assertContains($rel['target'], $entityIds);
            $this->assertNotSame($rel['source'], $rel['target']);
            if (in_array($haloaId, [$rel['source'], $rel['target']], true)
                && in_array($waipioId, [$rel['source'], $rel['target']], true)) {
                $connected = true;
            }
        }
        $this->assertTrue($connected, 'Hāloa and Waipio share a sentence and must be connected');
    }

    public function testSsaOnlyEntryDroppedWhenCuratedListClaimsTheKey(): void
    {
        // "Hawaii" is claimed by the curated place lists; the SSA-only person
        // entry for the same key must not add a duplicate junk node.
        $entities = NameListEntityExtractor::extractEntities("Ma ka moku o Hawaii nei.");
        $people = array_filter($entities, static fn (array $e): bool => $e['type'] === 'Person');
        foreach ($people as $person) {
            $this->assertNotSame('hawaii', $person['name']);
        }
        $places = array_values(array_filter(
            $entities,
            static fn (array $e): bool => $e['name'] === 'Hawaii' && $e['type'] === 'Place'
        ));
        $this->assertCount(1, $places);
    }

    public function testMissingListFileFailsLoudly(): void
    {
        $tmp = sys_get_temp_dir() . '/nle_test_' . uniqid();
        mkdir($tmp);
        file_put_contents($tmp . '/entity_sources', "does_not_exist.json\n");
        NameListEntityExtractor::setSourcePaths($tmp . '/entity_sources', self::REAL_LIST_DIR);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Name list not found');
        NameListEntityExtractor::extractEntities('Hāloa');
    }

    public function testUnmappedListFileFailsLoudly(): void
    {
        // Real list, but deliberately absent from the label mapping: a
        // misconfigured entity_sources must abort, not silently skip.
        $tmp = sys_get_temp_dir() . '/nle_test_' . uniqid();
        mkdir($tmp);
        file_put_contents($tmp . '/entity_sources', "hawaiian_wordlist.json\n");
        NameListEntityExtractor::setSourcePaths($tmp . '/entity_sources', self::REAL_LIST_DIR);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No graph label mapping');
        NameListEntityExtractor::extractEntities('Hāloa');
    }

    public function testNonRecordSchemaListFailsLoudly(): void
    {
        // A mapped file name whose contents are not a record array must abort
        // loudly instead of indexing nothing.
        $tmp = sys_get_temp_dir() . '/nle_test_' . uniqid();
        mkdir($tmp);
        file_put_contents($tmp . '/entity_sources', "rain.json\n");
        file_put_contents($tmp . '/rain.json', '{"not": "a record array"}');
        NameListEntityExtractor::setSourcePaths($tmp . '/entity_sources', $tmp);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('canonical record schema');
        NameListEntityExtractor::extractEntities('Hāloa');
    }
}
