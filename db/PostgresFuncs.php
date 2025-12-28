<?php
require_once __DIR__ . '/funcs.php';
require_once __DIR__ . '/../env-loader.php';

class PostgresLaana extends Laana {
    public function __construct() {
        $this->conn = $this->connect();
    }

    public function connect($dsn = null, $options = false) {
        // Load env and read PG_* variables
        $env = loadEnv(__DIR__ . '/../.env');
        $host = $env['PG_HOST'] ?? getenv('PG_HOST') ?? 'localhost';
        $port = $env['PG_PORT'] ?? getenv('PG_PORT') ?? '5432';
        $db   = $env['PG_DATABASE'] ?? getenv('PG_DATABASE') ?? ($env['PG_DB'] ?? getenv('PG_DB') ?? '');
        $user = $env['PG_USER'] ?? getenv('PG_USER') ?? '';
        $pass = $env['PG_PASSWORD'] ?? getenv('PG_PASSWORD') ?? '';
        $dsnOverride = $env['PG_DSN'] ?? getenv('PG_DSN') ?? null;

        $dsn = $dsnOverride ?: "pgsql:host=$host;port=$port;dbname=$db";
        try {
            $conn = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Ensure UTF-8
            $conn->exec("SET client_encoding TO 'UTF8'");
            debuglog([ 'dsn' => $dsn, 'user' => $user, 'db' => $db, 'host' => $host, 'port' => $port ], 'PostgresLaana::connect');
            return $conn;
        } catch (PDOException $e) {
            echo "Postgres connection failed: " . $e->getMessage();
            debuglog("Postgres connection failed: " . $e->getMessage());
        }
    }

    // Override searches to use Postgres full-text or regex where needed
    public function getSentences($term, $pattern, $pageNumber = -1, $options = []) {
        $funcName = "PostgresLaana::getSentences";
        debuglog($options, "$funcName($term,$pattern,$pageNumber)");

        // Optimize query planner for full-text searches with joins
        // Disable nested loop joins to force hash/merge joins which evaluate WHERE clauses first
        $this->conn->exec("SET LOCAL enable_nestloop = off");

        $countOnly = isset($options['count']) ? $options['count'] : false;
        $nodiacriticals = isset($options['nodiacriticals']) ? $options['nodiacriticals'] : false;
        $pageSize = $options['limit'] ?? $this->pageSize;
        $normalizedTerm = normalizeString($term);
        $search = "hawaiiantext";
        $values = [];
        
        // Helper function to wrap query with optimized CTE for performance
        $wrapWithCTE = function($whereClause, $bindValues) use ($search, $pageSize, $pageNumber, $options, &$limitApplied) {
            // Determine requested limit - prefer explicit limit from options over page-based calculation
            $requestedLimit = 10; // default
            if (isset($options['limit'])) {
                $requestedLimit = intval($options['limit']);
            } else if ($pageNumber >= 0) {
                $requestedLimit = $pageSize;
            }
            
            // Determine ordering - use provided order or default to random
            $orderClause = 'RANDOM()';
            $useRandomPool = true;
            if (!empty($options['orderby'])) {
                $orderBy = $options['orderby'];
                // Convert 'random' to 'RANDOM()' for PostgreSQL
                if ($orderBy === 'random') {
                    $orderClause = 'RANDOM()';
                    $useRandomPool = true;
                } else {
                    $orderClause = $orderBy;
                    $useRandomPool = false;
                }
            }
            
            // For random ordering, fetch 50x pool for pseudo-randomness
            // For deterministic ordering, apply ordering in CTE for true sorted results
            if ($useRandomPool) {
                $poolSize = min($requestedLimit * 50, 5000);
                $sql = "WITH matched_pool AS (
                    SELECT sentences.sentenceid, sentences.sourceid, $search as hawaiianText 
                    FROM sentences
                    WHERE $whereClause
                    LIMIT $poolSize
                )
                SELECT s.authors,s.date,s.sourcename,s.sourceid,s.link,o.hawaiianText,o.sentenceid,m.hawaiian_word_ratio,m.word_count,m.length,m.entity_count,m.frequency 
                FROM matched_pool o 
                LEFT JOIN sources s ON s.sourceid = o.sourceid 
                LEFT JOIN sentence_metrics m ON m.sentenceid = o.sentenceid
                ORDER BY $orderClause
                LIMIT $requestedLimit";
            } else {
                // Deterministic ordering: Use LATERAL join for index-friendly scanning
                // Scan sources table by ordering column, use LATERAL to find matching sentences
                // This allows early termination and efficient use of indexes
                $sql = "SELECT s.authors, s.date, s.sourcename, s.sourceid, s.link, 
                               sent.hawaiianText, sent.sentenceid,
                               m.hawaiian_word_ratio, m.word_count, m.length, m.entity_count, m.frequency
                FROM sources s
                CROSS JOIN LATERAL (
                    SELECT sentenceid, sourceid, $search as hawaiianText
                    FROM sentences
                    WHERE sourceid = s.sourceid AND $whereClause
                    LIMIT 1
                ) sent
                LEFT JOIN sentence_metrics m ON m.sentenceid = sent.sentenceid
                WHERE sent.sentenceid IS NOT NULL
                ORDER BY s.$orderClause
                LIMIT $requestedLimit";
            }
            
            $limitApplied = true;
            return [$sql, $bindValues];
        };

        // When nodiacriticals is set, use Postgres unaccent on-the-fly
        // and rely on functional indexes created on unaccent(hawaiianText)
        $useUnaccent = $nodiacriticals ? true : false;
        if ($useUnaccent) {
            $term = $normalizedTerm;
        }

        $targets = $countOnly ? "count(*) count" : "s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid,m.hawaiian_word_ratio,m.word_count,m.length,m.entity_count,m.frequency";

        // Strip quotes if present
        $term = trim($term, '"');

        if ($pattern === 'regex') {
            // Postgres uses POSIX regex operators ~ (case-sensitive) and ~* (case-insensitive)
            $values = ['term' => $term];
            if ($countOnly) {
                $sql = "select $targets from sentences s where " . ($useUnaccent ? "unaccent(s.$search)" : "s.$search") . " ~* :term";
            } else {
                $whereClause = ($useUnaccent ? "unaccent($search)" : $search) . " ~* :term";
                list($sql, $values) = $wrapWithCTE($whereClause, $values);
            }
        } else if ($pattern === 'exact') {
            // Accelerate exact phrase using trigram index via ILIKE '%term%'
            // Enforce word boundaries with a secondary regex filter.
            $regex = preg_quote($term, '/');
            $values = ['term' => "%$term%", 'regex' => "\\m$regex\\M"];
            if ($countOnly) {
                $likeExpr = ($useUnaccent ? "unaccent(o.$search)" : "o.$search") . " ILIKE :term";
                $regexExpr = ($useUnaccent ? "unaccent(o.$search)" : "o.$search") . " ~* :regex";
                $sql = "select $targets from sentences o where $likeExpr and $regexExpr";
            } else {
                $likeExpr = ($useUnaccent ? "unaccent($search)" : $search) . " ILIKE :term";
                $regexExpr = ($useUnaccent ? "unaccent($search)" : $search) . " ~* :regex";
                $whereClause = "$likeExpr AND $regexExpr";
                list($sql, $values) = $wrapWithCTE($whereClause, $values);
            }
        } else if ($pattern === 'all') {
            // All words required -> to_tsquery with AND
            $words = preg_split("/[\s,\?!\.\;\:\(\)]+/", $term);
            $ts = implode(' & ', array_filter($words));
            $values = ['term' => $ts];
            $tsExpr = "to_tsvector('simple', " . ($useUnaccent ? "unaccent($search)" : $search) . ") @@ to_tsquery('simple', :term)";
            if ($countOnly) {
                $sql = "select $targets from sentences o where $tsExpr";
            } else {
                list($sql, $values) = $wrapWithCTE($tsExpr, $values);
            }
        } else if ($pattern === 'near') {
            // Adjacent tokens (phrase-like) using <-> operator in tsquery
            // Build a tsquery like: tok1 <-> tok2 <-> tok3
            $tokens = array_values(array_filter(preg_split("/[\s,\?!\.\;\:\(\)]+/", $term)));
            if (count($tokens) < 2) {
                // Fallback to any for single token
                $values = ['term' => $term];
                $tsExpr = "to_tsvector('simple', " . ($useUnaccent ? "unaccent($search)" : $search) . ") @@ plainto_tsquery('simple', :term)";
            } else {
                $nearTs = implode(' <-> ', $tokens);
                $values = ['term' => $nearTs];
                $tsExpr = "to_tsvector('simple', " . ($useUnaccent ? "unaccent($search)" : $search) . ") @@ to_tsquery('simple', :term)";
            }
            if ($countOnly) {
                $sql = "select $targets from sentences o where $tsExpr";
            } else {
                list($sql, $values) = $wrapWithCTE($tsExpr, $values);
            }
        } else if ($pattern === 'clause') {
            // Direct SQL clause
            $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID left join sentence_metrics m on m.sentenceid = o.sentenceid where $term";
            $values = [];
        } else if ($pattern === 'fuzzy') {
            // Fuzzy match using pg_trgm similarity on unaccented text
            // Supports threshold via options['threshold'] (default 0.3)
            $threshold = isset($options['threshold']) ? floatval($options['threshold']) : 0.3;
            // Set local similarity threshold for this session
            $this->conn->exec("SET LOCAL pg_trgm.similarity_threshold = " . $threshold);
            $values = ['term' => $term];
            $left = ($useUnaccent ? "immutable_unaccent(o.$search)" : "o.$search");
            $simExpr = "$left % :term"; // % operator uses similarity()
            if ($countOnly) {
                // Count-only can use direct sentences filter without join
                $sql = "select $targets from sentences o where $simExpr";
            } else {
                // Optimize fuzzy: use CTE with LIMIT before joining to sources
                $requestedLimit = isset($options['limit']) ? intval($options['limit']) : 10;
                $poolSize = min($requestedLimit * 50, 5000);
                $scoreExpr = "similarity($left, :term)";
                
                $sql = "WITH matched_pool AS (
                    SELECT o.sentenceid, o.sourceid, o.$search as hawaiianText, $scoreExpr as score 
                    FROM sentences o 
                    WHERE $simExpr
                    ORDER BY score DESC
                    LIMIT $poolSize
                )
                SELECT s.authors,s.date,s.sourcename,s.sourceid,s.link,sub.hawaiianText,sub.sentenceid,sub.score,m.hawaiian_word_ratio,m.word_count,m.length,m.entity_count,m.frequency 
                FROM matched_pool sub 
                LEFT JOIN sources s ON s.sourceid = sub.sourceid 
                LEFT JOIN sentence_metrics m ON m.sentenceid = sub.sentenceid
                ORDER BY sub.score DESC";
                
                $limitApplied = true;
                $preferScoreOrder = true;
                $secondaryOrder = isset($options['orderby']) ? $options['orderby'] : null;
            }
        } else if ($pattern === 'vector') {
            // Vector-only search: order by cosine similarity, require embedding
            $values = ['query_vec' => $options['query_vec'] ?? null];
            if (empty($values['query_vec'])) {
                // Without a query vector, return empty result set quickly
                return [];
            }
            $targets = $countOnly ? "count(*) count" : "s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid, (1 - (o.embedding <=> (:query_vec)::vector)) as score,m.hawaiian_word_ratio,m.word_count,m.length,m.entity_count,m.frequency";
            if ($countOnly) {
                $sql = "select $targets from sentences o where o.embedding is not null";
            } else {
                $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID left join sentence_metrics m on m.sentenceid = o.sentenceid where o.embedding is not null order by score desc";
            }
        } else if ($pattern === 'hybrid') {
            // Hybrid: combine keyword ranking, vector similarity, and quality metrics
            // Requires laana.sentences.embedding populated via pgvector ingestion
            $useUnaccent = $nodiacriticals ? true : false;
            $values = ['term' => $term];
            $wText = isset($options['w_text']) ? floatval($options['w_text']) : 1.0;
            $wVec = isset($options['w_vec']) ? floatval($options['w_vec']) : 1.5;
            $wQual = isset($options['w_qual']) ? floatval($options['w_qual']) : 0.5;
            $textExpr = ($useUnaccent ? "unaccent(o.$search)" : "o.$search");
            $tsRank = "ts_rank_cd(to_tsvector('simple', $textExpr), plainto_tsquery('simple', unaccent(:term)))";
            // Vector similarity: 1 - cosine distance; if embedding is null, treat as 0
            $vecSim = "CASE WHEN o.embedding IS NULL THEN 0 ELSE (1 - (o.embedding <=> (:query_vec)::vector)) END";
            // Quality metrics: hawaiian_word_ratio
            // Quality metric column may not exist in Postgres; default to 0 in SQL path
            $qual = "0";
            // Default combined score
            $score = "$wText * $tsRank + $wVec * $vecSim + $wQual * $qual";
            // Build a CTE computing query vector once
            // Expect caller to pass pgvector array literal string in options['query_vec'] (e.g., "[0.01,0.02,...]")
            $values['query_vec'] = $options['query_vec'] ?? null;
            if (!$values['query_vec']) {
                // No vector provided; proceed with text + quality only
                // This keeps hybrid usable when provider cannot fetch a vector.
                $vecSim = "0";
                $score = "$wText * $tsRank + $wQual * $qual";
            } else {
                // Vector present: compute explicit vec_score and qual, then score
                $vecScore = "(1 - (o.embedding <=> (:query_vec)::vector))";
                $score = "$wVec * $vecScore + $wQual * $qual";
            }
            $targets = $countOnly ? "count(*) count" : "s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid, $score as score,m.hawaiian_word_ratio,m.word_count,m.length,m.entity_count,m.frequency";
            $tsFilter = "to_tsvector('simple', $textExpr) @@ plainto_tsquery('simple', unaccent(:term))";
            if ($countOnly) {
                // Count rows matching text filter (hybrid count only)
                $sql = "select $targets from sentences o where ($tsFilter)";
            } else {
                // For full results: if we have a query vector, delegate to vector-only SQL then compute hybrid score in PHP
                if (!empty($values['query_vec'])) {
                    $vectorTargets = "s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid, (1 - (o.embedding <=> (:query_vec)::vector)) as vec_score,m.hawaiian_word_ratio,m.word_count,m.length,m.entity_count,m.frequency";
                    $sql = "select $vectorTargets from sentences o inner join sources s on s.sourceID = o.sourceID left join sentence_metrics m on m.sentenceid = o.sentenceid where o.embedding is not null order by vec_score desc";
                    // Execute vector query, then compute hybrid score in PHP and sort
                    debuglog(['sql' => $sql, 'values' => $values], "$funcName hybrid->vector SQL");
                    // Bind only query_vec to avoid extraneous params
                    $vecValues = ['query_vec' => $values['query_vec']];
                    $rows = $this->getDBRows($sql, $vecValues);
                    foreach ($rows as &$r) {
                        $vec = isset($r['vec_score']) ? floatval($r['vec_score']) : 0.0;
                        $r['score'] = $wVec * $vec; // no qual available in Postgres schema
                    }
                    // Sort by score desc
                    usort($rows, function($a, $b) {
                        $sa = $a['score'] ?? 0;
                        $sb = $b['score'] ?? 0;
                        if ($sa == $sb) return 0;
                        return ($sa > $sb) ? -1 : 1;
                    });
                    // Apply limit/offset for paging after sort
                    if ($pageNumber >= 0) {
                        $offset = $pageNumber * $pageSize;
                        $rows = array_slice($rows, $offset, $pageSize);
                    } else if (isset($options['limit'])) {
                        $rows = array_slice($rows, 0, intval($options['limit']));
                    }
                    debuglog($rows, "$funcName hybrid vector-present result");
                    return $rows;
                } else {
                    // No vector: require text match
                    $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID left join sentence_metrics m on m.sentenceid = o.sentenceid where ($tsFilter) order by score desc";
                }
            }
        } else { // 'any' default natural language
            $values = ['term' => $term];
            $tsExpr = ($useUnaccent ? "to_tsvector('simple', unaccent(o.$search))" : "to_tsvector('simple', o.$search)") . " @@ plainto_tsquery('simple', :term)";
            if ($countOnly) {
                $sql = "select $targets from sentences o where $tsExpr";
            } else {
                // For performance with randomness: fetch a larger pool (50x requested), 
                // then randomize that pool and take the requested limit
                $requestedLimit = 10; // default
                if ($pageNumber >= 0) {
                    $requestedLimit = $pageSize;
                } else if (isset($options['limit'])) {
                    $requestedLimit = intval($options['limit']);
                }
                
                // Fetch 50x more results for randomization pool (capped at 5000)
                $poolSize = min($requestedLimit * 50, 5000);
                
                // Use CTE to find matching sentences first (limited pool), then randomize and limit
                $sql = "WITH matched_pool AS (
                    SELECT sentenceid, sourceid, $search as hawaiianText 
                    FROM sentences 
                    WHERE " . ($useUnaccent ? "to_tsvector('simple', unaccent($search))" : "to_tsvector('simple', $search)") . " @@ plainto_tsquery('simple', :term)
                    LIMIT $poolSize
                )
                SELECT s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid,m.hawaiian_word_ratio,m.word_count,m.length,m.entity_count,m.frequency 
                FROM matched_pool o 
                INNER JOIN sources s ON s.sourceID = o.sourceID 
                LEFT JOIN sentence_metrics m ON m.sentenceid = o.sentenceid
                ORDER BY RANDOM()
                LIMIT $requestedLimit";
                
                // Mark that we've already applied the limit so it won't be added again
                $limitApplied = true;
            }
        }

        // Date/authors filters
        if (isset($options['from']) && $options['from']) {
            $sql .= " and s.date >= '" . $options['from'] . "-01-01'";
        }
        if (isset($options['to']) && $options['to']) {
            $sql .= " and s.date <= '" . $options['to'] . "-12-31'";
        }
        if (isset($options['authors']) && $options['authors']) {
            $sql .= " and s.authors = '" . $options['authors'] . "'";
        }

        // Apply ordering. For fuzzy, always order by score desc, with optional secondary ordering.
        // Skip if limit was already applied in CTE (which includes ordering)
        if (!$countOnly && !isset($limitApplied)) {
            if (!empty($preferScoreOrder)) {
                // If user asked for date ordering, ensure date exists to avoid nulls appearing unexpectedly
                if (!empty($secondaryOrder) && preg_match('/^date/', $secondaryOrder)) {
                    $sql .= ' and s.date is not null';
                }
                $sql .= ' order by score desc';
                if (!empty($secondaryOrder)) {
                    // Convert 'random' to 'RANDOM()' for PostgreSQL
                    $orderClause = ($secondaryOrder === 'random') ? 'RANDOM()' : $secondaryOrder;
                    $sql .= ', ' . $orderClause;
                }
            } else if (isset($options['orderby'])) {
                if (preg_match('/^date/', $options['orderby'])) {
                    $sql .= ' and s.date is not null';
                }
                // Convert 'random' to 'RANDOM()' for PostgreSQL
                $orderClause = ($options['orderby'] === 'random') ? 'RANDOM()' : $options['orderby'];
                $sql .= ' order by ' . $orderClause;
            }
        }

        if ($pageNumber >= 0 && !$countOnly) {
            $offset = $pageNumber * $pageSize;
            if (!isset($limitApplied)) {
                $sql .= " limit $pageSize offset $offset";
            }
        } else if (!$countOnly && isset($options['limit'])) {
            // Ensure a limit is applied for predictable performance
            if (!isset($limitApplied)) {
                $sql .= " limit " . intval($options['limit']);
            }
        }

        // Debug SQL and bound values to diagnose hybrid path
        debuglog(['sql' => $sql, 'values' => $values], "$funcName SQL");
        if (!empty($options['debug_sql'])) {
            echo "SQL: " . $sql . "\n";
            echo "VALUES: " . json_encode($values) . "\n";
        }
        $rows = $this->getDBRows($sql, $values);
        debuglog($rows, "$funcName result");
        return $rows;
    }

    public function getDocuments($term, $pattern, $pageNumber = -1, $options = []) {
        $funcName = "PostgresLaana::getDocuments";
        debuglog($options, "$funcName($term,$pattern,$pageNumber)");

        $nodiacriticals = isset($options['nodiacriticals']) ? $options['nodiacriticals'] : false;
        $requestedLimit = isset($options['limit']) ? intval($options['limit']) : 10;
        $values = [];
        
        // Handle hybrid mode specially
        if ($pattern === 'hybrid') {
            $wText = isset($options['w_text']) ? floatval($options['w_text']) : 1.0;
            $wVec = isset($options['w_vec']) ? floatval($options['w_vec']) : 1.5;
            $wQual = isset($options['w_qual']) ? floatval($options['w_qual']) : 0.5;
            
            $values['query_vec'] = $options['query_vec'] ?? null;
            $values['snippet_term'] = $term;
            
            if (!$values['query_vec']) {
                // No vector provided; return empty
                return [];
            }
            
// Clean the query as before
$stopwords = ['he', 'no', 'i', 'ma', 'ua', 'ka', 'ke', 'o'];
$words = array_filter(explode(' ', strtolower($term)), function($word) use ($stopwords) {
    return !in_array($word, $stopwords) && strlen($word) > 2;
});
$strictTsQuery = implode(' & ', $words);

// If cleaning results in empty, use original term
$searchQuery = empty($strictTsQuery) ? $term : $strictTsQuery;

$sql = "WITH semantic_search AS (
    SELECT sourceid, 
           (1 - (embedding <=> (:query_vec)::vector)) as vector_score,
           ROW_NUMBER() OVER (ORDER BY embedding <=> (:query_vec)::vector) as sem_rank
    FROM contents
    WHERE embedding IS NOT NULL
    ORDER BY embedding <=> (:query_vec)::vector
    LIMIT 300
),
keyword_search AS (
    SELECT sourceid, 
           ts_rank_cd(text_tsv, to_tsquery('simple', :strict_query)) as text_score,
           ROW_NUMBER() OVER (ORDER BY ts_rank_cd(text_tsv, to_tsquery('simple', :strict_query)) DESC) as kw_rank
    FROM contents
    WHERE text_tsv @@ to_tsquery('simple', :strict_query2)
    LIMIT 300
)
SELECT 
    s.sourceid,
    s.sourcename,
    s.authors,
    s.date,
    s.link,
    ts_headline('simple', c.text, websearch_to_tsquery('simple', :snippet_term), 
               'StartSel=<mark>, StopSel=</mark>, MaxWords=50, MinWords=25, MaxFragments=1') AS snippet,
    -- IMPROVED HYBRID FUSION
    -- Documents that match BOTH get the highest boost.
    -- Documents that match ONLY Keywords are still relevant.
    -- Documents that match ONLY Vector are buried deep.
    (CASE 
        WHEN kw.kw_rank IS NOT NULL AND sem.sem_rank IS NOT NULL THEN (1.0 / (60 + sem.sem_rank)) + (1.0 / (60 + kw.kw_rank))
        WHEN kw.kw_rank IS NOT NULL THEN (1.0 / (60 + kw.kw_rank))
        ELSE (1.0 / (1000 + sem.sem_rank)) -- Massive penalty for Bible/Amos noise
     END) AS final_score
FROM semantic_search sem
FULL OUTER JOIN keyword_search kw ON sem.sourceid = kw.sourceid
JOIN sources s ON s.sourceid = COALESCE(sem.sourceid, kw.sourceid)
JOIN contents c ON c.sourceid = s.sourceid
LEFT JOIN document_metrics m ON m.sourceid = s.sourceid
ORDER BY final_score DESC
LIMIT $requestedLimit";

// Ensure all placeholders are mapped exactly once
$values = [
    'query_vec'     => $options['query_vec'],
    'strict_query'  => $searchQuery,
    'strict_query2' => $searchQuery, // Duplicate for the WHERE clause
    'snippet_term'  => $term
];

            debuglog(['sql' => $sql, 'values' => $values], "$funcName hybrid SQL");
            $rows = $this->getDBRows($sql, $values);
            debuglog($rows, "$funcName result");
            return $rows;
        }
        
        // Build WHERE clause based on pattern for non-hybrid modes
        if ($pattern === 'exact') {
            $regex = preg_quote($term, '/');
            $values = ['term' => "%$term%", 'regex' => "\\m$regex\\M"];
            $whereClause = "text ILIKE :term AND text ~* :regex";
        } else if ($pattern === 'all') {
            $words = preg_split("/[\s,\?!\.\;\:\(\)]+/", $term);
            $ts = implode(' & ', array_filter($words));
            $values = ['term' => $ts];
            $whereClause = "to_tsvector('simple', text) @@ to_tsquery('simple', :term)";
        } else if ($pattern === 'near') {
            $words = preg_split("/[\s,\?!\.\;\:\(\)]+/", $term);
            $ts = implode(' <-> ', array_filter($words));
            $values = ['term' => $ts];
            $whereClause = "to_tsvector('simple', text) @@ to_tsquery('simple', :term)";
        } else if ($pattern === 'regex') {
            $values = ['term' => $term];
            $whereClause = "text ~* :term";
        } else if ($pattern === 'any') {
            $values = ['term' => $term];
            $whereClause = "to_tsvector('simple', text) @@ plainto_tsquery('simple', :term)";
        } else {
            // Default to simple ILIKE
            $values = ['term' => "%$term%"];
            $whereClause = "text ILIKE :term";
        }

        // Use LATERAL join pattern for ordered queries
        $orderClause = $options['orderby'] ?? 'RANDOM()';
        if ($orderClause === 'random') {
            $orderClause = 'RANDOM()';
        }
        
        // Query with snippet extraction using ts_headline for context
        $sql = "SELECT s.authors, s.date, s.sourcename, s.sourceid, s.link,
                       ts_headline('simple', COALESCE(c.text, ''), plainto_tsquery('simple', :snippet_term),
                                  'StartSel=<mark>, StopSel=</mark>, MaxWords=50, MinWords=25, MaxFragments=1') as snippet,
                       m.hawaiian_word_ratio, m.word_count, m.length, m.entity_count
                FROM sources s
                CROSS JOIN LATERAL (
                    SELECT sourceid, text
                    FROM contents
                    WHERE sourceid = s.sourceid AND $whereClause
                    LIMIT 1
                ) c
                LEFT JOIN document_metrics m ON m.sourceid = c.sourceid
                WHERE c.sourceid IS NOT NULL
                ORDER BY s.$orderClause
                LIMIT $requestedLimit";
        
        $values['snippet_term'] = $term;
        
        debuglog(['sql' => $sql, 'values' => $values], "$funcName SQL");
        $rows = $this->getDBRows($sql, $values);
        debuglog($rows, "$funcName result");
        return $rows;
    }

    protected function getGrammarMatchesOrderSql($order) {
        if( $order == 'alpha' ) {
            return ' order by s.hawaiiantext asc';
        } else if( $order == 'alpha desc' ) {
            return ' order by s.hawaiiantext desc';
        } else if( $order == 'date' ) {
            return ' and src.date IS NOT NULL order by src.date asc, s.hawaiiantext asc';
        } else if( $order == 'date desc' ) {
            return ' and src.date IS NOT NULL order by src.date desc, s.hawaiiantext desc';
        } else if( $order == 'source' ) {
            return ' order by src.sourcename asc, s.hawaiiantext asc';
        } else if( $order == 'source desc' ) {
            return ' order by src.sourcename desc, s.hawaiiantext desc';
        } else if( $order == 'length' ) {
            return ' order by length(s.hawaiiantext) asc';
        } else if( $order == 'length desc' ) {
            return ' order by length(s.hawaiiantext) desc';
        } else if( $order == 'none' ) {
            return ' order by sp.sentenceid asc';
        } else {
            // rand or default
            return ' order by random()';
        }
    }

    public function updateSimplified( $sourceID ) {
        // No simplified column in postgres, only an index
        return 0;
    }
}

?>
