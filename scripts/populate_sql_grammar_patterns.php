<?php
/**
 * Script to populate sentence_patterns table for existing sentences in SQL.
 * 
 * Usage: php populate_sql_grammar_patterns.php [--force] [--sourceid=ID]
 */

$dir = dirname(__DIR__, 1);
require_once $dir . '/db/funcs.php';
require_once $dir . '/db/PostgresFuncs.php';
require_once $dir . '/db/parsehtml.php';
require_once $dir . '/lib/GrammarScanner.php';

// Parse command line arguments
$options = getopt("", ["force", "sourceid:", "provider:"]);
$force = isset($options['force']);
$targetSourceId = isset($options['sourceid']) ? (int)$options['sourceid'] : null;
$provider = isset($options['provider']) ? strtolower($options['provider']) : 'mysql';

if ($provider === 'postgres' || $provider === 'pgsql') {
    echo "Using Postgres provider.\n";
    $laana = new PostgresLaana();
    // Ensure search path is set for the session
    $laana->executePrepared("SET search_path TO laana, public");
} else {
    echo "Using MySQL provider.\n";
    $laana = new Laana();
}

$scanner = new \Noiiolelo\GrammarScanner($laana);

if ($force) {
    echo "Force mode enabled: existing patterns will be overwritten.\n";
} else {
    echo "Idempotent mode: only sentences without patterns will be processed.\n";
}

echo "Fetching source IDs...\n";
if ($targetSourceId) {
    $sql = "SELECT sourceid, sourcename FROM sources WHERE sourceid = :sourceid";
    $sources = $laana->getDBRows($sql, ['sourceid' => $targetSourceId]);
} else {
    $sql = "SELECT sourceid, sourcename FROM sources ORDER BY sourceid";
    $sources = $laana->getDBRows($sql);
}

$totalSources = count($sources);
echo "Found $totalSources sources.\n";

$totalProcessed = 0;
foreach ($sources as $index => $source) {
    $sourceID = $source['sourceid'];
    $sourceName = $source['sourcename'];
    
    echo sprintf("[%d/%d] Processing SourceID %d: %s... ", $index + 1, $totalSources, $sourceID, substr($sourceName, 0, 50));
    
    $count = $scanner->updateSourcePatterns($sourceID, $force);
    $totalProcessed += $count;
    
    echo "Processed $count sentences.\n";
}

echo "\nDone! Total sentences processed: $totalProcessed\n";
