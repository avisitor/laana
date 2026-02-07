<?php
/**
 * Unified script to save/index documents across different providers.
 * 
 * Usage: php save.php --provider=[es|mysql] [--parser=KEY] [--sourceid=ID] [--debug] [--verbose] [--maxrows=N]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Noiiolelo\Providers\MySQL\MySQLSaveManager;
use Noiiolelo\Providers\Elasticsearch\ElasticsearchSaveManager;

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
    "doclist-save::",
    "doclist-file:",
    "doclist-only"
]);

$providerName = isset($options['provider']) ? strtolower($options['provider']) : 'mysql';
$parserKey = $options['parser'] ?? null;
if (is_string($parserKey)) {
    $parserKey = strtolower(trim($parserKey));
    if ($parserKey === '') {
        $parserKey = null;
    }
}
$sourceId = $options['sourceid'] ?? null;

$doclistSave = $options['doclist-save'] ?? null;
$doclistFile = $options['doclist-file'] ?? null;
$doclistOnly = isset($options['doclist-only']);

$managerOptions = [
    'parserkey' => $parserKey,
    'sourceid' => $sourceId,
    'debug' => isset($options['debug']),
    'verbose' => isset($options['verbose']) || !isset($options['debug']), // Default to verbose if not debug
    'maxrows' => isset($options['maxrows']) ? (int)$options['maxrows'] : 20000,
    'force' => isset($options['force']),
    'resplit' => isset($options['resplit']),
    'local' => isset($options['local']),
    'minsourceid' => isset($options['minsourceid']) ? (int)$options['minsourceid'] : 0,
    'maxsourceid' => isset($options['maxsourceid']) ? (int)$options['maxsourceid'] : PHP_INT_MAX,
];

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
    $managerOptions['documents'] = $doclistData;
}

try {
    if ($providerName === 'elasticsearch' || $providerName === 'es') {
        echo "Using Elasticsearch provider\n";
        $manager = new ElasticsearchSaveManager($managerOptions);
    } else {
        echo "Using MySQL provider\n";
        $manager = new MySQLSaveManager($managerOptions);
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
        echo "Summary: " . json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
