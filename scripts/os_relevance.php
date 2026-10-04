#!/usr/bin/env php
<?php
/**
 * Tune OpenSearch hybrid document search (`hybriddoc`) with the Search
 * Relevance Workbench, using human relevance ratings.
 *
 *   1. prepare   Register the embedding service as an ML Commons remote model,
 *                build a query set and export pooled candidates to a CSV.
 *   2. (rate)    Fill the CSV's `rating` column: 0 irrelevant, 1 marginal,
 *                2 relevant, 3 highly relevant. Blank rows are skipped.
 *   3. optimize  Import the ratings, run the HYBRID_OPTIMIZER experiment and
 *                list the best normalization/combination variants.
 *   4. apply     Write the chosen variant to
 *                providers/OpenSearch/config/search_pipeline.json and install it.
 *
 * Usage:
 *   php scripts/os_relevance.php prepare [--queries=FILE | --from-searchstats]
 *                                        [--limit=25] [--depth=5] [--out=FILE]
 *   php scripts/os_relevance.php optimize [--judgments=FILE] [--top=10]
 *   php scripts/os_relevance.php apply [--rank=1]
 *   php scripts/os_relevance.php status
 *
 * State (ids of the query set, judgments, experiment) is kept in
 * logs/os-relevance-state.json between steps.
 */

declare(strict_types=1);

use HawaiianSearch\{OpenSearchClient, RemoteEmbeddingModel, RelevanceWorkbench};

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/lib/provider.php';

$statePath = $root . '/logs/os-relevance-state.json';
// getopt() stops at the first non-option (the command), so parse argv directly.
$command = 'help';
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $command = $arg;
    }
}

$loadState = function () use ($statePath): array {
    $state = json_decode((string)@file_get_contents($statePath), true);
    if (!is_array($state)) {
        fwrite(STDERR, "No state in $statePath - run 'prepare' first.\n");
        exit(1);
    }
    return $state;
};
$saveState = fn(array $state) => file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
$describe = fn(array $p) => ($p['combination'] ?? '') === 'rrf'
    ? "rrf rank_constant={$p['rank_constant']}"
    : sprintf('%s / %s weights=[%s] (keyword, vector)', $p['normalization'], $p['combination'], implode(', ', $p['weights'] ?? []));

try {
    switch ($command) {
        case 'prepare':
            if (isset($opts['queries'])) {
                $lines = @file($opts['queries'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines === false) {
                    throw new RuntimeException("Cannot read {$opts['queries']}");
                }
                $queries = array_slice(array_values(array_unique(array_map('trim', $lines))), 0, (int)($opts['limit'] ?? PHP_INT_MAX));
            } elseif (isset($opts['from-searchstats'])) {
                // Real user queries; search stats are only logged by the MySQL provider.
                $queries = RelevanceWorkbench::topQueries(getProvider('MySQL')->getSearchStats(), (int)($opts['limit'] ?? 25));
            } else {
                throw new RuntimeException('prepare needs --queries=FILE or --from-searchstats');
            }
            if (empty($queries)) {
                throw new RuntimeException('No queries found');
            }
            $client = new OpenSearchClient();
            $modelId = (new RemoteEmbeddingModel($client))->ensureDeployed();
            $wb = new RelevanceWorkbench($client, $modelId);
            $out = $opts['out'] ?? $root . '/logs/os-relevance-ratings.csv';
            $stamp = date('Ymd-His');
            $querySetId = $wb->createQuerySet("noiiolelo-hybriddoc-$stamp", $queries);
            $rows = $wb->exportCandidates($queries, $out, (int)($opts['depth'] ?? 5));
            $state = [
                'model_id' => $modelId,
                'query_set_id' => $querySetId,
                'queries' => $queries,
                'ratings_csv' => $out,
                'prepared_at' => date('c'),
            ];
            $saveState($state);
            echo count($queries) . " queries, $rows candidate rows written to $out\n";
            echo "Rate each row 0-3 in the 'rating' column, then run: php scripts/os_relevance.php optimize\n";
            break;

        case 'optimize':
            $state = $loadState();
            $client = new OpenSearchClient();
            $wb = new RelevanceWorkbench($client, $state['model_id']);
            $judgments = $wb->importJudgments($opts['judgments'] ?? $state['ratings_csv'], 'noiiolelo-ratings-' . date('Ymd-His'));
            echo "Imported {$judgments['rated']} ratings for " . count($judgments['queries']) . " queries ({$judgments['skipped']} unrated rows skipped)\n";
            $configId = $wb->createSearchConfiguration('noiiolelo-hybriddoc-' . date('Ymd-His'));
            echo "Running HYBRID_OPTIMIZER...\n";
            $experimentId = $wb->runHybridOptimizer($state['query_set_id'], $configId, $judgments['id']);
            $state = array_merge($state, ['judgment_id' => $judgments['id'], 'search_configuration_id' => $configId, 'experiment_id' => $experimentId]);
            $saveState($state);
            $results = $wb->variantResults($experimentId);
            $installed = OpenSearchClient::searchPipelineConfig()['body']['phase_results_processors'];
            foreach (array_slice($results, 0, (int)($opts['top'] ?? 10)) as $i => $r) {
                $mark = RelevanceWorkbench::pipelineBodyFor($r['parameters'])['phase_results_processors'] == $installed ? '  <- installed' : '';
                printf("%2d. NDCG@10 %.4f  MAP@10 %.4f  %s%s\n", $i + 1, RelevanceWorkbench::ndcg($r['metrics']), $r['metrics']['MAP@10'] ?? 0, $describe($r['parameters']), $mark);
            }
            foreach ($results as $i => $r) {
                if (RelevanceWorkbench::pipelineBodyFor($r['parameters'])['phase_results_processors'] == $installed) {
                    printf("Installed pipeline ranks %d of %d (NDCG@10 %.4f)\n", $i + 1, count($results), RelevanceWorkbench::ndcg($r['metrics']));
                }
            }
            echo "Apply a variant with: php scripts/os_relevance.php apply --rank=N\n";
            break;

        case 'apply':
            $state = $loadState();
            if (empty($state['experiment_id'])) {
                throw new RuntimeException("No experiment in state - run 'optimize' first.");
            }
            $client = new OpenSearchClient();
            $results = (new RelevanceWorkbench($client, $state['model_id']))->variantResults($state['experiment_id']);
            $rank = (int)($opts['rank'] ?? 1);
            if (!isset($results[$rank - 1])) {
                throw new RuntimeException("No variant at rank $rank (have " . count($results) . ')');
            }
            $config = OpenSearchClient::searchPipelineConfig();
            $config['body'] = RelevanceWorkbench::pipelineBodyFor($results[$rank - 1]['parameters']);
            $file = $root . '/providers/OpenSearch/config/search_pipeline.json';
            file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $client->createSearchPipeline();
            echo "Applied rank $rank (" . $describe($results[$rank - 1]['parameters']) . ") to $file and the cluster.\n";
            break;

        case 'status':
            echo json_encode($loadState(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            break;

        default:
            $doc = file_get_contents(__FILE__);
            preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
            echo preg_replace('/^\s*\* ?/m', '', trim($m[1])) . "\n";
            exit($command === 'help' || isset($opts['help']) ? 0 : 1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
