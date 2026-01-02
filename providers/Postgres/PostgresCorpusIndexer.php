<?php

namespace Noiiolelo\Providers\Postgres;
        $results = [
            'processed' => 0,
            'embeddings' => 0,
            'metrics' => 0,
            'errors' => 0,
            'ids' => [],
            'timing' => ['embed_ms' => 0.0, 'metrics_ms' => 0.0, 'db_ms' => 0.0, 'total_ms' => 0.0],
        ];
        $runStart = microtime(true);
require_once __DIR__ . '/PostgresClient.php';
require_once __DIR__ . '/PostgresSentenceIterator.php';
require_once __DIR__ . '/MetricsComputer.php';

class PostgresCorpusIndexer
{
    <?php

    namespace Noiiolelo\Providers\Postgres;

    require_once __DIR__ . '/PostgresClient.php';
    require_once __DIR__ . '/PostgresSentenceIterator.php';
    require_once __DIR__ . '/MetricsComputer.php';

    class PostgresCorpusIndexer
    {
        private PostgresClient $pg;
        private PostgresSentenceIterator $iterator;
        private bool $dryrun = true;
        private const EXPECTED_VECTOR_DIMS = 384;
        private int $maxRecords = 0;
        private MetricsComputer $metrics;

        public function __construct(array $config)
        {
            $this->dryrun = $config['dryrun'] ?? true;
            $this->maxRecords = (int)($config['MAX_RECORDS'] ?? 0);
            $this->pg = new PostgresClient($config);
            $batch = (int)($config['BATCH_SIZE'] ?? 100);
            $this->iterator = new PostgresSentenceIterator($this->pg, $batch);
            $hawaiianWordsPath = __DIR__ . '/../hawaiian_words.txt';
            $this->metrics = new MetricsComputer($hawaiianWordsPath);
        }

        public function run(): array
        {
            $processed = 0;
            $results = [
                'processed' => 0,
                'embeddings' => 0,
                'metrics' => 0,
                'errors' => 0,
                'ids' => [],
                'timing' => ['embed_ms' => 0.0, 'metrics_ms' => 0.0, 'db_ms' => 0.0, 'total_ms' => 0.0],
            ];
            $runStart = microtime(true);
            while (true) {
                if ($this->maxRecords > 0 && $processed >= $this->maxRecords) break;
                $batch = $this->iterator->getNext();
                if (empty($batch)) break;

                if ($this->maxRecords > 0 && $processed + count($batch) > $this->maxRecords) {
                    $batch = array_slice($batch, 0, $this->maxRecords - $processed);
                }

                $out = $this->processSentenceBatch($batch);
                $processed += $out['processed'];
                $results['processed'] += $out['processed'];
                $results['embeddings'] += $out['embeddings'];
                $results['metrics'] += $out['metrics'];
                $results['errors'] += $out['errors'];
                if (!empty($out['ids'])) {
                    $results['ids'] = array_merge($results['ids'], $out['ids']);
                }
                if (!empty($out['timing'])) {
                    $results['timing']['embed_ms'] += (float)($out['timing']['embed_ms'] ?? 0);
                    $results['timing']['metrics_ms'] += (float)($out['timing']['metrics_ms'] ?? 0);
                    $results['timing']['db_ms'] += (float)($out['timing']['db_ms'] ?? 0);
                    $results['timing']['total_ms'] += (float)($out['timing']['total_ms'] ?? 0);
                }
            }
            if (($results['timing']['total_ms'] ?? 0) <= 0) {
                $results['timing']['total_ms'] = (microtime(true) - $runStart) * 1000.0;
            }
            return $results;
        }

        private function processSentenceBatch(array $rows): array
        {
            if (empty($rows)) return ['processed' => 0, 'embeddings' => 0, 'metrics' => 0, 'errors' => 0, 'ids' => [], 'timing' => ['embed_ms'=>0.0,'metrics_ms'=>0.0,'db_ms'=>0.0,'total_ms'=>0.0]];

            $texts = [];
            $ids = [];
            foreach ($rows as $r) {
                $sid = (int)$r['sentenceid'];
                $txt = (string)($r['hawaiianText'] ?? $r['hawaiiantext'] ?? $r['text'] ?? '');
                $ids[] = $sid;
                $texts[] = $txt;
            }

            $bStart = microtime(true);
            $eStart = microtime(true);
            $vecs = $this->pg->getEmbeddingClient()->embedSentences($texts, 'passage: ');
            $eEnd = microtime(true);
            if (!is_array($vecs) || count($vecs) !== count($texts)) {
                foreach ($ids as $sid) {
                    echo "Error: invalid_vector for sentence {$sid}\n";
                }
                return [
                    'processed' => count($rows), 'embeddings' => 0, 'metrics' => 0, 'errors' => count($rows),
                    'ids' => $ids,
                    'timing' => ['embed_ms' => ($eEnd - $eStart)*1000.0, 'metrics_ms' => 0.0, 'db_ms' => 0.0, 'total_ms' => (microtime(true)-$bStart)*1000.0]
                ];
            }

            $embeddings = [];
            $metricsRows = [];
            $mStart = microtime(true);
            for ($i = 0; $i < count($rows); $i++) {
                $sid = $ids[$i];
                $vec = $vecs[$i] ?? null;
                if (!$this->isValidVector($vec)) {
                    echo "Error: invalid_vector for sentence {$sid}\n";
                    return [
                        'processed' => count($rows), 'embeddings' => 0, 'metrics' => 0, 'errors' => count($rows),
                        'ids' => $ids,
                        'timing' => ['embed_ms' => ($eEnd - $eStart)*1000.0, 'metrics_ms' => (microtime(true)-$mStart)*1000.0, 'db_ms' => 0.0, 'total_ms' => (microtime(true)-$bStart)*1000.0]
                    ];
                }
                $embeddings[$sid] = $vec;
                $m = $this->metrics->computeSentenceMetrics($texts[$i]);
                $metricsRows[] = ['sentenceid' => $sid] + $m;
            }
            $mEnd = microtime(true);

            $updatedEmb = 0;
            $updatedMet = 0;
            $dStart = microtime(true);
            if (!$this->dryrun) {
                $conn = $this->pg->conn;
                $conn->beginTransaction();
                try {
                    $targetCount = count($rows);
                    $updatedEmb = $this->pg->bulkUpdateSentenceEmbeddings($embeddings);
                    $updatedMet = $this->pg->upsertSentenceMetrics($metricsRows);
                    if ($updatedEmb !== $targetCount || $updatedMet !== $targetCount) {
                        // Debug: echo mismatch details for visibility
                        echo "Write mismatch: target={$targetCount} updatedEmb={$updatedEmb} updatedMet={$updatedMet}\n";
                        $conn->rollBack();
                        $dEnd = microtime(true);
                        return [
                            'processed' => $targetCount, 'embeddings' => 0, 'metrics' => 0, 'errors' => $targetCount,
                            'ids' => $ids,
                            'timing' => [
                                'embed_ms' => ($eEnd - $eStart) * 1000.0,
                                'metrics_ms' => ($mEnd - $mStart) * 1000.0,
                                'db_ms' => ($dEnd - $dStart) * 1000.0,
                                'total_ms' => (microtime(true) - $bStart) * 1000.0,
                            ],
                        ];
                    }
                    $conn->commit();
                } catch (\Throwable $e) {
                    $conn->rollBack();
                    echo 'Exception during write: ' . $e->getMessage() . "\n";
                    $dEnd = microtime(true);
                    return [
                        'processed' => count($rows), 'embeddings' => 0, 'metrics' => 0, 'errors' => count($rows),
                        'ids' => $ids,
                        'timing' => [
                            'embed_ms' => ($eEnd - $eStart) * 1000.0,
                            'metrics_ms' => ($mEnd - $mStart) * 1000.0,
                            'db_ms' => ($dEnd - $dStart) * 1000.0,
                            'total_ms' => (microtime(true) - $bStart) * 1000.0,
                        ],
                    ];
                }
            }
            $dEnd = microtime(true);

            return [
                'processed' => count($rows), 'embeddings' => $updatedEmb, 'metrics' => $updatedMet, 'errors' => 0,
                'ids' => $ids,
                'timing' => [
                    'embed_ms' => ($eEnd - $eStart) * 1000.0,
                    'metrics_ms' => ($mEnd - $mStart) * 1000.0,
                    'db_ms' => ($dEnd - $dStart) * 1000.0,
                    'total_ms' => (microtime(true) - $bStart) * 1000.0,
                ],
