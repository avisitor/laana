<?php

namespace HawaiianSearch;

/**
 * Search Relevance Workbench workflow for OpenSearch hybrid document search
 * (the `hybriddoc` mode): build a query set, export pooled candidates for
 * human rating, import the ratings as judgments, run the HYBRID_OPTIMIZER
 * experiment, and apply the best normalization/combination variant to
 * config/search_pipeline.json.
 */
class RelevanceWorkbench
{
    private const SRW = '/_plugins/_search_relevance';
    public const CSV_HEADER = ['query', 'doc_id', 'sourcename', 'date', 'snippet', 'rating'];

    private OpenSearchClient $client;
    private string $modelId;
    private string $index;

    public function __construct(OpenSearchClient $client, string $modelId)
    {
        $this->client = $client;
        $this->modelId = $modelId;
        $this->index = $client->getDocumentsIndexName();
    }

    /**
     * Most frequent distinct search terms from search-stats rows
     * (['searchterm' => ..., 'pattern' => ...]); regex searches are skipped.
     * Quotes, backslashes and tags are stripped: the Workbench rejects them,
     * and hybrid search does not use phrase syntax.
     */
    public static function topQueries(array $statRows, int $limit): array
    {
        $counts = [];
        $first = [];
        foreach ($statRows as $row) {
            $term = trim(preg_replace('/\s+/u', ' ', str_replace(['"', '\\'], ' ', strip_tags((string)($row['searchterm'] ?? '')))));
            if ($term === '' || ($row['pattern'] ?? '') === 'regex' || mb_strlen($term) < 2) {
                continue;
            }
            $key = mb_strtolower($term);          // match is case-insensitive
            $first[$key] ??= $term;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        return array_map(fn($k) => $first[$k], array_slice(array_keys($counts), 0, $limit));
    }

    public function createQuerySet(string $name, array $queries): string
    {
        return $this->srw('PUT', '/query_sets', [
            'name' => $name,
            'description' => 'Noiiolelo hybriddoc queries',
            'sampling' => 'manual',
            'querySetQueries' => array_map(fn($q) => ['queryText' => $q], array_values($queries)),
        ])['query_set_id'];
    }

    /**
     * Pool the top $depth documents per query from keyword, vector and the
     * current hybrid configuration, and write one CSV row per (query, doc)
     * with an empty rating column (0 = irrelevant .. 3 = highly relevant).
     */
    public function exportCandidates(array $queries, string $csvPath, int $depth): int
    {
        $out = fopen($csvPath, 'w');
        if ($out === false) {
            throw new \RuntimeException("Cannot write $csvPath");
        }
        fputcsv($out, self::CSV_HEADER);
        $rows = 0;
        foreach ($queries as $q) {
            $ids = [];
            foreach ([$this->matchQuery($q), $this->neuralQuery($q, $depth)] as $query) {
                $ids = array_merge($ids, $this->search(['size' => $depth, '_source' => false, 'query' => $query]));
            }
            $ids = array_merge($ids, $this->search(['size' => $depth, '_source' => false, 'query' => $this->hybridQuery($q, $depth)], true));
            $ids = array_values(array_unique($ids));
            foreach ($this->describe($q, $ids) as $doc) {
                fputcsv($out, [$q, $doc['id'], $doc['sourcename'], $doc['date'], $doc['snippet'], '']);
                $rows++;
            }
        }
        fclose($out);
        return $rows;
    }

    /** Import rated CSV rows as an IMPORT_JUDGMENT list; unrated rows are skipped. */
    public function importJudgments(string $csvPath, string $name): array
    {
        $byQuery = [];
        $skipped = 0;
        foreach (self::readRatings($csvPath) as $r) {
            if ($r['rating'] === null) {
                $skipped++;
                continue;
            }
            $byQuery[$r['query']][] = ['docId' => $r['doc_id'], 'rating' => number_format($r['rating'], 3, '.', '')];
        }
        if (empty($byQuery)) {
            throw new \RuntimeException("No rated rows in $csvPath (fill the rating column with 0-3)");
        }
        $ratings = [];
        foreach ($byQuery as $q => $list) {
            $ratings[] = ['query' => (string)$q, 'ratings' => $list];
        }
        $id = $this->srw('PUT', '/judgments', [
            'name' => $name,
            'description' => 'Human ratings exported by scripts/os_relevance.php',
            'type' => 'IMPORT_JUDGMENT',
            'judgmentRatings' => $ratings,
        ])['judgment_id'];
        return ['id' => $id, 'queries' => array_keys($byQuery), 'rated' => array_sum(array_map('count', $byQuery)), 'skipped' => $skipped];
    }

    /** Parse a rating CSV; ratings must be blank or an integer 0-3 (fails loudly otherwise). */
    public static function readRatings(string $csvPath): array
    {
        $in = @fopen($csvPath, 'r');
        if ($in === false) {
            throw new \RuntimeException("Cannot read $csvPath");
        }
        $header = fgetcsv($in);
        if ($header !== self::CSV_HEADER) {
            throw new \RuntimeException("$csvPath: unexpected header " . json_encode($header));
        }
        $rows = [];
        for ($line = 2; ($r = fgetcsv($in)) !== false; $line++) {
            $rating = trim((string)($r[5] ?? ''));
            if ($rating !== '' && !preg_match('/^[0-3]$/', $rating)) {
                throw new \RuntimeException("$csvPath line $line: rating must be 0-3 or blank, got '$rating'");
            }
            $rows[] = ['query' => $r[0], 'doc_id' => $r[1], 'rating' => $rating === '' ? null : (int)$rating];
        }
        fclose($in);
        return $rows;
    }

    public function createSearchConfiguration(string $name, int $k = 50): string
    {
        $query = ['query' => $this->hybridQuery('%SearchText%', $k)];
        return $this->srw('PUT', '/search_configurations', [
            'name' => $name,
            'query' => json_encode($query, JSON_UNESCAPED_UNICODE),
            // The Workbench rejects aliases; resolve to the index serving now.
            'index' => $this->client->getDocumentsConcreteName(),
        ])['search_configuration_id'];
    }

    /** Run HYBRID_OPTIMIZER and wait for it to finish; returns the experiment id. */
    public function runHybridOptimizer(string $querySetId, string $configId, string $judgmentId, int $size = 10, int $timeoutSec = 3600): string
    {
        $id = $this->srw('PUT', '/experiments', [
            'querySetId' => $querySetId,
            'searchConfigurationList' => [$configId],
            'judgmentList' => [$judgmentId],
            'size' => $size,
            'type' => 'HYBRID_OPTIMIZER',
        ])['experiment_id'];
        for ($waited = 0; $waited < $timeoutSec; $waited += 5) {
            $status = $this->srw('GET', "/experiments/$id")['hits']['hits'][0]['_source']['status'] ?? '';
            if ($status === 'COMPLETED') {
                return $id;
            }
            if ($status === 'ERROR' || $status === 'FAILED') {
                throw new \RuntimeException("Experiment $id ended with status $status");
            }
            sleep(5);
        }
        throw new \RuntimeException("Experiment $id did not finish within {$timeoutSec}s");
    }

    /**
     * Variants of a finished experiment, metrics averaged over queries,
     * best NDCG first. Each: ['parameters' => [...], 'metrics' => ['NDCG@10' => ..], 'queries' => n].
     */
    public function variantResults(string $experimentId): array
    {
        $variants = $this->searchAll('search-relevance-experiment-variant', ['term' => ['experimentId' => $experimentId]]);
        $evalIds = array_values(array_filter(array_map(fn($v) => $v['results']['evaluationResultId'] ?? null, $variants)));
        $evals = [];
        foreach (array_chunk($evalIds, 500) as $chunk) {
            foreach ($this->searchAll('search-relevance-evaluation-result', ['ids' => ['values' => $chunk]]) as $e) {
                $evals[$e['id']] = $e;
            }
        }
        $groups = [];
        foreach ($variants as $v) {
            $e = $evals[$v['results']['evaluationResultId'] ?? ''] ?? null;
            if ($e === null) {
                continue;
            }
            $key = json_encode($v['parameters']);
            $groups[$key]['parameters'] = $v['parameters'];
            foreach ($e['metrics'] as $m) {
                $groups[$key]['sums'][$m['metric']] = ($groups[$key]['sums'][$m['metric']] ?? 0) + (float)$m['value'];
            }
            $groups[$key]['queries'] = ($groups[$key]['queries'] ?? 0) + 1;
        }
        $results = [];
        foreach ($groups as $g) {
            $metrics = array_map(fn($sum) => $sum / $g['queries'], $g['sums']);
            ksort($metrics);
            $results[] = ['parameters' => $g['parameters'], 'metrics' => $metrics, 'queries' => $g['queries']];
        }
        usort($results, fn($a, $b) => self::ndcg($b['metrics']) <=> self::ndcg($a['metrics']));
        return $results;
    }

    public static function ndcg(array $metrics): float
    {
        foreach ($metrics as $name => $value) {
            if (str_starts_with($name, 'NDCG')) {
                return (float)$value;
            }
        }
        return 0.0;
    }

    /** The search_pipeline.json body equivalent to an optimizer variant's parameters. */
    public static function pipelineBodyFor(array $parameters): array
    {
        $combination = strtolower((string)($parameters['combination'] ?? ''));
        if ($combination === 'rrf') {
            $rrf = ['technique' => 'rrf'];
            if (isset($parameters['rank_constant'])) {
                $rrf['rank_constant'] = (int)$parameters['rank_constant'];
            }
            return [
                'description' => 'Hybrid search (RRF) chosen by Search Relevance Workbench',
                'phase_results_processors' => [['score-ranker-processor' => ['combination' => $rrf]]],
            ];
        }
        $weights = array_map('floatval', (array)($parameters['weights'] ?? []));
        $combine = ['technique' => $combination];
        if ($weights) {
            $combine['parameters'] = ['weights' => $weights];
        }
        return [
            'description' => 'Hybrid search normalization chosen by Search Relevance Workbench',
            'phase_results_processors' => [['normalization-processor' => [
                'normalization' => ['technique' => strtolower((string)$parameters['normalization'])],
                'combination' => $combine,
            ]]],
        ];
    }

    public function matchQuery(string $text): array
    {
        return ['match' => ['text' => ['query' => $text]]];
    }

    public function neuralQuery(string $text, int $k): array
    {
        return ['neural' => ['text_vector_1024' => ['query_text' => $text, 'model_id' => $this->modelId, 'k' => $k]]];
    }

    /** Same shape as OpenSearchQueryBuilder::hybridQuery, embedding inside OpenSearch. */
    public function hybridQuery(string $text, int $k): array
    {
        return ['hybrid' => ['queries' => [$this->matchQuery($text), $this->neuralQuery($text, $k)]]];
    }

    private function search(array $body, bool $hybrid = false): array
    {
        $path = "/{$this->index}/_search" . ($hybrid ? '?search_pipeline=' . OpenSearchClient::searchPipelineConfig()['name'] : '');
        return array_column($this->client->rawRequest('POST', $path, $body)['hits']['hits'] ?? [], '_id');
    }

    /** sourcename/date and a keyword-highlighted snippet for each id, in the given order. */
    private function describe(string $query, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $hits = $this->client->rawRequest('POST', "/{$this->index}/_search", [
            'size' => count($ids),
            '_source' => ['sourcename', 'date'],
            'query' => ['bool' => ['filter' => [['ids' => ['values' => $ids]]], 'should' => [$this->matchQuery($query)]]],
            'highlight' => ['fields' => ['text' => ['fragment_size' => 300, 'number_of_fragments' => 1, 'no_match_size' => 300]]],
        ])['hits']['hits'] ?? [];
        $byId = [];
        foreach ($hits as $h) {
            $snippet = preg_replace('/\s+/u', ' ', $h['highlight']['text'][0] ?? '');
            $byId[$h['_id']] = ['id' => $h['_id'], 'sourcename' => $h['_source']['sourcename'] ?? '', 'date' => $h['_source']['date'] ?? '',
                'snippet' => str_replace(['<em>', '</em>'], ['[', ']'], $snippet)];
        }
        return array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));
    }

    private function searchAll(string $index, array $query): array
    {
        $res = $this->client->rawRequest('POST', "/$index/_search", ['size' => 10000, 'query' => $query]);
        return array_column($res['hits']['hits'] ?? [], '_source');
    }

    private function srw(string $method, string $path, ?array $body = null): array
    {
        return $this->client->rawRequest($method, self::SRW . $path, $body);
    }
}
