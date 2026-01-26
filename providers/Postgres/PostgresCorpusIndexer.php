<?php

namespace Noiiolelo\Providers\Postgres;

use Noiiolelo\MetricsComputer;

require_once __DIR__ . '/PostgresClient.php';
require_once __DIR__ . '/PostgresSentenceIterator.php';
require_once __DIR__ . '/../../lib/MetricsComputer.php';

class PostgresCorpusIndexer
{
    private PostgresClient $pg;
    private PostgresSentenceIterator $iterator;
    private bool $dryrun = true;
    private bool $verbose = false;
    private const EXPECTED_VECTOR_DIMS = 384;
    private int $maxRecords = 0;
    private MetricsComputer $metrics;
    private ?string $outJson = null;
    private int $willProcess = 0;
    private array $intentIds = [];

    public function __construct(array $config)
    {
        $this->dryrun = $config['dryrun'] ?? true;
        $this->verbose = (bool)($config['verbose'] ?? false);
        $this->maxRecords = (int)($config['MAX_RECORDS'] ?? 0);
        $this->outJson = isset($config['out_json']) ? (string)$config['out_json'] : null;
        $this->willProcess = (int)($config['WILL_PROCESS'] ?? 0);
        $this->intentIds = is_array($config['INTENT_IDS'] ?? null) ? array_values(array_map('intval', $config['INTENT_IDS'])) : [];
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
            if (empty($batch)) {
                if ($this->willProcess > 0 && $processed < $this->willProcess) {
                    fwrite(STDERR, sprintf(
                        "Premature exit: iterator returned no batch; processed %d / %d intended.\n",
                        $processed, $this->willProcess
                    ));
                }
                break;
            }

            if ($this->maxRecords > 0 && $processed + count($batch) > $this->maxRecords) {
                $batch = array_slice($batch, 0, $this->maxRecords - $processed);
            }

            if ($this->verbose) {
                echo sprintf(
                    "Batch starting: size=%d processed %d / %d\n",
                    count($batch), $processed, ($this->willProcess ?: ($this->maxRecords ?: 0))
                );
            }

            if ($this->outJson) {
                $ids = !empty($this->intentIds)
                    ? $this->intentIds
                    : array_map(function($r){ return (int)$r['sentenceid']; }, $batch);
                $dir = dirname($this->outJson);
                if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                $snapshot = [
                    'intent' => ['count' => ($this->willProcess ?: count($ids)), 'ids' => $ids],
                    'progress' => ['processed' => $processed, 'total' => ($this->willProcess ?: count($ids))],
                    'created_at' => date('c'),
                ];
                file_put_contents($this->outJson, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $out = $this->processSentenceBatch($batch);
            $processed += $out['processed'];
            $results['processed'] += $out['processed'];
            $results['embeddings'] += $out['embeddings'];
            $results['metrics'] += $out['metrics'];
            $results['errors'] += $out['errors'];
            if ($this->verbose) {
                $t = $out['timing'] ?? ['embed_ms'=>0,'metrics_ms'=>0,'db_ms'=>0,'total_ms'=>0];
                $eps = ($t['total_ms'] ?? 0) > 0 ? sprintf('%.2f', ($out['processed'] / (($t['total_ms'])/1000.0))) : 'n/a';
                echo sprintf(
                    "Batch: processed=%d (%d / %d) emb=%d met=%d err=%d | time total=%.1fms embed=%.1fms metrics=%.1fms db=%.1fms | throughput=%s sent/s\n",
                    $out['processed'], $results['processed'], ($this->willProcess ?: ($this->maxRecords ?: 0)), $out['embeddings'], $out['metrics'], $out['errors'],
                    (float)($t['total_ms'] ?? 0), (float)($t['embed_ms'] ?? 0), (float)($t['metrics_ms'] ?? 0), (float)($t['db_ms'] ?? 0), $eps
                );
            }
            if (!empty($out['ids'])) {
                $results['ids'] = array_merge($results['ids'], $out['ids']);
            }
            if (!empty($out['timing'])) {
                $results['timing']['embed_ms'] += (float)($out['timing']['embed_ms'] ?? 0);
                $results['timing']['metrics_ms'] += (float)($out['timing']['metrics_ms'] ?? 0);
                $results['timing']['db_ms'] += (float)($out['timing']['db_ms'] ?? 0);
                $results['timing']['total_ms'] += (float)($out['timing']['total_ms'] ?? 0);
            }

            if ($this->outJson) {
                $dir = dirname($this->outJson);
                if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                $snapshot = [
                    'intent' => ['count' => ($this->willProcess ?: count($this->intentIds)), 'ids' => $this->intentIds],
                    'progress' => [
                        'processed' => $results['processed'],
                        'total' => ($this->willProcess ?: ($this->maxRecords ?: 0)),
                        'embeddings' => $results['embeddings'],
                        'metrics' => $results['metrics'],
                        'errors' => $results['errors'],
                        'ids' => $results['ids'],
                        'timing' => $results['timing'],
                    ],
                    'created_at' => date('c'),
                ];
                file_put_contents($this->outJson, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
                    fwrite(STDERR, "Write mismatch: target={$targetCount} updatedEmb={$updatedEmb} updatedMet={$updatedMet}\n");
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
                fwrite(STDERR, 'Exception during write: ' . $e->getMessage() . "\n");
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
        ];
    }

    private function isValidVector($vec): bool
    {
        if (!is_array($vec) || count($vec) !== self::EXPECTED_VECTOR_DIMS) return false;
        foreach ($vec as $v) {
            if (!is_float($v) && !is_int($v)) return false;
            if (!is_finite((float)$v)) return false;
        }
        return true;
    }
}
