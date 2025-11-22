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
    'check-orphans',
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
    echo "  --check-orphans        Check for orphaned records (slow, can take minutes for large datasets)\n";
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

// Validate required options (parser not needed for --check-orphans or --sourceid)
if (!isset($options['parser']) && !isset($options['check-orphans']) && !isset($options['sourceid'])) {
    echo "ERROR: --parser is required (unless using --sourceid or --check-orphans)\n";
    echo "Run with --help for usage information\n";
    exit(1);
}

global $parsermap;

// If sourceid is specified but no parser, look up the parser from Elasticsearch
if (isset($options['sourceid']) && !isset($options['parser'])) {
    $tempClient = new HawaiianSearch\ElasticsearchClient(['SPLIT_INDICES' => true]);
    $sourceMetadata = $tempClient->getSourceById($options['sourceid']);
    
    if (!$sourceMetadata) {
        echo "ERROR: Source {$options['sourceid']} not found in Elasticsearch\n";
        exit(1);
    }
    
    $options['parser'] = $sourceMetadata['groupname'];
    echo "Found source {$options['sourceid']} with parser: {$options['parser']}\n";
}

// Validate that parser exists (if parser was provided)
if (isset($options['parser']) && !isset($parsermap[$options['parser']])) {
    echo "ERROR: Unknown parser '{$options['parser']}'\n";
    echo "Available parsers: " . implode(', ', array_keys($parsermap)) . "\n";
    exit(1);
}

// Prepare options for ElasticsearchSaveManager
$managerOptions = [
    'parserkey' => $options['parser'] ?? null,
    'debug' => isset($options['debug']),
    'force' => isset($options['force']),
    'verbose' => true, // Always show output by default
    'maxrows' => isset($options['maxrows']) ? (int)$options['maxrows'] : 20000,
    'sourceid' => isset($options['sourceid']) ? (int)$options['sourceid'] : 0,
    'minsourceid' => isset($options['minsourceid']) ? (int)$options['minsourceid'] : 0,
    'maxsourceid' => isset($options['maxsourceid']) ? (int)$options['maxsourceid'] : PHP_INT_MAX,
    'check-orphans' => isset($options['check-orphans']),
    'sources' => [] // Will be populated as we process
];

try {
    // Initialize manager upfront
    $manager = new ElasticsearchSaveManager($managerOptions);
    
    // Check if we're in reindex-failed mode
    if (isset($options['reindex-failed'])) {
        reindexFailedDocuments($manager, $options);
    } else {
        indexDocuments($manager, $options);
    }
} catch (Exception $e) {
    if (isset($manager)) {
        $manager->outLine("");
        $manager->outLine("✗ Error: " . $e->getMessage());
        $manager->outLine($e->getTraceAsString());
    } else {
        echo "\n✗ Error: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
    exit(1);
}

/**
 * Index documents from source (normal mode)
 */
function indexDocuments($manager, $options) {
    // Check for integrity issues and prompt to fix
    $integrityReport = $manager->getIntegrityReport();
    if ($integrityReport && ($integrityReport['status'] === 'warning' || $integrityReport['status'] === 'error')) {
        $hasOrphans = !empty($integrityReport['orphaned_documents']) ||
                     !empty($integrityReport['orphaned_sentences']) ||
                     !empty($integrityReport['empty_metadata']);
        
        if ($hasOrphans) {
            $manager->out("⚠️  Integrity issues detected. Fix these issues before proceeding? (y/n): ");
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            fclose($handle);
            
            if (trim(strtolower($line)) === 'y') {
                $manager->fixIntegrityIssues();
                $manager->outLine("");
            }
        }
    }
    
    // Delete existing documents if requested
    if (isset($options['delete-existing'])) {
        $groupname = $options['parser'];
        $manager->outLine("WARNING: About to delete all existing documents with groupname '$groupname'");
        $manager->outLine("Press Ctrl+C within 5 seconds to cancel...");
        sleep(5);
        
        $stats = $manager->deleteByGroupname($groupname);
        $manager->outLine("Deleted:");
        $manager->outLine("  Documents: {$stats['documents']}");
        $manager->outLine("  Sentences: {$stats['sentences']}");
        $manager->outLine("  Metadata: {$stats['metadata']}");
        $manager->outLine("");
    }
    
    // Process documents (skip if we only want to check orphans without a parser)
    $maxrows = $manager->getMaxrows();
    $checkOrphansOnly = isset($options['check-orphans']) && !isset($options['parser']);
    
    if (!$checkOrphansOnly && ($maxrows > 0 || !isset($options['check-orphans']))) {
        $manager->outLine("Starting document processing...");
        $manager->outLine("");
        $manager->getAllDocuments();
        $manager->outLine("");
        $manager->outLine("✓ Indexing complete!");
    } else if ($checkOrphansOnly) {
        $manager->outLine("");
        $manager->outLine("✓ Integrity check complete!");
    } else {
        $manager->outLine("");
        $manager->outLine("✓ Integrity check complete!");
    }
}

/**
 * Reindex failed documents (documents with raw content but no sentences)
 */
function reindexFailedDocuments($manager, $options) {
    global $parsermap;
    
    $parserFilter = $options['parser'];
    $dryrun = isset($options['dryrun']);
    $limit = isset($options['limit']) ? intval($options['limit']) : null;
    
    $manager->outLine("=== Finding Failed Documents ===");
    $manager->outLine("Parser filter: $parserFilter");
    if ($dryrun) {
        $manager->outLine("DRY RUN MODE - no changes will be made");
    }
    if (!empty($limit)) {
        $manager->outLine("Limit: $limit sources");
    }
    $manager->outLine("");
    
    // Get Elasticsearch client from manager
    $client = $manager->getClient();
    
    // Get all sources from metadata for this parser/groupname
    $manager->outLine("Scanning source metadata...");
    $allSources = $client->getAllSources($parserFilter);
    
    if (empty($allSources)) {
        $manager->outLine("ERROR: No sources found in metadata for parser '$parserFilter'");
        exit(1);
    }
    
    $manager->outLine("Found " . count($allSources) . " sources in metadata");
    
    // Check each source for issues
    $manager->outLine("");
    $manager->outLine("Checking each source for issues...");
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
                $manager->out("  Checked $checked sources, found $hasIssues with issues...\r");
            }
        }
        
        $checked++;
        
        if ($limit && $hasIssues >= $limit) {
            $manager->outLine("");
            $manager->outLine("Reached limit of $limit sources with issues");
            break;
        }
    }
    
    $manager->outLine("");
    $manager->outLine("Checked $checked sources, found $hasIssues with issues");
    if ($noHawaiianContent > 0) {
        $manager->outLine("  (Plus $noHawaiianContent sources with no Hawaiian content - not counted as issues)");
    }
    $manager->outLine("");
    
    if (empty($failedSources)) {
        $manager->outLine("No failed sources found!");
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
    
    $manager->outLine("=== Issues Found ===");
    foreach ($issueTypes as $type => $count) {
        $desc = match($type) {
            'sentences_no_doc' => 'Sentences but no document (UTF-8 failures)',
            'raw_no_sentences' => 'Raw content but no sentences',
            'no_content' => 'No content at all',
            default => $type
        };
        $manager->outLine("  $desc: $count");
    }
    $manager->outLine("");
    
    // Show first 10 examples
    $manager->outLine("=== Examples (first 10) ===");
    foreach (array_slice($failedSources, 0, 10) as $source) {
        $manager->outLine("  [{$source['sourceid']}] {$source['sourcename']}");
        $manager->outLine("    Issue: {$source['issue_desc']}");
    }
    if (count($failedSources) > 10) {
        $manager->outLine("  ... and " . (count($failedSources) - 10) . " more");
    }
    $manager->outLine("");
    
    if ($dryrun) {
        $manager->outLine("DRY RUN - would reindex " . count($failedSources) . " sources");
        $manager->outLine("Run without --dryrun to actually reindex them");
        exit(0);
    }
    
    // Reindex failed sources
    $manager->outLine("=== Reindexing Failed Sources ===");
    $confirm = readline("Reindex " . count($failedSources) . " sources? (yes/no): ");
    if (strtolower(trim($confirm)) !== 'yes') {
        $manager->outLine("Cancelled");
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
    
    $manager->outLine("");
    $manager->outLine("=== Sources by Parser ===");
    foreach ($sourcesByParser as $gname => $sources) {
        $manager->outLine("  $gname: " . count($sources) . " sources");
    }
    $manager->outLine("");
    
    $indexed = 0;
    $skipped = 0;
    $errors = 0;
    
    // Process each parser group separately
    foreach ($sourcesByParser as $groupname => $sources) {
        // Get the correct parser for this groupname
        $parser = $parsermap[$groupname] ?? null;
        if (!$parser) {
            $manager->outLine("ERROR: No parser found for groupname '$groupname', skipping " . count($sources) . " sources");
            $skipped += count($sources);
            continue;
        }
        
        $manager->outLine("");
        $manager->outLine("--- Processing " . count($sources) . " sources with parser '$groupname' ---");
        
        // Build sources array for this parser
        $parserSources = [];
        foreach ($sources as $source) {
            $parserSources[$source['sourceid']] = $source;
        }
        
        // Create parser-specific manager for this group
        $parserManagerOptions = [
            'parserkey' => $groupname,
            'force' => true,  // Force reprocessing
            'verbose' => false,
            'sources' => $parserSources
        ];
        
        $parserManager = new ElasticsearchSaveManager($parserManagerOptions);
        
        foreach ($sources as $i => $source) {
            $sourceID = $source['sourceid'];
            $sourceName = $source['sourcename'];
            
            $parserManager->out("[" . ($i + 1) . "/" . count($sources) . "] Reindexing $sourceName (ID: $sourceID)... ");
            
            try {
                $count = $parserManager->saveContents($parser, $sourceID);
                
                if ($count > 0) {
                    $parserManager->outLine("SUCCESS ($count sentences)");
                    $indexed++;
                } else {
                    $parserManager->outLine("SKIP (no sentences)");
                    $skipped++;
                }
            } catch (Exception $e) {
                $parserManager->outLine("ERROR: " . $e->getMessage());
                $errors++;
            }
            
            usleep(100000); // 100ms delay
        }
    }
    
    $manager->outLine("");
    $manager->outLine("=== Reindexing Summary ===");
    $manager->outLine("Total sources: " . count($failedSources));
    $manager->outLine("Indexed: $indexed");
    $manager->outLine("Skipped: $skipped");
    $manager->outLine("Errors: $errors");
}
?>
