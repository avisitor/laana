<?php
require_once __DIR__ . '/funcs.php';
class PostgresLaana extends Laana {
    public function __construct() {
        parent::__construct();
    }

    public function connect($dsn = null, $options = false) {
        // Load env and read PG_* variables
        $env = \Avisitor\Env\Loader::load(__DIR__ . '/../.env');
        // Fail loudly when the Postgres connection config is missing instead of
        // silently connecting to localhost with empty credentials.
        $host = \Noiiolelo\EnvConfig::firstEnv('Postgres host', ['PG_HOST']);
        $port = \Noiiolelo\EnvConfig::firstEnv('Postgres port', ['PG_PORT']);
        $db   = \Noiiolelo\EnvConfig::firstEnv('Postgres database', ['PG_DATABASE', 'PG_DB']);
        $user = \Noiiolelo\EnvConfig::firstEnv('Postgres user', ['PG_USER']);
        $pass = \Noiiolelo\EnvConfig::firstEnv('Postgres password', ['PG_PASSWORD']);
        $dsnOverride = $env['PG_DSN'] ?? getenv('PG_DSN') ?? null;

        $config = [
            'driver'   => 'pgsql',
            'host'     => $host,
            'port'     => $port,
            'dbname'   => $db,
            'username' => $user,
            'password' => $pass,
        ];
        if ($dsnOverride) {
            $config['dsn'] = $dsnOverride;
        }
        try {
            $conn = $this->createConnection( $config );
            // Ensure UTF-8
            $conn->exec("SET client_encoding TO 'UTF8'");
            // Set search path
            $conn->exec("SET search_path TO laana, public");
            debuglog([ 'dsn' => $dsnOverride ?: "pgsql:host=$host;port=$port;dbname=$db", 'user' => $user, 'db' => $db, 'host' => $host, 'port' => $port ], 'PostgresLaana::connect');
            return $conn;
        } catch (\Throwable $e) {
            debuglog("Postgres connection failed: " . $e->getMessage());
            return null;
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
            // Use the non-blocked-source index path so the count stays fast
            // even when a common term matches a large share of the corpus.
            $sql = "SELECT count(*) as count FROM sentences s WHERE $where";
            $sql = $this->appendNonBlockedGroupWhereWithSourceAlias($sql, $values, 's');
        } else {
            // Fast data retrieval with LIMIT inside the subquery. The blocked
            // source filter must be applied INSIDE the subquery (before the
            // ORDER BY / LIMIT) so a page of results is never emptied out by
            // the filter being appended after the ORDER BY clause.
            $innerSql = "SELECT sentenceid, sourceid, hawaiiantext FROM sentences WHERE $where";
            $innerSql = $this->appendNonBlockedGroupWhereWithSourceAlias($innerSql, $values, 'sentences');
            $innerSql .= " ORDER BY sentenceid DESC LIMIT $pageSize OFFSET $offset";
            $sql = "SELECT s.authors, s.date, s.sourcename, s.sourceid, s.link, 
                        matched.hawaiiantext as hawaiianText, matched.sentenceid, 
                        m.hawaiian_word_ratio, m.word_count, m.length, m.entity_count, m.frequency
                    FROM ($innerSql) matched
                    INNER JOIN sources s ON s.sourceid = matched.sourceid
                    LEFT JOIN sentence_metrics m ON m.sentenceid = matched.sentenceid
                    ORDER BY matched.sentenceid DESC";
        }

        try {
            return $this->getDBRows($sql, $values);
        } catch (Exception $e) {
            \Avisitor\Monolog\Logger::logError("DB Error in $funcName: " . $e->getMessage());
            return [];
        }
    }

    public function refreshGrammarPatternCounts() {
        $sql = "REFRESH MATERIALIZED VIEW CONCURRENTLY laana.grammar_pattern_counts";
        try {
            $this->conn->exec($sql);
            return true;
        } catch (Exception $e) {
            \Avisitor\Monolog\Logger::logError("DB Error in PostgresLaana::refreshGrammarPatternCounts: " . $e->getMessage());
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
