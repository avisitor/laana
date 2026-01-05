<?php

namespace HawaiianSearch;

class QueryBuilder
{
    public const MODES = ["matchsentence", "matchsentence_all", "termsentence", "phrasesentence", "match", "term", "phrase", "regexp", "regexpsentence", "vector", "hybrid", "vectorsentence", "hybridsentence", "knn", "knnsentence"];

    private EmbeddingClient $embeddingClient;

    public function __construct(EmbeddingClient $embeddingClient)
    {
        $this->embeddingClient = $embeddingClient;
        $this->verbose = true;
    }

    protected function print( $msg ) {
        if( $this->verbose ) {
            error_log("QueryBuilder:$msg");
        }
    }

    
    private function getTargetIndex(string $mode): string
    {
        $sentenceModes = ['matchsentence', 'matchsentence_all', 'termsentence', 'phrasesentence', 'regexpsentence', 'vectorsentence', 'hybridsentence', 'knnsentence'];
        return in_array($mode, $sentenceModes) ? 'sentences' : 'documents';
    }

    protected function embedText(string $text, string $model = EmbeddingClient::MODEL_SMALL): ?array
    {
        try {
            return $this->embeddingClient->embedText($text, 'query: ', $model);
        } catch (\Exception $e) {
            error_log("Embedding failed: " . $e->getMessage());
            return null;
        }
    }

    public static function isSentenceLevelSearchMode(string $mode): bool
    {
        return in_array($mode, [
            "vectorsentence",
            "hybridsentence",
            "knnsentence",
            "regexpsentence",
            "matchsentence",
            "matchsentence_all",
            "termsentence",
            "phrasesentence"
        ]);
    }

    /**
    * These are the ones where results are evaluated for quality based on cosine
    * similarity
    */
    public static function isVectorSearchMode(string $mode): bool
    {
        return in_array($mode, ["vector", "vectorsentence", "knn", "knnsentence", "hybrid", "hybridsentence"]);
    }

    public function build(string $mode, string $queryText, array $options = []): array
    {
        // ...existing code...
    {
        $documentsIndex = $options['documentsIndex'] ?? null;
        $sentencesIndex = $options['sentencesIndex'] ?? null;
        $query = [];
        $handlerName = "{$mode}Query";
        if (!method_exists($this, $handlerName)) {
            $handlerName = "matchQuery";
        }
        $query = $this->$handlerName($queryText, $options);


        // Select the appropriate index based on search mode
        $targetIndexType = $this->getTargetIndex($mode);
        $selectedIndex = ($targetIndexType === 'sentences') 
            ? ($sentencesIndex ?? $documentsIndex) 
            : $documentsIndex;
        
        $params = [
            'index' => $selectedIndex,
            'body' => []
        ];

        if (isset($query['query']) || isset($query['knn'])) {
            $params['body'] = $query;
        } else {
            $params['body']['query'] = $query;
        }

        // Always include necessary fields
        $enableSentenceHighlight = $options['sentence_highlight'] ?? false;
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        // Robust detection for direct sentence index query: no nested path anywhere in query
        $isDirectSentenceIndex = false;
        if ($targetIndexType === 'sentences') {
            $hasNested = false;
            if (isset($query['nested']) || (isset($query['function_score']['query']) && isset($query['function_score']['query']['nested']))) {
                $hasNested = true;
            }
            if (isset($query['bool']['should'])) {
                foreach ($query['bool']['should'] as $shouldClause) {
                    if (isset($shouldClause['nested'])) {
                        $hasNested = true;
                        break;
                    }
                }
            }
            $isDirectSentenceIndex = !$hasNested;
        }

        // Add pagination parameters
        $params['body']['from'] = $options['offset'] ?? 0;
        $params['body']['size'] = $options['k'] ?? 10;
        $params['body']['track_total_hits'] = true;

        if (isset($options['date_filter']) && !empty($options['date_filter'])) {
            if (isset($params['body']['query']['bool'])) {
                if (!isset($params['body']['query']['bool']['filter'])) {
                    $params['body']['query']['bool']['filter'] = [];
                }
                $params['body']['query']['bool']['filter'][] = $options['date_filter'];
            } else {
                $params['body']['query'] = [
                    'bool' => [
                        'must' => $params['body']['query'],
                        'filter' => [$options['date_filter']]
                    ]
                ];
            }
        }

        // Add sorting if specified
        if (!empty($options['sort']) && is_array($options['sort'])) {
            $sorts = [];
            // Add 'text' and 'text.keyword' to valid sort fields for alphabetical sorting
            $validSortFields = ['date', 'authors', 'sourcename', 'groupname', 'length', 'sentences.text.keyword', 'text', 'text.keyword'];
            // Map fields that need .keyword suffix for sorting
            $keywordFields = ['sourcename', 'groupname', 'authors', 'sentences.text', 'text'];
            
            // Handle special sorting modes
            if (isset($options['sort']['_special'])) {
                $specialMode = $options['sort']['_special'];
                switch ($specialMode) {
                    case 'random':
                        // Use Elasticsearch function_score with random_score
                        $params['body']['query'] = [
                            'function_score' => [
                                'query' => $params['body']['query'],
                                'random_score' => [
                                    'seed' => time() // Use current time as seed for randomness
                                ],
                                'boost_mode' => 'replace'
                            ]
                        ];
                        break;
                    case 'none':
                        // No sorting - results will be in index order
                        break;
                    case 'score':
                        // Default scoring behavior (do nothing, let Elasticsearch handle it)
                        break;
                }
            } else {
                // Handle regular field-based sorting
                foreach ($options['sort'] as $field => $order) {
                    if (in_array($field, $validSortFields) && in_array(strtolower($order), ['asc', 'desc'])) {
                        // For 'text' and 'text.keyword', always use 'text.keyword' for sorting
                        if ($field === 'text' || $field === 'text.keyword') {
                            $sorts[] = ['text.keyword' => ['order' => strtolower($order)]];
                        } else {
                            $sortField = in_array($field, $keywordFields) ? $field . '.keyword' : $field;
                            $sorts[] = [$sortField => ['order' => strtolower($order)]];
                        }
                    }
                }
                if (!empty($sorts)) {
                    $params['body']['sort'] = $sorts;
                }
            }
        }

        // Add search_after for pagination
        if (!empty($options['search_after']) && is_array($options['search_after'])) {
            $params['body']['search_after'] = $options['search_after'];
        }

        // Add highlight configuration
        $enableSentenceHighlight = $options['sentence_highlight'] ?? false;
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        // Robust detection for direct sentence index query: no nested path anywhere in query
        $isDirectSentenceIndex = false;
        if ($targetIndexType === 'sentences') {
            // Check for nested path in query or in function_score
            $hasNested = false;
            if (isset($query['nested']) || isset($query['function_score']['query']['nested'])) {
                $hasNested = true;
            }
            // Also check for nested in bool/should clauses
            if (isset($query['bool']['should'])) {
                foreach ($query['bool']['should'] as $shouldClause) {
                    if (isset($shouldClause['nested'])) {
                        $hasNested = true;
                        break;
                    }
                }
            }
            $isDirectSentenceIndex = !$hasNested;
        }
        if (!$this->isSentenceLevelSearchMode($mode)) {
            // Document-level highlighting
            $highlightConfig = [
                'fields' => [
                    'text' => [
                        'fragment_size' => 500,
                        'number_of_fragments' => 3,
                        'pre_tags' => ['__START_HIGHLIGHT__'],
                        'post_tags' => ['__END_HIGHLIGHT__']
                    ]
                ]
            ];
            
            // Set highlight_query based on query type
            if (isset($query['function_score']) && isset($query['function_score']['query'])) {
                // Standard function_score wrapped queries
                $highlightConfig['highlight_query'] = $query['function_score']['query'];
                
                // Special handling for regexp queries - extract terms for highlighting
                if ($mode === 'regexp' && isset($query['function_score']['query']['regexp'])) {
                    $regexTerms = $this->extractHighlightTermsFromRegex($queryText);
                    if (!empty($regexTerms)) {
                        $highlightQuery = $this->createHighlightQueryFromRegexTerms($regexTerms, 'text');
                        if ($highlightQuery) {
                            $highlightConfig['highlight_query'] = $highlightQuery;
                        }
                    }
                }
            } elseif (isset($query['regexp'])) {
                // Direct regexp queries - extract terms for highlighting
                $regexTerms = $this->extractHighlightTermsFromRegex($queryText);
                if (!empty($regexTerms)) {
                    $highlightQuery = $this->createHighlightQueryFromRegexTerms($regexTerms, 'text');
                    if ($highlightQuery) {
                        $highlightConfig['highlight_query'] = $highlightQuery;
                    }
                }
            } elseif (isset($query['script_score'])) {
                // Vector queries - create a simple match query for highlighting
                if (!empty(trim($queryText))) {
                    $highlightConfig['highlight_query'] = [
                        'match' => [
                            'text' => $queryText
                        ]
                    ];
                }
            } elseif (isset($query['knn'])) {
                // KNN queries - create a simple match query for highlighting
                if (!empty(trim($queryText))) {
                    $highlightConfig['highlight_query'] = [
                        'match' => [
                            'text' => $queryText
                        ]
                    ];
                }
            } elseif (isset($query['bool']['should'])) {
                // Hybrid queries - use the text match part for highlighting
                foreach ($query['bool']['should'] as $should_clause) {
                    if (isset($should_clause['match']['text'])) {
                        $highlightConfig['highlight_query'] = $should_clause;
                        break;
                    }
                }
            }
            
            $params['body']['highlight'] = $highlightConfig;
        } elseif ($enableSentenceHighlight) {
            $sentenceField = $diacriticSensitive
                ? ($isDirectSentenceIndex ? 'text' : 'sentences.text')
                : ($isDirectSentenceIndex ? 'text.folded' : 'sentences.text.folded');
            $highlightConfig = [
                'fields' => [
                    $sentenceField => [
                        'fragment_size' => 200,
                        'number_of_fragments' => 5,
                        'pre_tags' => ['__START_HIGHLIGHT__'],
                        'post_tags' => ['__END_HIGHLIGHT__']
                    ]
                ]
            ];
            
            // Set highlight_query based on query type for sentence-level modes
            if (isset($query['function_score']) && isset($query['function_score']['query']['nested']['query'])) {
                // Function score wrapped nested queries
                $nestedQuery = [
                    'nested' => [
                        'path' => 'sentences',
                        'query' => $query['function_score']['query']['nested']['query']
                    ]
                ];
                
                // Special handling for regexpsentence queries
                if ($mode === 'regexpsentence' && isset($query['function_score']['query']['nested']['query']['regexp'])) {
                    $regexTerms = $this->extractHighlightTermsFromRegex($queryText);
                    if (!empty($regexTerms)) {
                        $highlightQuery = $this->createHighlightQueryFromRegexTerms($regexTerms, $sentenceField);
                        if ($highlightQuery) {
                            $nestedQuery = [
                                'nested' => [
                                    'path' => 'sentences',
                                    'query' => $highlightQuery
                                ]
                            ];
                        }
                    }
                }
                
                $highlightConfig['highlight_query'] = $nestedQuery;
            } elseif (isset($query['nested']['query'])) {
                // Direct nested queries
                $nestedQuery = [
                    'nested' => [
                        'path' => 'sentences',
                        'query' => $query['nested']['query']
                    ]
                ];
                
                // Special handling for regexpsentence queries
                if ($mode === 'regexpsentence' && isset($query['nested']['query']['regexp'])) {
                    $regexTerms = $this->extractHighlightTermsFromRegex($queryText);
                    if (!empty($regexTerms)) {
                        $highlightQuery = $this->createHighlightQueryFromRegexTerms($regexTerms, $sentenceField);
                        if ($highlightQuery) {
                            $nestedQuery = [
                                'nested' => [
                                    'path' => 'sentences',
                                    'query' => $highlightQuery
                                ]
                            ];
                        }
                    }
                }
                
                $highlightConfig['highlight_query'] = $nestedQuery;
            } elseif (isset($query['regexp']) || (isset($query['function_score']['query']['regexp']))) {
                // Direct regexp queries at sentence level - extract terms for highlighting
                $regexTerms = $this->extractHighlightTermsFromRegex($queryText);
                if (!empty($regexTerms)) {
                    $highlightQuery = $this->createHighlightQueryFromRegexTerms($regexTerms, $sentenceField);
                    if ($highlightQuery) {
                        if ($isDirectSentenceIndex) {
                            $highlightConfig['highlight_query'] = $highlightQuery;
                        } else {
                            $highlightConfig['highlight_query'] = [
                                'nested' => [
                                    'path' => 'sentences',
                                    'query' => $highlightQuery
                                ]
                            ];
                        }
                    }
                }
            } elseif (isset($query['knn'])) {
                // KNN sentence queries - use simple match for direct sentence index, nested for document-level
                if (!empty(trim($queryText))) {
                    if ($isDirectSentenceIndex) {
                        $highlightConfig['highlight_query'] = [
                            'match' => [
                                $sentenceField => $queryText
                            ]
                        ];
                    } else {
                        $highlightConfig['highlight_query'] = [
                            'nested' => [
                                'path' => 'sentences',
                                'query' => [
                                    'match' => [
                                        $sentenceField => $queryText
                                    ]
                                ]
                            ]
                        ];
                    }
                }
            }
            
            $params['body']['highlight'] = $highlightConfig;
        }
    }
        // Add date filtering if provided
        if (isset($options['date_filter']) && !empty($options['date_filter'])) {
            // If the query is already a bool, add to filter; else, wrap in bool
            if (isset($params['body']['query']['bool'])) {
                if (!isset($params['body']['query']['bool']['filter'])) {
                    $params['body']['query']['bool']['filter'] = [];
                }
                $params['body']['query']['bool']['filter'][] = $options['date_filter'];
            } else {
                $params['body']['query'] = [
                    'bool' => [
                        'must' => $params['body']['query'],
                        'filter' => [$options['date_filter']]
                    ]
                ];
            }
        }
        return $params;
    }

    private function addRatioBoost(array $query): array
    {
        return [
            "function_score" => [
                "query" => $query,
                "functions" => [
                    [
                        "field_value_factor" => [
                            "field" => "hawaiian_word_ratio",
                            "modifier" => "ln1p",
                            "factor" => 1.0,
                            "missing" => 1
                        ]
                    ]
                ],
                "boost_mode" => "multiply"
            ]
        ];
    }

    public function matchQuery(string $text, array $options = []): array
    {
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text" : "text.folded";
        $baseQuery = ["match" => [$field => $text]];
        return $this->addRatioBoost($baseQuery);
    }

    public function termQuery(string $text, array $options = []): array
    {
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text" : "text.folded";
        $baseQuery = ["term" => [$field => $text]];
        return $this->addRatioBoost($baseQuery);
    }

    public function phraseQuery(string $text, array $options = []): array
    {
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text" : "text.folded";
        $baseQuery = ["match_phrase" => [$field => $text]];
        return $this->addRatioBoost($baseQuery);
    }

    public function regexpQuery(string $text, array $options = []): array
    {
        $baseQuery = [
            "bool" => [
                "should" => [
                    [
                        "regexp" => [
                            "text.raw" => [
                                "value" => $text,
                                "flags" => "ALL",
                                "case_insensitive" => true
                            ]
                        ]
                    ],
                    [
                        "nested" => [
                            "path" => "text_chunks",
                            "query" => [
                                "regexp" => [
                                    "text_chunks.chunk_text.raw" => [
                                        "value" => $text,
                                        "flags" => "ALL",
                                        "case_insensitive" => true
                                    ]
                                ]
                            ],
                            "ignore_unmapped" => true
                        ]
                    ]
                ],
                "minimum_should_match" => 1
            ]
        ];
        return $this->addRatioBoost($baseQuery);
    }

    public function matchsentenceQuery(string $text, array $options = []): array
    {
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text" : "text.folded";
        
        $baseQuery = [
            "match" => [
                $field => [
                    "query" => $text,
                    "operator" => "or" // Explicitly set to OR for "any word"
                ]
            ]
        ];
        return $this->addRatioBoost($baseQuery);
    }

    public function matchsentence_allQuery(string $text, array $options = []): array
    {
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text" : "text.folded";
        
        $baseQuery = [
            "match" => [
                $field => [
                    "query" => $text,
                    "operator" => "and" // Explicitly set to AND for "all words"
                ]
            ]
        ];
        return $this->addRatioBoost($baseQuery);
    }
    public function termsentenceQuery(string $text, array $options = []): array
    {
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text.raw" : "text.folded";
        
        $baseQuery = [
            "term" => [
                $field => $text
            ]
        ];
        return $this->addRatioBoost($baseQuery);
    }

    public function phrasesentenceQuery(string $text, array $options = []): array
    {
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text" : "text.folded";
        
        $baseQuery = [
            "match_phrase" => [
                $field => $text
            ]
        ];
        return $this->addRatioBoost($baseQuery);
    }

    public function wildcardsentenceQuery(string $text, array $options = []): array
    {
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text" : "text.folded";
        
        // Split on wildcards to create multiple wildcard queries
        // This handles patterns like "word1*word2" better
        $parts = preg_split('/\*+/', $text);
        $parts = array_filter($parts, function($part) {
            return trim($part) !== '';
        });
        
        if (count($parts) === 0) {
            // No actual search terms, just wildcards
            return [];
        }
        
        if (count($parts) === 1 && strpos($text, '*') === false && strpos($text, '?') === false) {
            // No wildcards, use regular match
            $baseQuery = [
                "match" => [
                    $field => $text
                ]
            ];
        } else {
            // Build a bool query with must clauses for each part
            $must = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $must[] = [
                        "wildcard" => [
                            $field => [
                                "value" => "*" . $part . "*",
                                "case_insensitive" => true
                            ]
                        ]
                    ];
                }
            }
            
            if (count($must) === 1) {
                $baseQuery = $must[0];
            } else {
                $baseQuery = [
                    "bool" => [
                        "must" => $must
                    ]
                ];
            }
        }
        
        return $this->addRatioBoost($baseQuery);
    }

    public function regexpsentenceQuery(string $text, array $options = []): array
    {
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text.raw" : "text.folded";
        
        $baseQuery = [
            "regexp" => [
                $field => [
                    "value" => $text,
                    "case_insensitive" => true,
                    "flags" => "ALL"
                ]
            ]
        ];
        return $this->addRatioBoost($baseQuery);
    }
    public function vectorQuery(string $text, array $options = []): array
    {
        $vector = $this->embedText($text, EmbeddingClient::MODEL_LARGE);
        if (!$vector) {
            return [];
        }
        return [
            "script_score" => [
                "query" => ["match_all" => new \stdClass()],
                "script" => [
                    "source" => "cosineSimilarity(params.query_vector, 'text_vector_1024') + 1.0",
                    "params" => ["query_vector" => $vector]
                ]
            ]
        ];
    }

    public function hybridQuery(string $text, array $options = []): array
    {
        $vector = $this->embedText($text, EmbeddingClient::MODEL_LARGE);
        if (!$vector) {
            return [];
        }
        return [
            "query" => [
                "bool" => [
                    "should" => [
                        ["match" => ["text" => $text]],
                        [
                            "script_score" => [
                                "query" => ["match_all" => new \stdClass()],
                                "script" => [
                                    "source" => "cosineSimilarity(params.query_vector, 'text_vector_1024') + 1.0",
                                    "params" => ["query_vector" => $vector]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    public function knnQuery(string $text, array $options = []): array
    {
        $vector = $this->embedText($text, EmbeddingClient::MODEL_LARGE);
        if (!$vector) {
            return [];
        }
        $k = $options['k'] ?? 10; // Default k to 10 if not provided
        return [
            "knn" => [
                "field" => "text_vector_1024",
                "query_vector" => $vector,
                "k" => $k,
                "num_candidates" => $k * 10 // A common heuristic for num_candidates
            ]
        ];
    }

    public function vectorsentenceQuery(string $text, array $options = []): array
    {
        // vectorsentence is an alias for knnsentence
        return $this->knnsentenceQuery($text, $options);
    }

    public function hybridsentenceQuery(string $text, array $options = []): array
    {
        $vector = $this->embedText($text);
        if (!$vector) {
            return [];
        }
        
        $k = $options['k'] ?? 10;
        $diacriticSensitive = $options['diacritic_sensitive'] ?? true;
        $field = $diacriticSensitive ? "text" : "text.folded";
        
        // Hybrid: combine text matching with vector similarity
        return [
            "query" => [
                "bool" => [
                    "should" => [
                        [
                            "match" => [
                                $field => $text
                            ]
                        ]
                    ]
                ]
            ],
            "knn" => [
                "field" => "vector", 
                "query_vector" => $vector,
                "k" => $k * 5,
                "num_candidates" => $k * 25,
                "boost" => 1.5  // Give vector component slight boost
            ]
        ];
    }
    public function knnsentenceQuery(string $text, array $options = []): array
    {
        $vector = $this->embedText($text);
        if (!$vector) {
            return [];
        }
        $k = $options["k"] ?? 10; // Default k to 10 if not provided
        return [
            "knn" => [
                "field" => "vector",
                "query_vector" => $vector,
                "k" => $k,
                "num_candidates" => $k * 10 // A common heuristic for num_candidates
            ]
        ];
    }

    public function buildTotalSentenceCountQuery( $selectedIndex ): ?array
    {
        return $this->buildCountQuery( "", "", $selectedIndex );
    }
    
    public function buildCountQuery(string $mode, string $queryText, string $selectedIndex, array $options = []): ?array
    {
        if (!self::isSentenceLevelSearchMode($mode) && !empty(trim($queryText))) {
            return null;
        }

        // For split indices architecture, use the sentences index for counts
        $documentsIndex = $options['documentsIndex'] ?? null;
        $sentencesIndex = $options['sentencesIndex'] ?? null;
        
        // Use sentences index for counting since we're counting sentences
        $targetIndex = $sentencesIndex ?? $selectedIndex;

        // For empty queries, return total count of all sentences using count API
        if (empty(trim($queryText)) || $mode === 'knnsentence' || $mode === 'vectorsentence') {
            $params = [
                'index' => $targetIndex,
                'body' => [
                    'query' => ['match_all' => new \stdClass()]
                ]
            ];
            $this->print( "buildCountQuery (match_all): " .
                         json_encode($params, JSON_PRETTY_PRINT) );
            return $params;
        }

        // For text-based queries, build appropriate query for sentences index
        $handlerName = "{$mode}Query";
        if (!method_exists($this, $handlerName)) {
            $handlerName = "matchsentenceQuery"; // A reasonable default
        }
        
        // Build the base query for the mode
        $baseQuery = $this->$handlerName($queryText, $options);
        $this->print( "buildCountQuery: baseQuery: " .
                      json_encode($baseQuery, JSON_PRETTY_PRINT) );

        // For split indices, extract the core query without nested structure
        $sentenceQuery = null;
        if (isset($baseQuery['nested']['query'])) {
            // Extract nested query for direct use on sentences index
            $sentenceQuery = $baseQuery['nested']['query'];
        } elseif (isset($baseQuery['function_score']['query']['nested']['query'])) {
            $sentenceQuery = $baseQuery['function_score']['query']['nested']['query'];
        } elseif (isset($baseQuery['function_score']['query'])) {
            $sentenceQuery = $baseQuery['function_score']['query'];
        } else {
            // Direct query without nesting
            $sentenceQuery = $baseQuery;
        }

        if ($sentenceQuery === null) {
            return null; // Could not determine the query body.
        }

        // Build count query for sentences index
        $params = [
            'index' => $targetIndex,
            'body' => [
                'query' => $sentenceQuery
            ]
        ];

        $this->print( "buildCountQuery: queryBody: " .
             json_encode($params, JSON_PRETTY_PRINT) );
        return $params;
    }

    /**
     * Extract simple terms from regex patterns that can be used for highlighting
     * This is a basic implementation that handles common patterns
     */
    private function extractHighlightTermsFromRegex(string $regex): array
    {
        $terms = [];
        
        // Remove common regex metacharacters and anchors
        $cleaned = str_replace(['.*', '.+', '^', '$', '\\b', '\\s', '+', '*', '?'], ' ', $regex);
        
        // Extract words that are at least 3 characters long and contain letters
        preg_match_all('/[a-zA-ZāēīōūĀĒĪŌŪ]{3,}/', $cleaned, $matches);
        
        if (!empty($matches[0])) {
            $terms = array_unique($matches[0]);
        }
        
        return $terms;
    }

    /**
     * Create a simple match query from extracted regex terms for highlighting purposes
     * Uses lowercase to ensure case-insensitive matching like the regex query
     */
    private function createHighlightQueryFromRegexTerms(array $terms, string $field): ?array
    {
        if (empty($terms)) {
            return null;
        }
        
        // Convert terms to lowercase for case-insensitive matching
        $terms = array_map('strtolower', $terms);
        
        if (count($terms) === 1) {
            return [
                "match" => [
                    $field => [
                        "query" => $terms[0],
                        "fuzziness" => 0
                    ]
                ]
            ];
        }
        
        // Multiple terms - use bool should query
        $should = [];
        foreach ($terms as $term) {
            $should[] = [
                "match" => [
                    $field => [
                        "query" => $term,
                        "fuzziness" => 0
                    ]
                ]
            ];
        }
        
        return [
            "bool" => [
                "should" => $should,
                "minimum_should_match" => 1
            ]
        ];
    }
}
