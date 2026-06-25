#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Rebuild graph from locally stored corpus text (MySQL contents table),
 * so rule changes can be re-applied without re-fetching remote documents.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../env-loader.php';
require_once __DIR__ . '/../db/funcs.php';
require_once __DIR__ . '/../providers/Neo4j/AdvancedEntityExtractor.php';
require_once __DIR__ . '/../lib/ProviderFactory.php';

use Noiiolelo\ProviderFactory;
use Noiiolelo\Providers\Neo4j\AdvancedEntityExtractor;

const DEFAULT_BATCH_SIZE = 100;
const DEFAULT_DOCUMENT_LINK_ENTITY_LIMIT = 25;
const LAST_RUN_FILE = __DIR__ . '/../ops/graph_refresh_last_run.json';

function ts(): string
{
    return '[' . date('H:i:s') . ']';
}

function normalizeText(?string $text): string
{
    return trim((string)$text);
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

function saveLastRun(array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    @file_put_contents(LAST_RUN_FILE, $json . PHP_EOL, LOCK_EX);
}

function parseOptions(): array
{
    $options = getopt('', [
        'limit:',
        'offset:',
        'batch-size:',
        'document-link-limit:',
        'no-clear',
        'verbose',
        'help',
    ]);

    if (isset($options['help'])) {
        echo "Usage: php scripts/rebuild_graph_from_local.php [OPTIONS]\n";
        echo "\n";
        echo "Rebuilds graph from locally stored corpus text (contents table).\n";
        echo "\n";
        echo "Options:\n";
        echo "  --limit=N                Max documents to process (default: all)\n";
        echo "  --offset=N               Start offset (default: 0)\n";
        echo "  --batch-size=N           Batch size for DB paging (default: 100)\n";
        echo "  --document-link-limit=N  Max entity IDs per doc for document links (default: 25)\n";
        echo "  --no-clear               Do not clear existing graph before rebuild\n";
        echo "  --verbose                Verbose progress output\n";
        echo "  --help                   Show this help\n";
        exit(0);
    }

    $limit = isset($options['limit']) ? max(0, (int)$options['limit']) : 0;
    $offset = isset($options['offset']) ? max(0, (int)$options['offset']) : 0;
    $batchSize = isset($options['batch-size']) ? max(1, (int)$options['batch-size']) : DEFAULT_BATCH_SIZE;
    $documentLinkLimit = isset($options['document-link-limit'])
        ? max(1, (int)$options['document-link-limit'])
        : DEFAULT_DOCUMENT_LINK_ENTITY_LIMIT;

    return [
        'limit' => $limit,
        'offset' => $offset,
        'batchSize' => $batchSize,
        'documentLinkLimit' => $documentLinkLimit,
        'clearFirst' => !isset($options['no-clear']),
        'verbose' => isset($options['verbose']),
    ];
}

$cfg = parseOptions();
loadEnv();

$runStartedAt = date('c');
$started = microtime(true);

$stats = [
    'status' => 'running',
    'started_at' => $runStartedAt,
    'finished_at' => null,
    'config' => $cfg,
    'processed_docs' => 0,
    'entities' => 0,
    'relationships' => 0,
    'errors' => 0,
    'elapsed_seconds' => 0,
];
saveLastRun($stats);

echo "Rebuilding graph from local corpus...\n";
echo "  limit: " . ($cfg['limit'] > 0 ? (string)$cfg['limit'] : 'all') . "\n";
echo "  offset: {$cfg['offset']}\n";
echo "  batch size: {$cfg['batchSize']}\n";
echo "  document link limit: {$cfg['documentLinkLimit']}\n";
echo "  clear existing graph: " . ($cfg['clearFirst'] ? 'yes' : 'no') . "\n";
echo "\n";

try {
    $neo4j = ProviderFactory::create('neo4j', []);
    $ping = $neo4j->graphQuery('RETURN 1');
    if (empty($ping)) {
        throw new RuntimeException('Neo4j ping returned no result');
    }

    if ($cfg['clearFirst']) {
        echo ts() . " Clearing existing graph projection...\n";
        $neo4j->graphQuery('MATCH (n:GraphEntity) DETACH DELETE n');
        $neo4j->graphQuery('MATCH (d:Document) DETACH DELETE d');
    }

    $db = new DB();
    $cursorOffset = $cfg['offset'];

    while (true) {
        if ($cfg['limit'] > 0 && $stats['processed_docs'] >= $cfg['limit']) {
            break;
        }

        $remaining = $cfg['limit'] > 0 ? ($cfg['limit'] - $stats['processed_docs']) : $cfg['batchSize'];
        $fetchSize = min($cfg['batchSize'], max(1, $remaining));

        $sql = "SELECT s.sourceID AS source_id, s.title AS title, s.sourceName AS source_name, c.text AS text "
             . "FROM contents c INNER JOIN sources s ON s.sourceID = c.sourceID "
             . "WHERE c.text IS NOT NULL AND c.text <> '' "
             . "ORDER BY s.sourceID ASC "
             . "LIMIT $fetchSize OFFSET $cursorOffset";

        $rows = $db->getDBRows($sql);
        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            try {
                $docId = (string)($row['source_id'] ?? '');
                $text = normalizeText($row['text'] ?? '');
                if ($docId === '' || $text === '' || strlen($text) < 20) {
                    continue;
                }

                $entities = AdvancedEntityExtractor::extractEntities($text);
                $relationships = AdvancedEntityExtractor::extractRelationships($text, $entities);

                if (!empty($entities)) {
                    $neo4j->addEntities($entities);
                }
                if (!empty($relationships)) {
                    $neo4j->addRelationships($relationships);
                }

                $entityIds = array_values(array_unique(array_filter(array_map(
                    static fn(array $entity): string => (string)($entity['id'] ?? ''),
                    $entities
                ))));
                if (count($entityIds) > $cfg['documentLinkLimit']) {
                    $entityIds = array_slice($entityIds, 0, $cfg['documentLinkLimit']);
                }

                $title = normalizeText(($row['title'] ?? '') ?: ($row['source_name'] ?? ''));

                if (!empty($entityIds)) {
                    $neo4j->graphQuery(
                        'MERGE (d:Document {id: $docId}) '
                        . 'SET d.title = CASE WHEN $title = "" THEN d.title ELSE $title END, d.last_seen = datetime() '
                        . 'WITH d '
                        . 'UNWIND $entityIds AS entityId '
                        . 'MATCH (e:GraphEntity {id: entityId}) '
                        . 'MERGE (e)-[:MENTIONED_IN]->(d)',
                        [
                            'docId' => $docId,
                            'title' => $title,
                            'entityIds' => $entityIds,
                        ]
                    );

                    $coMentioned = buildCoMentionedRelationships($entityIds);
                    if (!empty($coMentioned)) {
                        $neo4j->addRelationships($coMentioned);
                    }
                }

                $stats['processed_docs']++;
                $stats['entities'] += count($entities);
                $stats['relationships'] += count($relationships);

                if ($cfg['verbose']) {
                    echo ts() . " doc {$docId}: " . count($entities) . " entities, " . count($relationships) . " relationships\n";
                } elseif ($stats['processed_docs'] % 20 === 0) {
                    echo '.';
                }
            } catch (Throwable $rowError) {
                $stats['errors']++;
                if ($cfg['verbose']) {
                    fwrite(STDERR, ts() . " error processing doc: " . $rowError->getMessage() . "\n");
                }
            }
        }

        $cursorOffset += count($rows);
    }

    $stats['status'] = 'completed';
} catch (Throwable $e) {
    $stats['status'] = 'error';
    $stats['error_message'] = $e->getMessage();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
}

$stats['elapsed_seconds'] = (int)round(microtime(true) - $started);
$stats['finished_at'] = date('c');
saveLastRun($stats);

echo "\n\nGraph rebuild status: {$stats['status']}\n";
echo "Processed docs: {$stats['processed_docs']}\n";
echo "Entities: {$stats['entities']}\n";
echo "Relationships: {$stats['relationships']}\n";
echo "Errors: {$stats['errors']}\n";
echo "Elapsed: {$stats['elapsed_seconds']} sec\n";
echo "Last run file: " . LAST_RUN_FILE . "\n";

exit($stats['status'] === 'completed' ? 0 : 1);
