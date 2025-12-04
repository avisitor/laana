<?php
require_once __DIR__ . '/funcs.php';
require_once __DIR__ . '/../env-loader.php';

class PostgresLaana extends Laana {
    public function __construct() {
        // Override connection before parent methods rely on it
        $this->connect();
        if ($this->conn == null) {
            debuglog("Unable to connect to Postgres DB");
            exit;
        }
        // Initialize parent properties
        parent::__construct();
    }

    public function connect() {
        // Load env and read PG_* variables
        $env = loadEnv(__DIR__ . '/../.env');
        $host = $env['PG_HOST'] ?? getenv('PG_HOST') ?? 'localhost';
        $port = $env['PG_PORT'] ?? getenv('PG_PORT') ?? '5432';
        $db   = $env['PG_DATABASE'] ?? getenv('PG_DATABASE') ?? ($env['PG_DB'] ?? getenv('PG_DB') ?? '');
        $user = $env['PG_USER'] ?? getenv('PG_USER') ?? '';
        $pass = $env['PG_PASSWORD'] ?? getenv('PG_PASSWORD') ?? '';
        $dsnOverride = $env['PG_DSN'] ?? getenv('PG_DSN') ?? null;

        $dsn = $dsnOverride ?: "pgsql:host=$host;port=$port;dbname=$db";
        $this->conn = null;
        try {
            $this->conn = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Ensure UTF-8
            $this->conn->exec("SET client_encoding TO 'UTF8'");
        } catch (PDOException $e) {
            echo "Postgres connection failed: " . $e->getMessage();
            debuglog("Postgres connection failed: " . $e->getMessage());
        }
    }

    // Override searches to use Postgres full-text or regex where needed
    public function getSentences($term, $pattern, $pageNumber = -1, $options = []) {
        $funcName = "PostgresLaana::getSentences";
        debuglog($options, "$funcName($term,$pattern,$pageNumber)");

        $countOnly = isset($options['count']) ? $options['count'] : false;
        $nodiacriticals = isset($options['nodiacriticals']) ? $options['nodiacriticals'] : false;
        $pageSize = $options['limit'] ?? $this->pageSize;
        $normalizedTerm = normalizeString($term);
        $search = "hawaiianText";
        $values = [];

        // When nodiacriticals is set, use Postgres unaccent on-the-fly
        // and rely on functional indexes created on unaccent(hawaiianText)
        $useUnaccent = $nodiacriticals ? true : false;
        if ($useUnaccent) {
            $term = $normalizedTerm;
        }

        $targets = $countOnly ? "count(*) count" : "s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid";

        // Strip quotes if present
        $term = trim($term, '"');

        if ($pattern === 'regex') {
            // Postgres uses POSIX regex operators ~ (case-sensitive) and ~* (case-insensitive)
            $values = ['term' => $term];
            if ($countOnly) {
                $sql = "select $targets from sentences s where " . ($useUnaccent ? "unaccent(s.$search)" : "s.$search") . " ~* :term";
            } else {
                $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where " . ($useUnaccent ? "unaccent(o.$search)" : "o.$search") . " ~* :term";
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
                $likeExpr = ($useUnaccent ? "unaccent(o.$search)" : "o.$search") . " ILIKE :term";
                $regexExpr = ($useUnaccent ? "unaccent(o.$search)" : "o.$search") . " ~* :regex";
                $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where $likeExpr and $regexExpr";
            }
        } else if ($pattern === 'all') {
            // All words required -> to_tsquery with AND
            $words = preg_split("/[\s,\?!\.\;\:\(\)]+/", $term);
            $ts = implode(' & ', array_filter($words));
            $values = ['term' => $ts];
            $tsExpr = "to_tsvector('simple', " . ($useUnaccent ? "unaccent(o.$search)" : "o.$search") . ") @@ to_tsquery('simple', :term)";
            if ($countOnly) {
                $sql = "select $targets from sentences o where $tsExpr";
            } else {
                $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where $tsExpr";
            }
        } else if ($pattern === 'near') {
            // Adjacent tokens (phrase-like) using <-> operator in tsquery
            // Build a tsquery like: tok1 <-> tok2 <-> tok3
            $tokens = array_values(array_filter(preg_split("/[\s,\?!\.\;\:\(\)]+/", $term)));
            if (count($tokens) < 2) {
                // Fallback to any for single token
                $values = ['term' => $term];
                $tsExpr = "to_tsvector('simple', " . ($useUnaccent ? "unaccent(o.$search)" : "o.$search") . ") @@ plainto_tsquery('simple', :term)";
            } else {
                $nearTs = implode(' <-> ', $tokens);
                $values = ['term' => $nearTs];
                $tsExpr = "to_tsvector('simple', " . ($useUnaccent ? "unaccent(o.$search)" : "o.$search") . ") @@ to_tsquery('simple', :term)";
            }
            if ($countOnly) {
                $sql = "select $targets from sentences o where $tsExpr";
            } else {
                $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where $tsExpr";
            }
        } else if ($pattern === 'clause') {
            // Direct SQL clause
            $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where $term";
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
                // Optimize fuzzy by ranking in a subquery using the trigram index, then join sources
                $scoreExpr = "similarity($left, :term)";
                $subselect = "select o.sentenceid, o.sourceID, o.$search as hawaiianText, $scoreExpr as score from sentences o where $simExpr";
                $sql = "select s.authors,s.date,s.sourceName,s.sourceID,s.link,sub.hawaiianText,sub.sentenceid, sub.score from (" . $subselect . ") sub inner join sources s on s.sourceID = sub.sourceID";
                // Prefer ordering by similarity score for performance and relevance
                $preferScoreOrder = true;
                $secondaryOrder = isset($options['orderby']) ? $options['orderby'] : null;
            }
        } else if ($pattern === 'hybrid') {
            // Hybrid: combine keyword ranking, vector similarity, and quality metrics
            // Requires laana.sentences.embedding populated via pgvector ingestion
            $useUnaccent = $nodiacriticals ? true : false;
            $values = ['term' => $term];
            $wText = isset($options['w_text']) ? floatval($options['w_text']) : 1.0;
            $wVec = isset($options['w_vec']) ? floatval($options['w_vec']) : 1.5;
            $wQual = isset($options['w_qual']) ? floatval($options['w_qual']) : 0.5;
            $textExpr = ($useUnaccent ? "immutable_unaccent(o.$search)" : "o.$search");
            $tsRank = "ts_rank_cd(to_tsvector('simple', $textExpr), plainto_tsquery('simple', immutable_unaccent(:term)))";
            // Vector similarity: 1 - cosine distance; if embedding is null, treat as 0
            $vecSim = "CASE WHEN o.embedding IS NULL THEN 0 ELSE (1 - (o.embedding <=> :query_vec)) END";
            // Quality metrics: hawaiian_word_ratio plus normalized length/entity_count if present
            $qual = "COALESCE(o.hawaiian_word_ratio, 0)";
            $score = "$wText * $tsRank + $wVec * $vecSim + $wQual * $qual";
            // Build a CTE computing query vector once
            $values['query_vec'] = $options['query_vec'] ?? null; // Expect caller to pass array literal
            if (!$values['query_vec']) {
                // If not provided, we cannot compute hybrid; return empty
                return [];
            }
            $targets = $countOnly ? "count(*) count" : "s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid, $score as score";
            $tsFilter = "to_tsvector('simple', $textExpr) @@ plainto_tsquery('simple', immutable_unaccent(:term))";
            $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where ($tsFilter) order by score desc";
        } else { // 'any' default natural language
            $values = ['term' => $term];
            $tsExpr = "to_tsvector('simple', " . ($useUnaccent ? "unaccent(o.$search)" : "o.$search") . ") @@ plainto_tsquery('simple', :term)";
            if ($countOnly) {
                $sql = "select $targets from sentences o where $tsExpr";
            } else {
                $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where $tsExpr";
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
        if (!$countOnly) {
            if (!empty($preferScoreOrder)) {
                // If user asked for date ordering, ensure date exists to avoid nulls appearing unexpectedly
                if (!empty($secondaryOrder) && preg_match('/^date/', $secondaryOrder)) {
                    $sql .= ' and s.date is not null';
                }
                $sql .= ' order by score desc';
                if (!empty($secondaryOrder)) {
                    $sql .= ', ' . $secondaryOrder;
                }
            } else if (isset($options['orderby'])) {
                if (preg_match('/^date/', $options['orderby'])) {
                    $sql .= ' and s.date is not null';
                }
                $sql .= ' order by ' . $options['orderby'];
            }
        }

        if ($pageNumber >= 0 && !$countOnly) {
            $offset = $pageNumber * $pageSize;
            $sql .= " limit $pageSize offset $offset";
        }

        $rows = $this->getDBRows($sql, $values);
        debuglog($rows, "$funcName result");
        return $rows;
    }

    public function updateSimplified( $sourceID ) {
        // No simplified column in postgres, only an index
        return 0;
    }
}

?>
