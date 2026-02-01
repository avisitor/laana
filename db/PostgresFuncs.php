<?php
require_once __DIR__ . '/funcs.php';
require_once __DIR__ . '/../env-loader.php';

class PostgresLaana extends Laana {
    public function __construct() {
        parent::__construct();
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
            // Set search path
            $conn->exec("SET search_path TO laana, public");
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
        $countOnly = !empty($options['count']);
        $nodiacriticals = !empty($options['nodiacriticals']);
        
        // 1. Session Tuning
        $this->conn->exec("SET LOCAL work_mem = '128MB'");
        $this->conn->exec("SET search_path TO laana, public");

        $pageSize = intval($options['limit'] ?? $this->pageSize);
        $offset = ($pageNumber >= 0) ? ($pageNumber * $pageSize) : 0;
        $term = trim($term, '"');
        $values = [];
        $searchVector = $nodiacriticals ? 'hawaiian_unaccent_tsv' : 'hawaiian_tsv';

        // 2. Build the WHERE clause (Same logic as before)
        if ($pattern === 'any') {
            $words = array_filter(preg_split("/[\s,]+/", $term));
            $values['tsquery'] = implode(' | ', $words);
            $where = "$searchVector @@ to_tsquery('simple', :tsquery)";
        } else {
            $values['tsquery'] = $term;
            $tsFunc = ($pattern === 'all') ? "plainto_tsquery" : "phraseto_tsquery";
            $where = "$searchVector @@ $tsFunc('simple', :tsquery)";
        }

        // 3. BRANCH: COUNT vs. DATA
        if ($countOnly) {
            // High-speed count. We don't join sources or metrics here.
            $sql = "SELECT count(*) as count FROM sentences s WHERE $where";
            $sql = $this->appendBlockedGroupWhereWithSourceAlias($sql, $values, 's');
        } else {
            // Fast data retrieval with LIMIT inside the subquery
            $sql = "SELECT s.authors, s.date, s.sourcename, s.sourceid, s.link, 
                        matched.hawaiiantext as hawaiianText, matched.sentenceid, 
                        m.hawaiian_word_ratio, m.word_count, m.length, m.entity_count, m.frequency
                    FROM (
                        SELECT sentenceid, sourceid, hawaiiantext 
                        FROM sentences 
                        WHERE $where
                        ORDER BY sentenceid DESC
                        LIMIT $pageSize OFFSET $offset
                    ) matched
                    INNER JOIN sources s ON s.sourceid = matched.sourceid
                    LEFT JOIN sentence_metrics m ON m.sentenceid = matched.sentenceid
                    ORDER BY matched.sentenceid DESC";
            $sql = $this->appendBlockedGroupWhereWithGroupAlias($sql, $values, 's');
        }

        try {
            return $this->getDBRows($sql, $values);
        } catch (Exception $e) {
            error_log("DB Error in $funcName: " . $e->getMessage());
            return [];
        }
    }

    public function refreshGrammarPatternCounts() {
        $sql = "REFRESH MATERIALIZED VIEW CONCURRENTLY laana.grammar_pattern_counts";
        try {
            $this->conn->exec($sql);
            return true;
        } catch (Exception $e) {
            error_log("DB Error in PostgresLaana::refreshGrammarPatternCounts: " . $e->getMessage());
            return false;
        }
    }

    protected function getTableRowCount(string $name): int {
        $sql = "SELECT value FROM laana.table_row_counts WHERE name = :name";
        $row = $this->getOneDBRow($sql, ['name' => $name]);
        return isset($row['value']) ? (int)$row['value'] : 0;
    }

    public function getSentenceCount() {
        return $this->getTableRowCount('sentences');
    }

    public function getSourceCount() {
        return $this->getTableRowCount('sources');
    }

    public function getNonEmptySourceCount() {
        return $this->getTableRowCount('sources');
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
