#!/usr/bin/env php
<?php
/**
 * Save documents to Elasticsearch
 * 
 * This script provides two main modes:
 * 1. Index new documents from source (like parsehtml.php)
 * 2. Reindex failed documents that have issues
 * 
 * Usage examples:
 *   # Index first 10 documents from nupepa
 *   php saveelastic.php --parser=nupepa --maxrows=10
 * 
 *   # Force re-index existing documents
 *   php saveelastic.php --parser=nupepa --force --maxrows=10
 * 
 *   # Reindex failed documents (raw content but no sentences)
 *   php saveelastic.php --parser=kauakukalahale --reindex-failed
 * 
 *   # Dry run to see what would be reindexed
 *   php saveelastic.php --parser=kauakukalahale --reindex-failed --dryrun --limit=50
 * 
 *   # Delete existing group and re-index
 *   php saveelastic.php --parser=nupepa --delete-existing --maxrows=50
 */

require_once __DIR__ . '/../../elasticsearch/vendor/autoload.php';
require_once __DIR__ . '/../../elasticsearch/php/src/ElasticsearchClient.php';
require_once __DIR__ . '/ElasticsearchSaveManager.php';
require_once __DIR__ . '/parsers.php';

use HawaiianSearch\ElasticsearchClient;

// Parse command-line arguments
$options = getopt('', [
    'parser:',
    'debug',
    'force',
    'maxrows:',
    'sourceid:',
    'minsourceid:',
    'maxsourceid:',
    'delete-existing',
    'reindex-failed',
    'dryrun',
    'limit:',
    'help'
]);

if (isset($options['help'])) {
    echo "Usage: php saveelastic.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --parser=NAME          Parser/groupname to use (e.g., nupepa, kauakukalahale)\n";
    echo "  --debug                Enable debug output\n";
    echo "  --force                Force re-indexing of existing documents\n";
    echo "  --maxrows=N            Maximum number of documents to process (default: 20000)\n";
    echo "  --sourceid=ID          Process only this specific source ID\n";
    echo "  --minsourceid=ID       Minimum source ID to process\n";
    echo "  --maxsourceid=ID       Maximum source ID to process\n";
    echo "  --delete-existing      Delete existing documents with this groupname first\n";
    echo "  --reindex-failed       Find and reindex failed documents (raw content but no sentences)\n";
    echo "  --dryrun               Show what would be done without actually doing it (for --reindex-failed)\n";
    echo "  --limit=N              Maximum number of failed sources to reindex (for --reindex-failed)\n";
    echo "  --help                 Show this help message\n\n";
    echo "Available parsers:\n";
    global $parsermap;
    foreach (array_keys($parsermap) as $key) {
        echo "  - $key\n";
    }
    exit(0);
}

// Validate required options
if (!isset($options['parser'])) {
    echo "ERROR: --parser is required\n";
    echo "Run with --help for usage information\n";
    exit(1);
}

global $parsermap;

// Validate that parser exists
if (!isset($parsermap[$options['parser']])) {
    echo "ERROR: Unknown parser '{$options['parser']}'\n";
    echo "Available parsers: " . implode(', ', array_keys($parsermap)) . "\n";
    exit(1);
}

// Check if we're in reindex-failed mode
if (isset($options['reindex-failed'])) {
    reindexFailedDocuments($options);
} else {
    indexDocuments($options);
}

/**
 * Index documents from source (normal mode)
 */
function indexDocuments($options) {
    // Prepare options for ElasticsearchSaveManager
    $managerOptions = [
        'parserkey' => $options['parser'],
        'debug' => isset($options['debug']),
        'force' => isset($options['force']),
        'verbose' => isset($options['debug']),
        'maxrows' => isset($options['maxrows']) ? (int)$options['maxrows'] : 20000,
        'sourceid' => isset($options['sourceid']) ? (int)$options['sourceid'] : 0,
        'minsourceid' => isset($options['minsourceid']) ? (int)$options['minsourceid'] : 0,
        'maxsourceid' => isset($options['maxsourceid']) ? (int)$options['maxsourceid'] : PHP_INT_MAX,
        'sources' => [] // Will be populated as we process
    ];

    try {
        // Initialize manager
        $manager = new ElasticsearchSaveManager($managerOptions);
        
        // Delete existing documents if requested
        if (isset($options['delete-existing'])) {
            $groupname = $options['parser'];
            echo "WARNING: About to delete all existing documents with groupname '$groupname'\n";
            echo "Press Ctrl+C within 5 seconds to cancel...\n";
            sleep(5);
            
            $stats = $manager->deleteByGroupname($groupname);
            echo "Deleted:\n";
            echo "  Documents: {$stats['documents']}\n";
            echo "  Sentences: {$stats['sentences']}\n";
            echo "  Metadata: {$stats['metadata']}\n\n";
        }
        
        // Process documents
        echo "Starting document processing...\n\n";
        $manager->getAllDocuments();
        
        echo "\n✓ Indexing complete!\n";
        
    } catch (Exception $e) {
        echo "\n✗ Error: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
        exit(1);
    }
}

/**
 * Reindex failed documents (documents with raw content but no sentences)
 */
function reindexFailedDocuments($options) {
    global $parsermap;
    
    $parserFilter = $options['parser'];
    $dryrun = isset($options['dryrun']);
    $limit = isset($options['limit']) ? intval($options['limit']) : null;
    
    echo "=== Finding Failed Documents ===\n";
    echo "Parser filter: $parserFilter\n";
    if ($dryrun) {
        echo "DRY RUN MODE - no changes will be made\n";
    }
    if (!empty($limit)) {
        echo "Limit: $limit sources\n";
    }
    echo "\n";
    
    // Initialize Elasticsearch client
    $client = new ElasticsearchClient(['SPLIT_INDICES' => true]);
    
    // Get all sources from metadata for this parser/groupname
    echo "Scanning source metadata...\n";
    $allSources = $client->getAllSources($parserFilter);
    
    if (empty($allSources)) {
        echo "ERROR: No sources found in metadata for parser '$parserFilter'\n";
        exit(1);
    }
    
    echo "Found " . count($allSources) . " sources in metadata\n";
    
    // Check each source for issues
    echo "\nChecking each source for issues...\n";
    $checked = 0;
    $hasIssues = 0;
    $noHawaiianContent = 0;
    $failedSources = [];
    
    foreach ($allSources as $source) {
        $sourceID = $source['sourceid'];
        $sourceName = $source['sourcename'] ?? 'Unknown';
        
        // Check if document exists
        try {
            $doc = $client->getDocumentOutline($sourceID);
            $hasDoc = ($doc !== null);
        } catch (Exception $e) {
            $hasDoc = false;
        }
        
        // Check if sentences exist
        try {
            $sentencesResult = $client->getSentencesBySourceID($sourceID);
            $sentenceCount = $sentencesResult && isset($sentencesResult['sentences']) ? count($sentencesResult['sentences']) : 0;
        } catch (Exception $e) {
            $sentenceCount = 0;
        }
        
        // Check if raw content exists
        // null = never retrieved, "" = retrieved but no Hawaiian content, non-empty = has content
        try {
            $raw = $client->getDocumentRaw($sourceID);
            $hasRaw = ($raw !== null && $raw !== '');
            $wasAttempted = ($raw !== null); // null means never tried, "" or content means was attempted
        } catch (Exception $e) {
            $hasRaw = false;
            $wasAttempted = false;
        }
        
        // Determine if this source has issues
        $issue = null;
        if ($sentenceCount > 0 && !$hasDoc) {
            $issue = "sentences_no_doc";
            $issueDesc = "Has $sentenceCount sentences but no document";
        } elseif ($hasRaw && $sentenceCount == 0) {
            $issue = "raw_no_sentences";
            $issueDesc = "Has raw content but no sentences";
        } elseif ($wasAttempted && !$hasRaw && !$hasDoc && $sentenceCount == 0) {
            // This is expected: source was processed but had no Hawaiian content
            // Track for informational purposes but don't treat as "failed"
            $noHawaiianContent++;
            $issue = null; // Clear issue so it's not added to failedSources
        } elseif (!$wasAttempted && !$hasDoc && $sentenceCount == 0) {
            $issue = "not_retrieved";
            $issueDesc = "Not yet retrieved (metadata only)";
        }
        
        // Only add to failedSources if it's actually a problem
        if ($issue) {
            $failedSources[] = [
                'sourceid' => $sourceID,
                'sourcename' => $sourceName,
                'groupname' => $source['groupname'] ?? '',
                'link' => $source['link'] ?? '',
                'title' => $source['title'] ?? '',
                'authors' => $source['authors'] ?? '',
                'date' => $source['date'] ?? '',
                'issue' => $issue,
                'issue_desc' => $issueDesc,
                'sentence_count' => $sentenceCount,
                'has_doc' => $hasDoc,
                'has_raw' => $hasRaw
            ];
            $hasIssues++;
            
            if ($checked % 50 == 0) {
                echo "  Checked $checked sources, found $hasIssues with issues...\r";
            }
        }
        
        $checked++;
        
        if ($limit && $hasIssues >= $limit) {
            echo "\nReached limit of $limit sources with issues\n";
            break;
        }
    }
    
    echo "\nChecked $checked sources, found $hasIssues with issues\n";
    if ($noHawaiianContent > 0) {
        echo "  (Plus $noHawaiianContent sources with no Hawaiian content - not counted as issues)\n";
    }
    echo "\n";
    
    if (empty($failedSources)) {
        echo "No failed sources found!\n";
        exit(0);
    }
    
    // Show summary by issue type
    $issueTypes = [];
    foreach ($failedSources as $source) {
        $type = $source['issue'];
        if (!isset($issueTypes[$type])) {
            $issueTypes[$type] = 0;
        }
        $issueTypes[$type]++;
    }
    
    echo "=== Issues Found ===\n";
    foreach ($issueTypes as $type => $count) {
        $desc = match($type) {
            'sentences_no_doc' => 'Sentences but no document (UTF-8 failures)',
            'raw_no_sentences' => 'Raw content but no sentences',
            'no_content' => 'No content at all',
            default => $type
        };
        echo "  $desc: $count\n";
    }
    echo "\n";
    
    // Show first 10 examples
    echo "=== Examples (first 10) ===\n";
    foreach (array_slice($failedSources, 0, 10) as $source) {
        echo "  [{$source['sourceid']}] {$source['sourcename']}\n";
        echo "    Issue: {$source['issue_desc']}\n";
    }
    if (count($failedSources) > 10) {
        echo "  ... and " . (count($failedSources) - 10) . " more\n";
    }
    echo "\n";
    
    if ($dryrun) {
        echo "DRY RUN - would reindex " . count($failedSources) . " sources\n";
        echo "Run without --dryrun to actually reindex them\n";
        exit(0);
    }
    
    // Reindex failed sources
    echo "=== Reindexing Failed Sources ===\n";
    $confirm = readline("Reindex " . count($failedSources) . " sources? (yes/no): ");
    if (strtolower(trim($confirm)) !== 'yes') {
        echo "Cancelled\n";
        exit(0);
    }
    
    // Group sources by parser
    $sourcesByParser = [];
    foreach ($failedSources as $source) {
        $groupname = $source['groupname'];
        if (!isset($sourcesByParser[$groupname])) {
            $sourcesByParser[$groupname] = [];
        }
        $sourcesByParser[$groupname][] = $source;
    }
    
    echo "\n=== Sources by Parser ===\n";
    foreach ($sourcesByParser as $gname => $sources) {
        echo "  $gname: " . count($sources) . " sources\n";
    }
    echo "\n";
    
    $indexed = 0;
    $skipped = 0;
    $errors = 0;
    
    // Process each parser group separately
    foreach ($sourcesByParser as $groupname => $sources) {
        // Get the correct parser for this groupname
        $parser = $parsermap[$groupname] ?? null;
        if (!$parser) {
            echo "ERROR: No parser found for groupname '$groupname', skipping " . count($sources) . " sources\n";
            $skipped += count($sources);
            continue;
        }
        
        echo "\n--- Processing " . count($sources) . " sources with parser '$groupname' ---\n";
        
        // Build sources array for this parser
        $parserSources = [];
        foreach ($sources as $source) {
            $parserSources[$source['sourceid']] = $source;
        }
        
        // Create manager for this parser
        $managerOptions = [
            'parserkey' => $groupname,
            'force' => true,  // Force reprocessing
            'verbose' => false,
            'sources' => $parserSources
        ];
        
        $manager = new ElasticsearchSaveManager($managerOptions);
        
        foreach ($sources as $i => $source) {
            $sourceID = $source['sourceid'];
            $sourceName = $source['sourcename'];
            
            echo "[" . ($i + 1) . "/" . count($sources) . "] Reindexing $sourceName (ID: $sourceID)... ";
            
            try {
                $count = $manager->saveContents($parser, $sourceID);
                
                if ($count > 0) {
                    echo "SUCCESS ($count sentences)\n";
                    $indexed++;
                } else {
                    echo "SKIP (no sentences)\n";
                    $skipped++;
                }
            } catch (Exception $e) {
                echo "ERROR: " . $e->getMessage() . "\n";
                $errors++;
            }
            
            usleep(100000); // 100ms delay
        }
    }
    
    echo "\n=== Reindexing Summary ===\n";
    echo "Total sources: " . count($failedSources) . "\n";
    echo "Indexed: $indexed\n";
    echo "Skipped: $skipped\n";
    echo "Errors: $errors\n";
}
?>
