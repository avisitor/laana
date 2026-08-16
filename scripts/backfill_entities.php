#!/usr/bin/env php
<?php
/**
 * Backfill existing Elasticsearch documents into Neo4j entities/relationships.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../providers/Neo4j/AdvancedEntityExtractor.php';
require_once __DIR__ . '/../lib/ProviderFactory.php';

use HawaiianSearch\ElasticsearchClient;
use Noiiolelo\ProviderFactory;
use Noiiolelo\Providers\Neo4j\AdvancedEntityExtractor;

const DEFAULT_BATCH_SIZE = 200;
const PROCESSED_IDS_FILE = __DIR__ . '/.backfill_entities_processed_ids.txt';
const DEFAULT_DOCUMENT_LINK_ENTITY_LIMIT = 25;

function mapLegacyElasticsearchEnv(): void
{
    $legacyHost = getenv('ELASTICSEARCH_HOST');
    $legacyPort = getenv('ELASTICSEARCH_PORT');
    $legacyUser = getenv('ELASTICSEARCH_USERNAME') ?: getenv('ELASTICSEARCH_USER');
    $legacyPass = getenv('ELASTICSEARCH_PASSWORD');

    if (!getenv('ES_HOST') && !empty($legacyHost)) {
        $host = $legacyHost;
        if (preg_match('#^https?://#i', $host)) {
            $parts = parse_url($host);
            if (!empty($parts['host'])) {
                $host = $parts['host'];
            }
            if (empty($legacyPort) && !empty($parts['port'])) {
                $legacyPort = (string) $parts['port'];
            }
        }
        putenv('ES_HOST=' . $host);
        $_ENV['ES_HOST'] = $host;
    }

    if (!getenv('ES_PORT') && !empty($legacyPort)) {
        putenv('ES_PORT=' . $legacyPort);
        $_ENV['ES_PORT'] = $legacyPort;
    }

    if (!getenv('ES_USER') && !empty($legacyUser)) {
        putenv('ES_USER=' . $legacyUser);
        $_ENV['ES_USER'] = $legacyUser;
    }

    if (!getenv('ES_PASS') && !empty($legacyPass)) {
        putenv('ES_PASS=' . $legacyPass);
        $_ENV['ES_PASS'] = $legacyPass;
    }
}

function extractDocumentText(array $source): string
{
    $candidates = [
        'text',
        'content',
        'body',
        'hawaiiantext',
        'raw_text',
        'title',
    ];

    foreach ($candidates as $field) {
        if (!empty($source[$field]) && is_string($source[$field])) {
            return trim($source[$field]);
        }
    }

    return '';
}

function loadProcessedIds(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $ids = [];
    foreach ($lines as $line) {
        $ids[$line] = true;
    }

    return $ids;
}

function appendProcessedId(string $path, string $docId): void
{
    file_put_contents($path, $docId . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function buildDocumentTitle(array $source): string
{
    $title = $source['title'] ?? $source['sourcename'] ?? '';
    if (!is_string($title)) {
        return '';
    }
    return trim($title);
}

function verboseTimestamp(): string
{
    return '[' . date('H:i:s') . ']';
}

function buildCoMentionedRelationships(array $entityIds): array
{
    $relationships = [];
    $count = count($entityIds);
    if ($count < 2) {
        return $relationships;
    }

    for ($i = 0; $i < $count - 1; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $relationships[] = [
                'source' => $entityIds[$i],
                'relation' => 'CO_MENTIONED_WITH',
                'target' => $entityIds[$j],
            ];
        }
    }

    return $relationships;
}

// Parse command line options
$options = getopt('', ['limit:', 'offset:', 'batch-size:', 'document-link-limit:', 'force', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php backfill_entities.php [OPTIONS]\n";
    echo "Options:\n";
    echo "  --limit=N      Maximum new documents to process (default: all)\n";
    echo "  --offset=N     Start from offset N for first batch (default: 0)\n";
    echo "  --batch-size=N Elasticsearch batch size per scroll request (default: 200)\n";
    echo "  --document-link-limit=N  Max entity IDs per document for document-context graph links (default: 25)\n";
    echo "  --force        Reprocess all docs (ignore processed-id checkpoint)\n";
    echo "  --verbose      Show detailed progress\n";
    echo "  --help         Show this help message\n";
    exit(0);
}

$limit = isset($options['limit']) ? (int)$options['limit'] : 0;
$offset = isset($options['offset']) ? (int)$options['offset'] : 0;
$batchSize = isset($options['batch-size']) ? (int)$options['batch-size'] : DEFAULT_BATCH_SIZE;
$documentLinkLimit = isset($options['document-link-limit']) ? (int)$options['document-link-limit'] : DEFAULT_DOCUMENT_LINK_ENTITY_LIMIT;
$force = isset($options['force']);
$verbose = isset($options['verbose']);

if ($limit < 0) {
    fwrite(STDERR, "ERROR: --limit must be >= 0\n");
    exit(1);
}

if ($offset < 0) {
    fwrite(STDERR, "ERROR: --offset must be >= 0\n");
    exit(1);
}

if ($batchSize <= 0) {
    fwrite(STDERR, "ERROR: --batch-size must be > 0\n");
    exit(1);
}

if ($documentLinkLimit <= 0) {
    fwrite(STDERR, "ERROR: --document-link-limit must be > 0\n");
    exit(1);
}

\Avisitor\Env\Loader::load(__DIR__ . '/../.env');
mapLegacyElasticsearchEnv();

echo "Starting entity extraction backfill...\n";
echo "Configuration:\n";
echo "  ES Host: " . (getenv('ES_HOST') ?: 'localhost') . "\n";
echo "  ES Port: " . (getenv('ES_PORT') ?: '9200') . "\n";
echo "  ES Auth: " . ((getenv('API_KEY') || getenv('ES_USER')) ? 'configured' : 'missing') . "\n";
echo "  Neo4j URI: " . (getenv('NEO4J_URI') ?: 'http://localhost:7474') . "\n";
echo "  Limit: " . ($limit > 0 ? (string)$limit : 'all') . ", Offset: $offset\n";
echo "  Batch Size: $batchSize\n";
echo "  Document Link Limit: $documentLinkLimit\n";
echo "  Force: " . ($force ? 'yes' : 'no') . "\n";
echo "  Verbose: " . ($verbose ? 'yes' : 'no') . "\n\n";

// Initialize statistics
$processedCount = 0;
$skippedAlreadyProcessedCount = 0;
$entitiesCount = 0;
$relationshipsCount = 0;
$errorCount = 0;
$startTime = time();

$processedIds = $force ? [] : loadProcessedIds(PROCESSED_IDS_FILE);
if ($verbose && !$force) {
    echo "Loaded " . count($processedIds) . " processed document IDs from checkpoint\n";
}

// Neo4j is mandatory for this script.
$neo4jProvider = null;
try {
    if ($verbose) {
        echo "Checking Neo4j availability...\n";
    }
    $neo4jProvider = ProviderFactory::create('neo4j', []);
    $neo4jPing = $neo4jProvider->graphQuery('RETURN 1');
    if (empty($neo4jPing)) {
        throw new RuntimeException('Neo4j query returned no result');
    }
    if ($verbose) {
        echo "Neo4j is reachable\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: Neo4j is not available: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Set NEO4J_URI/NEO4J_USERNAME/NEO4J_PASSWORD and start Neo4j before running backfill.\n");
    exit(1);
}

try {
    if ($verbose) {
        echo "Creating Elasticsearch client...\n";
    }

    $esClient = new ElasticsearchClient([
        'verbose' => $verbose,
        'quiet' => !$verbose,
    ]);
    $rawClient = $esClient->getRawClient();
    $documentsIndex = $esClient->getDocumentsIndexName();

    if ($verbose) {
        echo "Fetching documents from index: $documentsIndex\n";
    }

    $response = $rawClient->search([
        'index' => $documentsIndex,
        'scroll' => '1m',
        'from' => $offset,
        'size' => $batchSize,
        '_source' => true,
        'body' => [
            'query' => [
                'match_all' => new stdClass(),
            ],
            'sort' => ['_doc'],
        ],
    ]);

    if (method_exists($response, 'asArray')) {
        $response = $response->asArray();
    }

    if (!is_array($response)) {
        throw new RuntimeException('Unexpected Elasticsearch response type');
    }

    $totalFound = $response['hits']['total']['value'] ?? 0;
    $hits = $response['hits']['hits'] ?? [];
    $scrollId = $response['_scroll_id'] ?? null;

    if ($verbose) {
        echo "Found $totalFound total documents; first batch has " . count($hits) . " documents\n\n";
    }

    if (count($hits) === 0) {
        echo "No documents found in Elasticsearch.\n";
        exit(0);
    }
} catch (Throwable $e) {
    echo "ERROR: Failed to retrieve documents: {$e->getMessage()}\n";
    if ($verbose) {
        echo "Tip: check ES_HOST/ES_PORT and API_KEY or ES_USER/ES_PASS in .env\n";
    }
    exit(1);
}

// Process each document via scroll
$batchNumber = 0;
while (true) {
    $batchNumber++;

    foreach ($hits as $hit) {
        if ($limit > 0 && $processedCount >= $limit) {
            break 2;
        }

        try {
            $docId = (string)($hit['_id'] ?? '');
            if ($docId === '') {
                $errorCount++;
                continue;
            }

            if (!$force && isset($processedIds[$docId])) {
                $skippedAlreadyProcessedCount++;
                if ($verbose) {
                    echo '  ' . verboseTimestamp() . " SKIP: already processed $docId\n";
                }
                continue;
            }

            // Extract document text
            $source = $hit['_source'] ?? [];
            $text = extractDocumentText($source);

            if (!$text || strlen(trim($text)) < 20) {
                if ($verbose) {
                    echo '  ' . verboseTimestamp() . " SKIP: Insufficient text in $docId\n";
                }
                $errorCount++;
                $processedIds[$docId] = true;
                appendProcessedId(PROCESSED_IDS_FILE, $docId);
                continue;
            }

            // Extract entities and relationships using the advanced extractor
            $entities = AdvancedEntityExtractor::extractEntities($text);
            $relationships = AdvancedEntityExtractor::extractRelationships($text, $entities);

            // Store the extracted data in Neo4j.
            try {
                if (!empty($entities)) {
                    $neo4jProvider->addEntities($entities);
                }
                if (!empty($relationships)) {
                    $neo4jProvider->addRelationships($relationships);
                }

                // Document-aware graph: link each entity mention to its source document.
                $documentTitle = buildDocumentTitle($source);
                $entityIds = array_values(array_unique(array_filter(array_map(
                    static fn(array $entity): string => (string)($entity['id'] ?? ''),
                    $entities
                ))));
                if (count($entityIds) > $documentLinkLimit) {
                    $entityIds = array_slice($entityIds, 0, $documentLinkLimit);
                }
                $coMentionedRelationships = buildCoMentionedRelationships($entityIds);

                $neo4jProvider->graphQuery(
                    'MERGE (d:Document {id: $docId}) '
                    . 'SET d.title = CASE WHEN $title = "" THEN d.title ELSE $title END, d.last_seen = datetime() '
                    . 'WITH d '
                    . 'UNWIND $entityIds AS entityId '
                    . 'MATCH (e:GraphEntity {id: entityId}) '
                    . 'MERGE (e)-[:MENTIONED_IN]->(d)',
                    [
                        'docId' => $docId,
                        'title' => $documentTitle,
                        'entityIds' => $entityIds,
                    ]
                );

                if (!empty($coMentionedRelationships)) {
                    $neo4jProvider->addRelationships($coMentionedRelationships);
                }
            } catch (Throwable $e) {
                fwrite(STDERR, "ERROR: Neo4j write failed for doc $docId: " . $e->getMessage() . "\n");
                exit(1);
            }

            $processedCount++;
            $entitiesCount += count($entities);
            $relationshipsCount += count($relationships);
            $processedIds[$docId] = true;
            appendProcessedId(PROCESSED_IDS_FILE, $docId);

            if ($verbose) {
                echo '  ' . verboseTimestamp() . " OK: $docId - " . count($entities) . " entities, " . count($relationships) . " relationships\n";
            } else if ($processedCount % 10 === 0) {
                echo ".";
            }
        } catch (Throwable $e) {
            if ($verbose) {
                echo '  ' . verboseTimestamp() . " ERROR: " . $e->getMessage() . "\n";
            }
            $errorCount++;
        }
    }

    if ($verbose) {
        echo "Finished batch $batchNumber (processed so far: $processedCount, skipped already processed: $skippedAlreadyProcessedCount)\n";
    }

    if (empty($scrollId)) {
        break;
    }

    $scrollResponse = $rawClient->scroll([
        'scroll_id' => $scrollId,
        'scroll' => '1m',
    ]);

    if (method_exists($scrollResponse, 'asArray')) {
        $scrollResponse = $scrollResponse->asArray();
    }

    if (!is_array($scrollResponse)) {
        break;
    }

    $hits = $scrollResponse['hits']['hits'] ?? [];
    $scrollId = $scrollResponse['_scroll_id'] ?? $scrollId;
    if (count($hits) === 0) {
        break;
    }
}

if (!empty($scrollId)) {
    try {
        $rawClient->clearScroll(['scroll_id' => [$scrollId]]);
    } catch (Throwable $e) {
        // no-op
    }
}

$elapsedTime = time() - $startTime;

echo "\n\nBackfill Complete!\n";
echo "==================\n";
echo "Documents Processed: $processedCount\n";
echo "Skipped Already Processed: $skippedAlreadyProcessedCount\n";
echo "Entities Extracted:  $entitiesCount\n";
echo "Relationships:       $relationshipsCount\n";
echo "Errors:              $errorCount\n";
echo "Time Elapsed:        $elapsedTime seconds\n";

exit(0);
