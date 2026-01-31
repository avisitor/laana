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
$options = getopt("", ["force", "sourceid:", "provider:", "batch:"]);
$force = isset($options['force']);
$targetSourceId = isset($options['sourceid']) ? (int)$options['sourceid'] : null;
$providerName = isset($options['provider']) ? strtolower($options['provider']) : 'mysql';
$batchSize = isset($options['batch']) ? max(100, (int)$options['batch']) : 5000;

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
        echo "Delta scanning all sentences (batch size: {$batchSize})...\n";
        $startTime = microtime(true);
        $result = $scanner->updateAllPatternsDelta($force, $batchSize, function($currentId, $maxId, $newSentences, $newPatterns, $skipped) use ($startTime) {
            $elapsed = microtime(true) - $startTime;
            $rate = $elapsed > 0 ? ($currentId / $elapsed) : 0;
            $pct = $maxId > 0 ? ($currentId / $maxId) * 100 : 100;
            $label = $skipped ? 'Skipping processed block' : 'Progress';
            echo sprintf("%s: %d/%d (%.1f%%) | New Sentences: %d | New Patterns: %d | Rate: %.0f IDs/sec\r",
                $label, $currentId, $maxId, $pct, $newSentences, $newPatterns, $rate
            );
            flush();
        });
        echo "\nDone. Sentences newly analyzed: {$result['sentences']}. Pattern records created: {$result['patterns']}.\n";
    }

    echo "\n--- Current Pattern Distribution (Full Table) ---\n";
    $summary = $scanner->getPatternSummary();
    if (!$summary) {
        echo "No patterns found.\n";
    } else {
        echo str_pad('Pattern Type', 25) . " | " . str_pad('Count', 10) . "\n";
        echo str_repeat('-', 40) . "\n";
        foreach ($summary as $row) {
            echo str_pad($row['pattern_type'], 25) . " | " . str_pad($row['count'], 10) . "\n";
        }
    }

    echo "\nRefreshing grammar_pattern_counts...\n";
    if (method_exists($laana, 'refreshGrammarPatternCounts')) {
        $ok = $laana->refreshGrammarPatternCounts();
        echo $ok ? "grammar_pattern_counts refreshed.\n" : "Failed to refresh grammar_pattern_counts.\n";
    } else {
        try {
            $laana->executePrepared("CALL refresh_grammar_counts()");
            echo "grammar_pattern_counts refreshed.\n";
        } catch (Throwable $e) {
            echo "Failed to refresh grammar_pattern_counts: {$e->getMessage()}\n";
        }
    }
}
