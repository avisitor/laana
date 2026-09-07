<?php
/**
 * Unified script to save/index documents across different providers.
 *
 * Usage: php save.php --provider=[mysql|postgres|es|elasticsearch|os|opensearch] [options]
 *        php save.php --help
 *
 * Run `php save.php --help` for the full option list. Unknown providers exit 1
 * loudly; there is no silent fallback to MySQL.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Parse command line arguments
$options = getopt("", [
    "provider:",
    "parser:",
    "sourceid:",
    "debug",
    "verbose",
    "maxrows:",
    "force",
    "resplit",
    "local",
    "minsourceid:",
    "maxsourceid:",
    "remote:",
    "delete-existing",
    "dryrun",
    "dry-run",
    "help",
    "doclist-save::",
    "doclist-file:",
    "doclist-only"
]);

/**
 * Print the save.php usage block; an optional error message goes to STDERR
 * first. Keep the block in sync with the getopt() spec above.
 */
function saveScriptUsage(string $error = ''): void
{
    if ($error !== '') {
        fwrite(STDERR, "Error: $error\n");
    }
    echo "Usage: php save.php --provider=[mysql|postgres|es|elasticsearch|os|opensearch] [options]\n";
    echo "\n";
    echo "  --help                     Show this help and exit\n";
    echo "  --parser=KEY               Site parser key (optional when --sourceid resolves it from the source's groupname)\n";
    echo "  --sourceid=ID              Process a single source; with no --parser, the parser is resolved from the source's groupname\n";
    echo "  --minsourceid=/--maxsourceid=  Restrict processing to a source ID range\n";
    echo "  --remote=ID                Re-catalog just the metadata for this sourceid from its live page (no content fetch)\n";
    echo "  --delete-existing          Delete all existing documents for the parser's groupname before re-saving (5-second cancel window)\n";
    echo "  --dryrun                   Print what the run would do (provider, parser, document-list preview, delete/report decisions) without writing anything\n";
    echo "  --maxrows=N                Max documents (default 20000)\n";
    echo "  --force                    Re-process already-saved documents\n";
    echo "  --resplit                  Re-run sentence splitting\n";
    echo "  --local                    Use the local/queued parser variant where supported\n";
    echo "  --doclist-save[=PATH]      Save the parser's document list to JSON (default scripts/doclists/<parser>.json)\n";
    echo "  --doclist-file=PATH        Run against a previously saved document list\n";
    echo "  --doclist-only             Save the doc list and exit without fetching documents\n";
    echo "  --debug, --verbose         Output control\n";
}

/**
 * Print what a run would do without writing anything. Script-level dry run
 * only: it previews the selection, the parser and the document list; it does
 * not simulate per-document writes (that logic lives inside the managers and
 * writes to the backend as a side effect of evaluating it).
 */
function saveScriptDryRun(object $manager, array $plan): void
{
    echo "Dry run — nothing will be written (provider: {$plan['provider']}";
    if ($plan['parser']) {
        echo ", parser: {$plan['parser']}";
    }
    echo ")\n";

    if ($plan['delete-existing'] && $plan['parser']) {
        echo "Would delete all existing documents with groupname '{$plan['parser']}' (--delete-existing)\n";
    }

    if ($plan['sourceid']) {
        echo "Would process source {$plan['sourceid']} with parser '{$plan['parser']}'\n";
    } elseif ($plan['remote']) {
        echo "Would re-catalog metadata for source {$plan['remote']} (no content fetch)\n";
    } elseif ($plan['doclist-count'] !== null) {
        echo "Would process {$plan['doclist-count']} documents from the doc list";
        echo ($plan['doclist-save'] !== null) ? " and save the doc list\n" : "\n";
    } elseif ($plan['parser']) {
        $docs = $manager->getDocumentListForParser($plan['parser']);
        $count = sizeof($docs);
        $effective = min($count, (int)$plan['maxrows']);
        echo "Would fetch and process {$effective} of {$count} documents for parser '{$plan['parser']}' (maxrows {$plan['maxrows']})\n";
        foreach (array_slice($docs, 0, 10) as $i => $doc) {
            $name = $doc['sourcename'] ?? ($doc['sourceName'] ?? '?');
            $link = $doc['url'] ?? $doc['link'] ?? '';
            echo "  " . ($i + 1) . ". $name $link\n";
        }
        if ($count > 10) {
            echo "  ... and " . ($count - 10) . " more\n";
        }
        if ($plan['minsourceid'] > 0 || $plan['maxsourceid'] < PHP_INT_MAX) {
            echo "  (range filter applies: {$plan['minsourceid']}..{$plan['maxsourceid']})\n";
        }
        if ($plan['doclist-save'] !== null) {
            $path = ($plan['doclist-save'] ?? '') !== '' ? $plan['doclist-save'] : "scripts/doclists/{$plan['parser']}.json";
            echo "Would save the doc list to $path ($count entries)\n";
        }
        if ($plan['doclist-only']) {
            echo "Would stop after saving the doc list (--doclist-only)\n";
        }
    } elseif ($plan['minsourceid'] > 0 || $plan['maxsourceid'] < PHP_INT_MAX) {
        echo "Would process sources in range {$plan['minsourceid']}..{$plan['maxsourceid']} (exact set resolved by the backend at run time)\n";
    }
    echo "\n";
}

$providerName = isset($options['provider']) ? strtolower($options['provider']) : 'mysql';
$parserKey = $options['parser'] ?? null;
if (is_string($parserKey)) {
    $parserKey = strtolower(trim($parserKey));
    if ($parserKey === '') {
        $parserKey = null;
    }
}
$sourceId = $options['sourceid'] ?? null;

// getopt() yields false for a valueless --doclist-save; normalize it to ''
// so the default-path handling applies instead of fataling on an empty file
// path at write time.
$doclistSave = isset($options['doclist-save']) ? ($options['doclist-save'] ?: '') : null;
$doclistFile = $options['doclist-file'] ?? null;
$doclistOnly = isset($options['doclist-only']);

$help = isset($options['help']);
$deleteExisting = isset($options['delete-existing']);
$dryRun = isset($options['dryrun']) || isset($options['dry-run']);
$remoteId = isset($options['remote']) ? (int)$options['remote'] : 0;
$minSourceId = isset($options['minsourceid']) ? (int)$options['minsourceid'] : 0;
$maxSourceId = isset($options['maxsourceid']) ? (int)$options['maxsourceid'] : PHP_INT_MAX;

$providerLabel = $providerName;

$managerOptions = [
    'parserkey' => $parserKey,
    'sourceid' => $sourceId,
    'debug' => isset($options['debug']),
    'verbose' => isset($options['verbose']) || !isset($options['debug']), // Default to verbose if not debug
    'maxrows' => isset($options['maxrows']) ? (int)$options['maxrows'] : 20000,
    'force' => isset($options['force']),
    'resplit' => isset($options['resplit']),
    'local' => isset($options['local']),
    'minsourceid' => $minSourceId,
    'maxsourceid' => $maxSourceId,
    'remote' => $remoteId,
];

// --help must work without a valid provider or any backend connection.
if ($help) {
    saveScriptUsage();
    exit(0);
}

// A group wipe needs a groupname: --parser, or a --sourceid whose record
// resolves to one (checked again after the source lookup below).
if ($deleteExisting && !$parserKey && !$sourceId) {
    saveScriptUsage('--delete-existing requires --parser (or --sourceid to resolve the parser from the source record)');
    exit(1);
}

// --doclist-only only means something as part of a --doclist-save run;
// on its own it would be silently ignored and the run would fall through
// to full document processing.
if ($doclistOnly && $doclistSave === null) {
    saveScriptUsage('--doclist-only requires --doclist-save');
    exit(1);
}

// Something must be selected: parser, single source, source range, or the
// remote-recatalog mode. A bare run would otherwise reprocess the whole
// corpus; fail with usage instead (ported from scripts/savedocument.php).
if (!$parserKey && !$sourceId && $minSourceId === 0 && !$remoteId) {
    saveScriptUsage("Specify a parser, a --sourceid, a --minsourceid/--maxsourceid range, or --remote=ID");
    exit(1);
}

if (($doclistSave !== null || $doclistFile) && !$parserKey) {
    echo "Error: --parser is required when using doclist options\n";
    exit(1);
}

if ($doclistFile) {
    if (!file_exists($doclistFile)) {
        echo "Error: doclist file not found: $doclistFile\n";
        exit(1);
    }
    $doclistJson = file_get_contents($doclistFile);
    $doclistData = json_decode($doclistJson, true);
    if (!is_array($doclistData)) {
        echo "Error: invalid doclist JSON in $doclistFile\n";
        exit(1);
    }
        foreach ($doclistData as &$doc) {
            if (is_array($doc) && (!isset($doc['provider']) || $doc['provider'] === '')) {
                $doc['provider'] = $providerLabel;
            }
        }
        unset($doc);
    $managerOptions['documents'] = $doclistData;
}

try {
    try {
        $normalized = \Noiiolelo\SaveManagerFactory::normalize($providerName);
        $manager = \Noiiolelo\SaveManagerFactory::create($normalized, $managerOptions);
        echo "Using $normalized provider\n";
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }

    // Resolve the parser from the source's registered groupname in the
    // selected backend (ported from scripts/savedocument.php, which asked
    // the web API instead).
    if ($sourceId && !$parserKey) {
        $parserKey = $manager->resolveParserForSource((int)$sourceId);
        if (!$parserKey) {
            fwrite(STDERR, "Error: Source $sourceId not found (or has no groupname) in $normalized\n");
            exit(1);
        }
        echo "Resolved parser '$parserKey' from source $sourceId\n";
    }

    if ($deleteExisting && !$parserKey) {
        saveScriptUsage('--delete-existing requires --parser (or a resolvable --sourceid)');
        exit(1);
    }

    // Dry run: print the plan and stop before any write. Script-level only —
    // per-document simulation is not attempted (see saveScriptDryRun()).
    if ($dryRun) {
        saveScriptDryRun($manager, [
            'provider' => $normalized,
            'parser' => $parserKey,
            'delete-existing' => $deleteExisting,
            'sourceid' => $sourceId,
            'remote' => $remoteId,
            'minsourceid' => $minSourceId,
            'maxsourceid' => $maxSourceId,
            'maxrows' => $managerOptions['maxrows'],
            'doclist-save' => $doclistSave,
            'doclist-count' => (isset($doclistData) && is_array($doclistData)) ? count($doclistData) : null,
            'doclist-only' => $doclistOnly,
        ]);
        exit(0);
    }

    // Delete the parser group's existing data before re-saving (ported from
    // scripts/savedocument.php, with its 5-second cancel window).
    if ($deleteExisting) {
        echo "WARNING: About to delete all existing documents with groupname '$parserKey'\n";
        echo "Press Ctrl+C within 5 seconds to cancel...\n";
        sleep(5);

        $stats = $manager->deleteByGroupname($parserKey);
        echo "Deleted:\n";
        foreach ($stats as $key => $value) {
            echo "  " . ucfirst((string)$key) . ": {$value}\n";
        }
        echo "\n";
    }

    if ($doclistSave !== null) {
        $doclistPath = $doclistSave;
        if ($doclistPath === '' || $doclistPath === null) {
            $doclistPath = __DIR__ . '/doclists/' . $parserKey . '.json';
        }
        $doclistDir = dirname($doclistPath);
        if (!is_dir($doclistDir)) {
            mkdir($doclistDir, 0775, true);
        }
        $doclist = $manager->getDocumentListForParser($parserKey);
        if (empty($doclist)) {
            echo "Error: parser returned empty document list\n";
            exit(1);
        }
        foreach ($doclist as &$doc) {
            if (is_array($doc) && (!isset($doc['provider']) || $doc['provider'] === '')) {
                $doc['provider'] = $providerLabel;
            }
        }
        unset($doc);
        file_put_contents($doclistPath, json_encode($doclist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo "Saved document list to $doclistPath\n";
        if ($doclistOnly) {
            exit(0);
        }
    }

    if ($sourceId) {
        $summary = $manager->processOneSource($sourceId);
    } else {
        $summary = $manager->getAllDocuments();
    }

    if (is_array($summary)) {
        if (!isset($summary['provider']) || $summary['provider'] === '') {
            $summary['provider'] = $providerLabel;
        }
        echo "Summary: " . json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
