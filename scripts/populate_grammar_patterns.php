<?php
/**
 * Unified script to populate grammar patterns for sentences across different providers.
 * 
 * Usage: php populate_grammar_patterns.php --provider=[es|mysql|postgres] [--force] [--sourceid=ID]
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db/funcs.php';
require_once __DIR__ . '/../db/PostgresFuncs.php';

use Noiiolelo\ProviderFactory;
use Noiiolelo\GrammarScanner;

// Parse command line arguments
$options = getopt("", ["force", "sourceid:", "provider:"]);
$force = isset($options['force']);
$targetSourceId = isset($options['sourceid']) ? (int)$options['sourceid'] : null;
$providerName = isset($options['provider']) ? strtolower($options['provider']) : 'mysql';

try {
    $provider = ProviderFactory::create($providerName, ['verbose' => true]);
    echo "Using provider: " . $provider->getName() . "\n";
} catch (\Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}

if ($providerName === 'elasticsearch' || $providerName === 'es') {
    // Elasticsearch specific population logic
    $client = $provider->getRawClient(); // We might need to expose this or add a method to the interface
    
    $index = $provider->getSentencesIndexName();
    echo "Processing Elasticsearch index: $index\n";
    
    $params = [
        'index' => $index,
        'scroll' => '1m',
        'size' => 100,
        'body' => [
            'query' => [
                'match_all' => (object)[]
            ]
        ]
    ];
    
    if (!$force) {
        $params['body']['query'] = [
            'bool' => [
                'must_not' => [
                    'exists' => ['field' => 'grammar_patterns']
                ]
            ]
        ];
    }
    
    if ($targetSourceId) {
        $params['body']['query'] = [
            'bool' => [
                'must' => [
                    ['term' => ['sourceid' => $targetSourceId]]
                ]
            ]
        ];
    }

    $response = $client->search($params)->asArray();
    $scrollId = $response['_scroll_id'];
    $total = $response['hits']['total']['value'];
    echo "Found $total sentences to process.\n";

    $count = 0;
    $scanner = new GrammarScanner();

    while (true) {
        $hits = $response['hits']['hits'];
        if (empty($hits)) break;

        $bulkParams = ['body' => []];
        foreach ($hits as $hit) {
            $text = $hit['_source']['text'];
            $patterns = $scanner->scanSentence($text);
            
            $bulkParams['body'][] = [
                'update' => [
                    '_index' => $index,
                    '_id' => $hit['_id']
                ]
            ];
            $bulkParams['body'][] = [
                'doc' => [
                    'grammar_patterns' => $patterns
                ]
            ];
            $count++;
        }

        if (!empty($bulkParams['body'])) {
            $client->bulk($bulkParams);
        }

        echo "Processed $count / $total\r";

        $response = $client->scroll([
            'scroll_id' => $scrollId,
            'scroll' => '1m'
        ])->asArray();
        $scrollId = $response['_scroll_id'];
    }
    echo "\nDone.\n";

} else {
    // SQL specific population logic
    $laana = $provider->getProcessingLogger(); // In SQL providers, this returns the Laana/PostgresLaana object
    $scanner = new GrammarScanner($laana);
    
    if ($targetSourceId) {
        echo "Processing source ID: $targetSourceId\n";
        $count = $scanner->updateSourcePatterns($targetSourceId, $force);
        echo "Processed $count sentences.\n";
    } else {
        echo "Processing all sources...\n";
        $count = $scanner->updateAllPatterns($force);
        echo "Processed $count sentences.\n";
    }
}
