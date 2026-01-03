<?php 
namespace HawaiianSearch;
use Elastic\Elasticsearch\Exception\ClientResponseException;

class ElasticsearchScrollIterator
{
    private $client;
    private $index;
    private $scroll;
    private $size = -1;
    private $batchSize = 1;
    private $scrollId = null;
    private $finished = false;
    private $sourceIncludes  = [
        'doc_id',
        'groupname',
        'sourcename',
        'date',
        'sourceid',
        'authors',
    ];

    private $sourceExcludes =  [
        'hawaiian_word_ratio',
        'text',
        'sentences.text',
        'sentences.position',
        'text_chunks',
        
        'text_vector',
        'sentences.vector',
    ];

    private bool $returnFullHit = false;

    public function __construct($client, $index, $scroll = '1m', $batchSize = 1, $sourceIncludes = null, $sourceExcludes = null, bool $returnFullHit = false)
    {
        $this->client = $client->getRawClient();
        $this->index = $index;
        $this->scroll = $scroll;
        $this->batchSize = $batchSize;
        $this->sourceIncludes = $sourceIncludes ?? $this->sourceIncludes;
        $this->sourceExcludes = $sourceExcludes ?? $this->sourceExcludes;
        $this->returnFullHit = $returnFullHit;
    }

    public function getSize(): int {
        if( $this->size >= 0) {
            return $this->size;
        }

        $params = [
            'index' => $this->index,
            'body' => [
                'query' => [
                    'match_all' => new \stdClass(),
                ]
            ],
            'size' => 0,
            'track_total_hits' => true,
            'filter_path' => ['hits.total']
        ];

        try {
            $response = $this->client->search($params);
            $this->size = $response['hits']['total']['value'] ?? 0;
            return $this->size;
        } catch (Exception $e) {
            error_log("Failed to get scroll size: " . $e->getMessage());
            return 0;
        }
    }
    
    public function getNext()
    {
        if ($this->finished) {
            return null;
        }

        $response = [];
        $hits = [];
        if ($this->scrollId === null) {
            // Initial search
            $params = [
                'index' => $this->index,
                'scroll' => $this->scroll,
                'size' => $this->batchSize,
                'body' => [
                    '_source' => [
                        'includes' => $this->sourceIncludes,
                        'excludes' => $this->sourceExcludes,
                    ],
                    'query' => [
                        'match_all' => new \stdClass(),
                    ],
                ],
                'filter_path' => ['_scroll_id', 'hits.hits._source', 'hits.hits._id'],
            ];

            try {
                $response = $this->client->search($params);
            } catch (ClientResponseException $e) {
                $this->finished = true;
                echo $e->getTraceAsString() . "\n";
                return null;
            }
        } else {
            try {
                // Scroll to next batch
                $response = $this->client->scroll([
                    'scroll_id' => $this->scrollId,
                    'scroll' => $this->scroll,
                    'filter_path' => ['_scroll_id', 'hits.hits._source', 'hits.hits._id'],
                ]);
            } catch (ClientResponseException $e) {
                $this->finished = true;
                echo $e->getTraceAsString() . "\n";
                return null;
            }
        }

        // Update scroll ID
        $this->scrollId = $response['_scroll_id'] ?? null;

        // Extract hits
        $hits = $response['hits']['hits'] ?? [];

        if (empty($hits)) {
            $this->finished = true;
            return null;
        }

        // Return full hit or only _source
        if ($this->returnFullHit) {
            return $hits;
        }
        return array_map(fn($hit) => $hit['_source'], $hits);
    }
}
?>
