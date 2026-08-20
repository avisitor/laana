#!/usr/bin/env php
<?php
/**
 * Rebuild the hawaiian-source-metadata index from the documents index.
 *
 * Recovery script for source-metadata records lost to a bug in
 * CorpusIndexer::initializeSourceMetadata(), which deleted the
 * source-metadata index whenever --recreate was combined with --import-raw
 * (or other update-only modes), even though those modes are only supposed
 * to touch a different index. Source-metadata is fully derived data
 * (sourceid, sourcename, groupname, authors, date, link, title, discarded)
 * that can be reconstructed by scrolling the documents index, so nothing is
 * permanently lost when this index is empty.
 *
 * Usage:
 *   php php/php/rebuild_source_metadata.php --provider=Elasticsearch
 *   php php/php/rebuild_source_metadata.php --provider=OpenSearch
 *   php php/php/rebuild_source_metadata.php --provider=Elasticsearch --dryrun
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Error: Composer autoloader not found at {$autoloadPath}.\n");
    fwrite(STDERR, "Run 'composer install' in {$projectRoot} first.\n");
    exit(1);
}

require_once $autoloadPath;

if (class_exists('Avisitor\\Env\\Loader')) {
    \Avisitor\Env\Loader::load($projectRoot . '/.env');
}

use HawaiianSearch\ElasticsearchClient;
use HawaiianSearch\OpenSearchClient;

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------
$provider = 'Elasticsearch';
$dryrun = false;
$scrollBatch = 1000;
$saveBatch = 500;

$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if (preg_match('/^--provider=(.+)$/i', $arg, $m)) {
        $provider = $m[1];
    } elseif ($arg === '--dryrun') {
        $dryrun = true;
    } elseif (preg_match('/^--batch-size=(\d+)$/i', $arg, $m)) {
        $scrollBatch = (int)$m[1];
    }
}

echo "Provider: {$provider}\n";
echo "Dry run: " . ($dryrun ? 'yes' : 'no') . "\n\n";

// ---------------------------------------------------------------------------
// Create the provider client
// ---------------------------------------------------------------------------
$clientConfig = ['verbose' => true];

if (in_array(strtolower($provider), ['opensearch', 'os'], true)) {
    $client = new OpenSearchClient($clientConfig);
    echo "Using OpenSearch client\n";
} else {
    $client = new ElasticsearchClient($clientConfig);
    echo "Using Elasticsearch client\n";
}

$documentsIndex = $client->getDocumentsIndexName();
$sourceMetadataIndex = $client->getSourceMetadataName();

echo "Documents index:       {$documentsIndex}\n";
echo "Source-metadata index: {$sourceMetadataIndex}\n\n";

// ---------------------------------------------------------------------------
// Step 1: Ensure the source-metadata index exists. This is idempotent — it
// does NOT delete/recreate an existing index (createSourceMetadataIndex only
// deletes first when its own $recreate argument is true, which we never pass
// here).
// ---------------------------------------------------------------------------
echo "Step 1: Ensuring source-metadata index exists...\n";
if (!$dryrun) {
    $client->createSourceMetadataIndex(false, $client->getIndexName());
} else {
    echo "  [DRY RUN] Would create source-metadata index if missing\n";
}

// ---------------------------------------------------------------------------
// Step 2: Scroll the documents index and rebuild one source-metadata record
// per document (mirrors CorpusIndexer::updateSourceMetadata()).
// ---------------------------------------------------------------------------
echo "\nStep 2: Scrolling documents and rebuilding source-metadata records...\n";

$tc = $client->getTransportClient();

$response = $tc->search([
    'scroll' => '60s',
    'size'   => $scrollBatch,
    'index'  => $documentsIndex,
    'body'   => [
        'query'   => ['match_all' => (object)[]],
        '_source' => ['doc_id', 'sourcename', 'groupname', 'authors', 'date', 'link', 'title', 'sentencecount'],
    ],
]);

if (is_object($response) && method_exists($response, 'asArray')) {
    $response = $response->asArray();
}

$scrollId  = $response['_scroll_id'] ?? null;
$hits      = $response['hits']['hits'] ?? [];
$totalHits = $response['hits']['total']['value'] ?? 0;

echo "  Total documents: {$totalHits}\n";

$processed = 0;
$records = [];

$flush = function () use (&$records, $client, $dryrun): void {
    if (empty($records)) {
        return;
    }
    if (!$dryrun) {
        $client->saveSourceMetadata($records);
    }
    $records = [];
};

while (!empty($hits)) {
    foreach ($hits as $hit) {
        $source = $hit['_source'] ?? [];
        $sourceid = (string)($source['doc_id'] ?? $hit['_id'] ?? '');
        if (empty($sourceid)) {
            continue;
        }

        $discarded = ((int)($source['sentencecount'] ?? 0) < 1);
        $records[] = [
            'sourceid'   => $sourceid,
            'sourcename' => $source['sourcename'] ?? '',
            'groupname'  => $source['groupname'] ?? '',
            'authors'    => $source['authors'] ?? '',
            'date'       => $source['date'] ?? '',
            'link'       => $source['link'] ?? '',
            'title'      => $source['title'] ?? '',
            'quality'    => 1.0,
            'discarded'  => $discarded,
            'empty'      => $discarded,
        ];
        $processed++;

        if (count($records) >= $saveBatch) {
            $flush();
            echo "  Processed {$processed} / {$totalHits}\n";
        }
    }

    if (!$scrollId) {
        break;
    }
    $response = $tc->scroll(['scroll_id' => $scrollId, 'scroll' => '60s']);
    if (is_object($response) && method_exists($response, 'asArray')) {
        $response = $response->asArray();
    }
    $scrollId = $response['_scroll_id'] ?? null;
    $hits = $response['hits']['hits'] ?? [];
}

$flush();

echo "\nDone. Rebuilt {$processed} source-metadata record(s)" . ($dryrun ? " [DRY RUN - not written]" : "") . ".\n";
