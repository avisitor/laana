<?php

namespace Noiiolelo\Tests\Database;

require_once __DIR__ . '/../../db/funcs.php';
require_once __DIR__ . '/../../db/PostgresFuncs.php';

use Noiiolelo\Tests\BaseTestCase;
use Laana;
use PostgresLaana;

/**
 * Tests for database triggers (stats automation and simplified text normalization)
 */
class TriggerTest extends BaseTestCase
{
    private $mysql;
    private $postgres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysql = new Laana();
        $this->postgres = new PostgresLaana();
    }

    /**
     * Test MySQL simplified text normalization trigger
     */
    public function testMySQLSimplifiedNormalization()
    {
        $sourceID = $this->createTempSource($this->mysql);
        
        $hawaiianText = "O ka ‘ōlelo ka iwi o ka no‘ono‘o.";
        $expectedSimplified = "O ka olelo ka iwi o ka noonoo."; // Triggers remove okina and kahako
        
        // Insert sentence
        $sql = "INSERT INTO sentences (sourceID, hawaiianText) VALUES (:sourceID, :text)";
        $this->mysql->executePrepared($sql, ['sourceID' => $sourceID, 'text' => $hawaiianText]);
        
        $sentenceID = $this->mysql->conn->lastInsertId();
        
        // Verify simplified field
        $row = $this->mysql->getSentence($sentenceID);
        $this->assertEquals($expectedSimplified, $row['simplified'], "MySQL simplified text should be normalized on insert");
        
        // Update sentence
        $newHawaiianText = "Hana ‘i‘ini.";
        $newExpectedSimplified = "Hana iini.";
        
        $sql = "UPDATE sentences SET hawaiianText = :text WHERE sentenceID = :id";
        $this->mysql->executePrepared($sql, ['text' => $newHawaiianText, 'id' => $sentenceID]);
        
        $row = $this->mysql->getSentence($sentenceID);
        $this->assertEquals($newExpectedSimplified, $row['simplified'], "MySQL simplified text should be normalized on update");
        
        $this->cleanupTempSource($this->mysql, $sourceID);
    }

    /**
     * Test Postgres simplified text normalization trigger
     */
    public function testPostgresSimplifiedNormalization()
    {
        $sourceID = $this->createTempSource($this->postgres);
        
        $hawaiianText = "O ka ‘ōlelo ka iwi o ka no‘ono‘o.";
        $expectedSimplified = "O ka olelo ka iwi o ka noonoo.";
        
        // Insert sentence
        $sql = "INSERT INTO sentences (sourceid, hawaiiantext) VALUES (:sourceID, :text)";
        $this->postgres->executePrepared($sql, ['sourceID' => $sourceID, 'text' => $hawaiianText]);
        
        // Verify simplified field (query by sourceid and hawaiiantext since sentenceid might be null/not auto-inc)
        $sql = "SELECT * FROM sentences WHERE sourceid = :sourceID AND hawaiiantext = :text";
        $row = $this->postgres->getOneDBRow($sql, ['sourceID' => $sourceID, 'text' => $hawaiianText]);
        
        $this->assertNotEmpty($row, "Postgres sentence should be found");
        $this->assertEquals($expectedSimplified, $row['simplified'], "Postgres simplified text should be normalized on insert");
        
        // Update sentence
        $newHawaiianText = "Hana ‘i‘ini.";
        $newExpectedSimplified = "Hana iini.";
        
        $sql = "UPDATE sentences SET hawaiiantext = :text WHERE sourceid = :sourceID AND hawaiiantext = :oldText";
        $this->postgres->executePrepared($sql, ['text' => $newHawaiianText, 'sourceID' => $sourceID, 'oldText' => $hawaiianText]);
        
        $sql = "SELECT * FROM sentences WHERE sourceid = :sourceID AND hawaiiantext = :text";
        $row = $this->postgres->getOneDBRow($sql, ['sourceID' => $sourceID, 'text' => $newHawaiianText]);
        
        $this->assertNotEmpty($row, "Postgres updated sentence should be found");
        $this->assertEquals($newExpectedSimplified, $row['simplified'], "Postgres simplified text should be normalized on update");
        
        $this->cleanupTempSource($this->postgres, $sourceID);
    }

    /**
     * Test MySQL stats triggers
     */
    public function testMySQLStatsTriggers()
    {
        $initialCount = $this->mysql->getSentenceCount();
        
        $sourceID = $this->createTempSource($this->mysql);
        
        // Insert sentence
        $sql = "INSERT INTO sentences (sourceID, hawaiianText) VALUES (:sourceID, 'Test sentence')";
        $this->mysql->executePrepared($sql, ['sourceID' => $sourceID]);
        
        $newCount = $this->mysql->getSentenceCount();
        $this->assertEquals($initialCount + 1, $newCount, "MySQL stats count should increment on insert");
        
        // Delete sentence
        $this->mysql->removesentences($sourceID);
        
        $finalCount = $this->mysql->getSentenceCount();
        $this->assertEquals($initialCount, $finalCount, "MySQL stats count should decrement on delete");
        
        $this->cleanupTempSource($this->mysql, $sourceID);
    }

    /**
     * Test Postgres stats triggers
     */
    public function testPostgresStatsTriggers()
    {
        $initialCount = $this->postgres->getSentenceCount();
        
        $sourceID = $this->createTempSource($this->postgres);
        
        // Insert sentence
        $sql = "INSERT INTO sentences (sourceid, hawaiiantext) VALUES (:sourceID, 'Test sentence')";
        $this->postgres->executePrepared($sql, ['sourceID' => $sourceID]);
        
        $newCount = $this->postgres->getSentenceCount();
        $this->assertEquals($initialCount + 1, $newCount, "Postgres stats count should increment on insert");
        
        // Delete sentence
        $this->postgres->removesentences($sourceID);
        
        $finalCount = $this->postgres->getSentenceCount();
        $this->assertEquals($initialCount, $finalCount, "Postgres stats count should decrement on delete");
        
        $this->cleanupTempSource($this->postgres, $sourceID);
    }

    private function createTempSource($db)
    {
        $name = "TriggerTestTempSource_" . uniqid();
        $link = "http://example.com/" . $name;
        
        if ($db instanceof PostgresLaana) {
            // Postgres schema seems to lack auto-inc for now, provide a random ID
            $sourceID = rand(1000000, 9999999);
            $sql = "INSERT INTO sources (sourceid, sourcename, link, groupname) VALUES (:id, :name, :link, 'test')";
            $db->executePrepared($sql, ['id' => $sourceID, 'name' => $name, 'link' => $link]);
            return $sourceID;
        } else {
            $sql = "INSERT INTO sources (sourcename, link, groupname) VALUES (:name, :link, 'test')";
            $db->executePrepared($sql, ['name' => $name, 'link' => $link]);
            
            $sql = "SELECT sourceid FROM sources WHERE link = :link";
            $row = $db->getOneDBRow($sql, ['link' => $link]);
            return $row['sourceid'];
        }
    }

    private function cleanupTempSource($db, $sourceID)
    {
        $db->executePrepared("DELETE FROM sources WHERE sourceid = :id", ['id' => $sourceID]);
    }
}
