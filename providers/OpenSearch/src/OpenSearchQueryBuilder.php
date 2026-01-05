<?php

namespace HawaiianSearch;

class OpenSearchQueryBuilder extends QueryBuilder
{
    public function __construct(EmbeddingClient $embeddingClient)
    {
        parent::__construct($embeddingClient);
    }

    /**
     * OpenSearch KNN Search implementation
     */
    public function knnQuery(string $text, array $options = []): array
    {
        $vector = $this->embedText($text, EmbeddingClient::MODEL_LARGE);
        if (!$vector) {
            return [];
        }
        $k = $options['k'] ?? 10;
        return [
            "query" => [
                "knn" => [
                    "text_vector_1024" => [
                        "vector" => $vector,
                        "k" => $k
                    ]
                ]
            ]
        ];
    }

    /**
     * OpenSearch KNN Sentence Search implementation
     */
    public function knnsentenceQuery(string $text, array $options = []): array
    {
        $vector = $this->embedText($text);
        if (!$vector) {
            return [];
        }
        $k = $options['k'] ?? 10;
        return [
            "query" => [
                "knn" => [
                    "vector" => [
                        "vector" => $vector,
                        "k" => $k
                    ]
                ]
            ]
        ];
    }

    /**
     * OpenSearch Vector Search implementation (alias for KNN)
     */
    public function vectorQuery(string $text, array $options = []): array
    {
        return $this->knnQuery($text, $options);
    }

    /**
     * OpenSearch Vector Sentence Search implementation (alias for KNN)
     */
    public function vectorsentenceQuery(string $text, array $options = []): array
    {
        return $this->knnsentenceQuery($text, $options);
    }

    /**
     * OpenSearch Hybrid Search implementation
     * Combines lexical and vector search with score normalization
     */
    public function hybridQuery(string $text, array $options = []): array
    {
        $vector = $this->embedText($text, EmbeddingClient::MODEL_LARGE);
        if (!$vector) {
            return $this->matchQuery($text, $options);
        }

        $k = $options['k'] ?? 10;

        return [
            "hybrid" => [
                "queries" => [
                    [
                        "match" => [
                            "text" => [
                                "query" => $text
                            ]
                        ]
                    ],
                    [
                        "knn" => [
                            "text_vector_1024" => [
                                "vector" => $vector,
                                "k" => $k
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * OpenSearch Hybrid Sentence Search implementation
     */
    public function hybridsentenceQuery(string $text, array $options = []): array
    {
        $vector = $this->embedText($text);
        if (!$vector) {
            return $this->matchsentenceQuery($text, $options);
        }

        $k = $options['k'] ?? 10;
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text" : "text.folded";

        return [
            "hybrid" => [
                "queries" => [
                    [
                        "match" => [
                            $field => [
                                "query" => $text
                            ]
                        ]
                    ],
                    [
                        "knn" => [
                            "vector" => [
                                "vector" => $vector,
                                "k" => $k
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Override build to handle OpenSearch specific parameters like search_pipeline
     */
    public function build(string $mode, string $queryText, array $options = []): array
    {
        $params = parent::build($mode, $queryText, $options);

        // If it's a hybrid query, we need to specify the search pipeline
        if (strpos($mode, 'hybrid') !== false) {
            $params['search_pipeline'] = $options['search_pipeline'] ?? 'norm-pipeline';
            
            // Add pagination_depth if needed (required for pagination in newer OpenSearch versions)
            if (isset($params['body']['query']['hybrid'])) {
                $from = $params['body']['from'] ?? 0;
                $size = $params['body']['size'] ?? 10;
                // Set pagination_depth to cover the requested window plus a buffer
                // Default to at least 100 to ensure decent result set for normalization
                $params['body']['query']['hybrid']['pagination_depth'] = max(100, $from + $size + 50);
            }
        }

        return $params;
    }
}
