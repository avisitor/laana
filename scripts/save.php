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
$options = getopt("", ["provider:", "parser:", "sourceid:", "debug", "verbose", "maxrows:", "force", "resplit", "local", "minsourceid:", "maxsourceid:"]);

$providerName = isset($options['provider']) ? strtolower($options['provider']) : 'mysql';
$parserKey = $options['parser'] ?? null;
$sourceId = $options['sourceid'] ?? null;

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

try {
    if ($providerName === 'elasticsearch' || $providerName === 'es') {
        echo "Using Elasticsearch provider\n";
        $manager = new ElasticsearchSaveManager($managerOptions);
    } else {
        echo "Using MySQL provider\n";
        $manager = new MySQLSaveManager($managerOptions);
    }

    if ($sourceId) {
        $manager->processOneSource($sourceId);
    } else {
        $manager->getAllDocuments();
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
