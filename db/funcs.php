<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../env-loader.php';

$home ="/" . basename(__DIR__);

// Configure error_log to write to file in current directory, not stderr
// This prevents debug logs from appearing in console output
ini_set('error_log', __DIR__ . '/../scripts/php_errorlog');

function formatLogMessage( $msg, $intro = "" ) {
    if( is_object( $msg ) || is_array( $msg ) ) {
        $msg = var_export( $msg, true );
    }
    $defaultTimezone = 'Pacific/Honolulu';
    $now = new DateTimeImmutable( "now", new DateTimeZone( $defaultTimezone ) );
    $now = $now->format( 'Y-m-d H:i:s' );
    $out = "$now " . $_SERVER['SCRIPT_NAME'];
    if( $intro ) {
        $out .= " $intro:";
    }
    return "$out $msg";
}

function printObject( $obj, $intro = '' ) {
    $msg = formatLogMessage( $obj, $intro );
    echo "$msg \n";
}

function debuglog( $msg, $intro = "" ) {
    if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
        $msg = formatLogMessage( $msg, $intro );
        //error_log( "$msg \n", 3, "/tmp/php_errorlog" );
        error_log( $msg );
    }
    return;
}

function logRequest() {
    debuglog(  $_REQUEST, "REQUEST" );
    debuglog(  $_SESSION, "SESSION" );
    $remote = $_SERVER['REMOTE_ADDR'] . ' - ' . $_SERVER['HTTP_USER_AGENT'];
    debuglog( $remote, "remote" );
    //debuglog( "SERVER: " . var_export($_SERVER, true) );
    debuglog(  $_COOKIE, "COOKIE" );
}

class DB extends Common\DB\DBBase {
    protected $logName;
    public $logLevel = 1;
    protected $noisyLogLevel = 3;
    public $conn;

    public function __construct($pdo = null, $logger = null) {
        parent::__construct($pdo, $logger);
        $this->logName = (new \ReflectionClass($this))->getShortName();

        // Allow overriding noisy log level via environment for test debugging
        $envLevel = getenv('DB_LOG_LEVEL');
        if ($envLevel !== false && is_numeric($envLevel)) {
            $this->noisyLogLevel = (int)$envLevel;
        }
        $this->conn = $this->connect();
    }

    public function setDebugLogLevel( $level ) {
        $this->noisyLogLevel = $level;
    }

    public function debuglog( $msg, $prefix="", $logLevel = 5 ) {
        if( $logLevel >= $this->noisyLogLevel ||
            $this->logLevel >= $this->noisyLogLevel) {
            parent::debuglog( $msg, $prefix );
        }
    }

    public function connect() {
        $env = loadEnv(__DIR__ . '/../.env');
        $env['port'] = $env['port'] ?? $env['DB_PORT'] ?? '3306';
        $env['username'] = $env['username'] ?? $env['DB_USER'] ?? '';
        $env['password'] = $env['password'] ?? $env['DB_PASSWORD'] ?? '';
        $env['dbname'] = $env['dbname'] ?? $env['DB_DATABASE'] ?? '';
        $env['host'] = $env['host'] ?? $env['DB_HOST'] ?? 'localhost';
        unset( $env['socket'] ); // Not used in this implementation
        try {
            return $this->createConnection( $env );
        } catch(PDOException $e) {
            echo "Connection failed: " . $e->getMessage();
            $this->debuglog(  $e->getMessage(), "Connection failed: " );
            return null;
        }
    }

    private function abbreviatedValues( $values ) {
        $v = [];
        if( $values && sizeof($values) > 0 ) {
            foreach( $values as $key => $value ) {
                $v[$key] = substr( $value, 0, 150 );
            }
        }
        return $v;
    }

    public function executePrepared( $sql, $values = [] ) {
        $result = $this->executeStatement( $sql, $values );
        //$this->debuglog( $result, "Result of executeStatement");
        return $result ? true : 0;
    }

    public function getOneDBRow( $sql, $values = [] ) {
        return $this->getOne( $sql, $values );
    }

    public function getDBRows( $sql, $values = [] ) {
        return $this->query( $sql, $values );
    }
}

$a = array( 'ō', 'ī', 'ē', 'ū', 'ā', 'Ō', 'Ī', 'Ē', 'Ū', 'Ā', '‘', 'ʻ' );
$b = array('o', 'i', 'e', 'u', 'a', 'O', 'I', 'E', 'U', 'A', '', '' );
$a = array( 'ō', 'ī', 'ē', 'ū', 'ā', 'Ō', 'Ī', 'Ē', 'Ū', 'Ā', '‘', 'ʻ' );
//$b = array('o', 'i', 'e', 'u', 'a', 'O', 'I', 'E', 'U', 'A', 'ʻ', 'ʻ' );

function sqlNormalize() {
    global $a, $b;
    $search = "replace(hawaiianText,'ō','o')";
    for( $i = 1; $i < sizeof( $a ); $i++ ) {
        $search = "replace($search,'" . $a[$i] . "','" . $b[$i] . "')";
    }
    return $search;
}

function sqlNormalizeOkina() {
    $search = "preg_replace(hawaiianText, '/\‘|\ʻ/', '')";
    $search = "preg_replace(hawaiianText, '‘', '')";
    $search = "replace(hawaiianText,'ʻ','')";
    $search = "hawaiianText";
    return $search;
}

function normalizeString( $term ) {
    global $a, $b;
    return str_replace($a, $b, $term);
}

require_once __DIR__ . '/../lib/ProcessingLogger.php';

class Laana extends DB {
    use \Noiiolelo\ProcessingLogger;
    
    private $pageNumber = 0;
    public $pageSize = 1000;
    
    public function getRandomWord( $minlength = 5 ) {
        $count = $this->getSentenceCount();
        $len = 0;
        $times = 10;
        while( ($len < $minlength) && ($times > 0) ) {
            $start = random_int( 0, $count - 1 );
            $sql = "select hawaiianText from sentences limit $start, 1";
            $rows = $this->getDBRows( $sql );
            $sentence = $rows[0]['hawaiiantext'];
            $words = preg_split( "/[\s,\?!\.\;\:\(\)]+/",  $sentence );
            $start = random_int( 0, sizeof($words) - 1 );
            $word = $words[$start];
            $len = strlen( $word );
            $times--;
        }
        return $word;
    }
    
    public function getsentences( $term, $pattern, $pageNumber = -1, $options = [] ) {
        $funcName = "Laana::getsentences";
        debuglog( $options, "$funcName($term,$pattern,$pageNumber");
        $countOnly = isset($options['count']) ? $options['count'] : false;
        $nodiacriticals = isset($options['nodiacriticals']) ? $options['nodiacriticals'] : false;
        $orderBy = (isset($options['orderby']) && $options['orderby']) ? "order by " . $options['orderby'] : '';
        $pageSize = $options['limit'] ?? $this->pageSize;
        $normalizedTerm = normalizeString( $term );
        $search = "hawaiianText";
        $values = [];

        if( $nodiacriticals ) {
            //if( $pattern == 'regex' || $pattern == 'exact' ) {
                $search = "simplified";
                $term = $normalizedTerm;
            //}
        }
        if( $countOnly ) {
            $targets = "count(*) count";
        }
        // Strip quotes if present - user wants exact match either way
        $term = trim($term, '"');
        $booleanMode = "";
        if( $pattern == 'regex' ) {
            if( $countOnly ) {
                $sql = "select $targets from sentences s where $search REGEXP :term";
            } else {
                $targets = "o.authors,o.date,o.sourceName,o.sourceID,o.link,s.hawaiianText,s.sentenceid";
                $sql = "select $targets from sentences s, sources o where $search REGEXP :term and s.sourceID = o.sourceID";
            }
            $term = preg_replace( '/\\\/', '\\\\', $term );
            $values = [
                'term' => $term,
            ];
        } else {
            // Use REGEXP for exact pattern instead of fulltext for better performance
            if( $pattern == 'exact' ) {
                // Use simple regex with escaped spaces - faster than word boundaries
                $regexTerm = preg_quote($term, '/');
                $values = [
                    'term' => $regexTerm,
                ];
                if( $countOnly ) {
                    $sql = "select $targets from sentences o where o.$search REGEXP :term";
                } else {
                    $targets = "s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid";
                    $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where o.$search REGEXP :term";
                }
            } else if( $pattern == 'all' ) {
                // Boolean mode: all words required
                $words = preg_split( "/[\s,\?!\.\;\:\(\)]+/",  $term );
                $term = "";
                foreach( $words as $word ) {
                    $term .= "+$word ";
                }
                $term = trim( $term );
                $booleanMode = "IN BOOLEAN MODE";
                $values = [
                    'term' => $term,
                ];
                if( $countOnly ) {
                    $sql = "select $targets from sentences o where match(o.$search) against (:term $booleanMode)";
                } else {
                    $targets = "s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid";
                    $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where match(o.$search) against (:term $booleanMode)";
                }
            } else if( $pattern == 'clause' ) {
                // Direct SQL clause
                $targets = "s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid";
                $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where $term";
                $values = [
                ];
            } else {
                // Default: natural language fulltext search (any words)
                $values = [
                    'term' => $term,
                ];
                if( $countOnly ) {
                    $sql = "select $targets from sentences o where match(o.$search) against (:term)";
                } else {
                    $targets = "s.authors,s.date,s.sourceName,s.sourceID,s.link,o.hawaiianText,o.sentenceid";
                    $sql = "select $targets from sentences o inner join sources s on s.sourceID = o.sourceID where match(o.$search) against (:term)";
                }
            }
        }
        // NUll dates show up as first
        if( isset( $options['orderby'] ) && preg_match( '/^date/', $options['orderby'] ) ) {
            $sql .= ' and not isnull(date)';
        }
        if( isset( $options['from'] ) && $options['from'] ) {
            $sql .= " and date >= '" . $options['from'] . "-01-01'";
        }
        if( isset( $options['to'] ) && $options['to'] ) {
            $sql .= " and date <= '" . $options['to'] . "-12-31'";
        }
        if( isset( $options['authors'] ) && $options['authors'] ) {
            $sql .= " and authors = '" . $options['authors'] . "'";
        }
        $orderBy = "";
        if( !$countOnly && isset( $options['orderby'] ) ) {
            $orderBy = ' order by ' . $options['orderby'];
        }
        $sql .= $orderBy;
        if( $pageNumber >= 0 && !$countOnly ) {
            $sql .= " limit " . $pageNumber * $pageSize . "," . $pageSize;
        }
        //echo "sql: $sql, values: " . var_export( $values, true ) . "\n";
        $rows = $this->getDBRows( $sql, $values );
        debuglog( $rows, "$funcName getsentences result" );
        return $rows;
    }
    
    public function getMatchingSentenceCount( $term, $pattern, $pageNumber = -1, $options = [] ) {
        $funcName = "Laana::getMatchingSentenceCount";
        debuglog( $options, "$funcName($term,$pattern,$pageNumber)" );
        $options['count'] = true;
        $rows = $this->getsentences( $term, $pattern, $pageNumber, $options );
        if( sizeof( $rows ) > 0 ) {
            return $rows[0]['count'];
        }
        return 0;
    }
    
    public function getSentenceCountBySourceID( $sourceid ) {
        $sql = "select count(hawaiianText) cnt from sentences where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        $this->debuglog( $row['cnt'], "Sentence count");
        return $row['cnt'];
    }
    
    public function getsentencesBySourceID( $sourceid ) {
        $sql = "select sentenceID, hawaiianText text from sentences where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $results = $this->getSource( $sourceid );
        $results['sentences'] = $this->getDBRows( $sql, $values );
        //echo "getSource: " . var_export( $results, true ) . "\n";
        return $results;
    }
    
    public function getSentence( $sentenceid ) {
        $sql = "select * from sentences where sentenceid = :id";
        $values = [
            'id' => $sentenceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row;
    }
    
    public function getsources( $groupname = '', $properties = [] ) {
        $criteria = "";
        $criteria = "having sentencecount > 0";
        if( sizeof($properties) < 1 ) {
            $properties = ["*"];
        }
        if( !$groupname ) {
            for( $i = 0; $i < sizeof( $properties ); $i++ ) {
                $properties[$i] = "o." . $properties[$i];
            }
        }
        //var_export( $properties );
        $selection = implode( ",", $properties );
        if( !$groupname ) {
            //$sql = "select $selection,count(sentenceID) sentencecount from sources o,sentences s where o.sourceID = s.sourceID group by o.sourceID order by sourceName";
            $sql = "select $selection,count(sentenceID) sentencecount from sources o left join sentences s on o.sourceID = s.sourceID group by o.sourceID $criteria order by sourceName";
            //echo $sql;
            $rows = $this->getDBRows( $sql );
        } else {
            $sql = "select $selection from (select o.*,count(sentenceID) sentencecount from sources o  left join sentences s on o.sourceID = s.sourceID  group by o.sourceID $criteria order by date,sourceName) h where groupname = :groupname";
            $values = [
                'groupname' => $groupname,
            ];
            //echo $sql;
            $rows = $this->getDBRows( $sql, $values );
        }
        return $rows;
    }
    
    public function getSourceCount() {
        $sql = "select count(distinct sourceid) c from sentences";
        $row = $this->getOneDBRow( $sql );
        return $row['c'];
    }
    
    public function getSourceGroupCounts() {
        $sql = "SELECT g.groupname, COUNT(DISTINCT s.sourceid) AS c FROM sources g LEFT JOIN sentences s ON g.sourceid = s.sourceid GROUP BY g.groupname";
        $rows = $this->getDBRows( $sql );
        $results = [];
        foreach( $rows as $row ) {
            $results[$row['groupname']] = $row['c'];
        }
        return $results;
    }
    
    public function getTotalSourceGroupCounts() {
        $sql = "SELECT groupname, COUNT(DISTINCT sourceid) AS c FROM sources GROUP BY groupname";
        $rows = $this->getDBRows( $sql );
        $results = [];
        foreach( $rows as $row ) {
            $results[$row['groupname']] = $row['c'];
        }
        return $results;
    }
    
    public function getSource( $sourceid ) {
        //$sql = "select * from sources o where sourceid = :sourceid";
        $sql = "select sources.*,count(*) sentencecount from sources left join sentences on sources.sourceid = sentences.sourceid where sources.sourceid = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        $this->debuglog( $row, 'returning source');
        return $row;
    }
    
    public function getSourceByName( $name ) {
        $sql = "select * from sources o where sourceName = :name group by o.sourceID";
        $values = [
            'name' => $name,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row;
    }
    
    public function getSourceByLink( $link ) {
        $sql = "select * from sources o where link = :link";
        $values = [
            'link' => $link,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row;
    }
    
    public function getLatestSourceDates() {
        $sql = "select groupname,max(date) date from sources o group by groupname";
        $rows = $this->getDBRows( $sql );
        return $rows;
    }

    function hasSomething( $sourceid, $what ) {
        $sql = "select count(*) cnt from sources s, contents c where s.sourceid=c.sourceid and s.sourceid = :sourceid and not (isnull($what) or length($what) < 1)";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return ($row['cnt'] > 0);
    }
    
    function hasRaw( $sourceid ) {
        return $this->hasSomething( $sourceid, "html" );
    }
    
    function hasText( $sourceid ) {
        return $this->hasSomething( $sourceid, "text" );
    }
    
    public function getSentenceCount() {
        $funcName = "getSentenceCount";
        $sql = "select value count from stats where name = 'sentences'";
        $row = $this->getOneDBRow( $sql );
        debuglog( $row, $funcName );
        return $row['count'];
    }

    public function updateCounts() {
        $sql = "update stats set value=(select count(*) from sentences where name = 'sentences'";
        $status = $this->executeSQL( $sql );
        if( $status == 1 ) {
            $sql = "update stats set value=(select count(*) from sources) where name = 'sources'";
            $status = $this->executeSQL( $sql );
        }
        return $status;
    }

    public function getSourceIDs( $groupname = '' ) {
        if( !$groupname ) {
            $sql = "select sourceID from sources order by sourceID";
            $rows = $this->getDBRows( $sql );
        } else {
            $sql = "select sourceID from sources where groupname = :groupname order by sourceID";
            $values = [
                'groupname' => $groupname,
            ];
            $rows = $this->getDBRows( $sql, $values );
        }
        $sourceIDs = [];
        foreach( $rows as $row ) {
            $sourceIDs[] = $row['sourceid'];
        }
        return $sourceIDs;
    }

    protected function patchSource( $sql, $params ) {
        $link = $params['link'] ?: '';
        $groupname = $params['groupname'] ?: '';
        $date = $params['date'] ?: '';
        $title = $params['title'] ?: '';
        $authors = $params['authors'] ?: '';
        $name = $params['sourcename'] ?: '';
        $values = [
            'name' => $name,
            'link' => $link,
            'authors' => $authors,
            'groupname' => $groupname,
            'title' => substr($title, 0, 140),
        ];
        if( $date ) {
            $values['date'] = $date;
        }
        if( $this->executePrepared( $sql, $values ) ) {
            $row = $this->getSourceByLink( $link );
            return $row;
        } else {
            return null;
        }
    }

    public function updateSourceByID( $params ) {
        $values = $params;
        $attrs = [
            "link", "groupname", "sourcename", "author", "title", "date", "sourceid"
        ];
        debuglog( $values, "Laana::updateSourceByID params before cleaning" );
        foreach( $values as $key => $value ) {
            if( !in_array( $key, $attrs ) ) {
                unset( $values[$key] );
            }
        }
        foreach( $attrs as $key ) {
            if( !isset( $values[$key] ) ) {
                $values[$key] = '';
            }
        }
        if( !$values['date'] ) {
            $values['date'] = '1970';
        }
        if( strlen( $values['date'] ) == 4 ) {
            $values['date'] .= '-01-01';
        }
        debuglog( $values, "Laana::updateSourceByID params after cleaning" );
        $sql = "update sources set link = :link, " .
               "groupname = :groupname, " .
               "sourcename = :sourcename, " .
               "authors = :author, " .
               "title = :title, " .
               "date = :date " .
               "where sourceID = :sourceid";
        if( $this->executePrepared( $sql, $values ) ) {
            $row = $this->getSourceByName( $values['sourcename'] );
            return $row;
        } else {
            return null;
        }
    }
    
    public function addSource( $name, $params = [] ) {
        if( !$name && isset( $params['sourcename'] ) ) {
            $name = $params['sourcename'];
        }
        if( !$name ) {
            return null;
        }
        if( !$params['date'] ) {
            $params['date'] = '1970-01-01';
        }
        if( !isset( $params['sourceid'] ) ) {
            $row = $this->getSourceByName( $name );
            if( isset( $row['sourceid'] ) ) {
                $params['sourceid'] = $row['sourceid'];
            }
        }
        if( isset( $params['sourceid'] ) && isset( $params['link'] ) ) {
            return $this->updateSourceByID( $params );
        } else if( !isset( $params['link'] ) || !isset( $params['groupname'] ) || !isset( $params['title'] ) ) {
            return null;
        } else {
            // Create a new entry
            // Provide empty values for non-required attributes
            foreach( ['authors',] as $key ) {
                if( !isset( $params[$key] ) ) {
                    $params[$key] = '';
                }
            }
            $sql = "replace into sources(sourceName, link, authors, groupname, title, date) " .
                   "values(:name, :link, :authors, :groupname, :title, :date)";
            return $this->patchSource( $sql, $params );
        }
    }
    
    public function addSomesentences( $sourceID, $sentences ) {
        $sql = "insert ignore into sentences (sourceID, hawaiianText) values ";
        $values = [
            //'sourceID' => $sourceID,
        ];
        $i = 0;
        for( $i = 0; $i < sizeof($sentences); $i++ ) {
            $sentence = trim($sentences[$i]);
            if( strlen($sentence) < 3 ) {
                continue;
            }
            $sql .= "(:sourceID_$i, :text_$i),";
            $values["text_$i"] = $sentence;
            $values["sourceID_$i"] = $sourceID;
        }
        $sql = trim( $sql, "," );

        if( $this->executePrepared( $sql, $values ) ) {
            $count = sizeof( $sentences );
            return $count;
        } else {
            return null;
        }
    }
    public function updateSimplified( $sourceID ) {
        // Maintain the simplified column without diacriticals
        $sql = "update sentences set simplified = replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(hawaiianText,'ō','o'),'ī','i'),'ē','e'),'ū','u'),'ā','a'),'Ō','O'),'Ī','I'),'Ē','E'),'Ū','U'),'Ā','A'),'‘',''),'ʻ','') where sourceID = :sourceID";
        $values = [
            'sourceID' => $sourceID,
        ];
        return $this->executePrepared( $sql, $values );
    }
    public function updateSentenceCount( $sourceID = null ) {
        $sql = "UPDATE sources s INNER JOIN ( SELECT sourceid, COUNT(*) AS sentence_count FROM sentences GROUP BY sourceid ) sc ON s.sourceid = sc.sourceid SET s.sentencecount = sc.sentence_count";
        if( $sourceID ) {
            $sql .= " where s.sourceid = :sourceID";
            $values = [
                'sourceID' => $sourceID,
            ];
            return $this->executePrepared( $sql, $values );
        } else {
            return $this->executeSQL( $sql );
        }
    }
    public function addsentences( $sourceID, $sentences ) {
        $maxValues = 30;
        $count = 0;
        for( $i = 0; $i < sizeof( $sentences ); $i += $maxValues ) {
            $len = min( $maxValues, sizeof($sentences) - $i );
            $subset = array_slice( $sentences, $i, $len );
            $status = $this->addSomesentences( $sourceID, $subset );
            if( !$status ) {
                break;
            }
            $count += $status;
        }
        $this->updateSimplified( $sourceID );
        //$this->updateSentenceCount( $sourceID );
        return $count;
    }
    public function addContentRow( $sourceID ) {
        $sql = "insert ignore into contents(sourceID) values(:sourceID)";
        $values = [
            'sourceID' => $sourceID,
        ];
        $result = $this->executePrepared( $sql, $values );
        debuglog( "Laana::addContentRow($sourceID) returned $result" );
        return $result;
    }
    public function addFullText( $sourceID, $sentences ) {
        $this->addContentRow( $sourceID );
        $sql = "update contents set text=:text where sourceid=:sourceID";
        // Have to do the line by line mapping to avoid character set issues
        $text = implode("\n", array_map(
            fn($s) => preg_replace('/[\x00-\x1F\x7F]/u', '', mb_convert_encoding(trim($s), 'UTF-8', 'auto')),
            $sentences
        ));

        file_put_contents("/tmp/debug_output-$sourceID.txt", $text);
        $values = [
            'sourceID' => $sourceID,
            'text' => $text,
        ];
        
        //$stmt = $this->conn->query("SHOW VARIABLES LIKE 'character_set_client'");
        //debuglog("SHOW VARIABLES: " . $stmt->fetchColumn(1) );
        
        $result = ($this->executePrepared( $sql, $values )) ? sizeof( $sentences ) : 0;
        debuglog( "Laana::addFullText($sourceID) $sql returned $result" );
        return $result;
    }
    public function addRawText( $sourceID, $rawtext ) {
        $this->addContentRow( $sourceID );
        $sql = "update contents set html=:text where sourceid=:sourceID";
        $safe = $rawtext;
        //$safe = mb_convert_encoding(html_entity_decode($rawtext), "UTF-8");
        //echo "addRawText: $safe\n";
        $values = [
            'sourceID' => $sourceID,
            'text' => $safe,
        ];
        $result = ($this->executePrepared( $sql, $values )) ? strlen( $rawtext ) : 0;
        debuglog( "Laana::addRawText($sourceID) $sql returned $result" );
        //echo( "Laana::addRawText($sourceID) $sql returned $result\n" );
        return $result;
    }
    public function addText( $sourceID, $text ) {
        $sql = "replace into contents(sourceID, text) values(:sourceID,:text)";
        $values = [
            'sourceID' => $sourceID,
            'text' => $text,
        ];
        $result = ($this->executePrepared( $sql, $values )) ? strlen( $text ) : 0;
        debuglog( "Laana::addText($sourceID) $sql returned $result" );
        return $result;
    }
    public function getRawText( $sourceid ) {
        $sql = "select html from contents where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row['html'] ?? '';
    }
    public function getText( $sourceid ) {
        $sql = "select text from contents where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row['text'] ?? '';
    }
    public function removecontents( $sourceid ) {
        $sql = "delete from contents where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $result = $this->executePrepared( $sql, $values );
        return $result;
    }
    public function removesentences( $sourceid ) {
        $sql = "delete from sentences where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $result = $this->executePrepared( $sql, $values );
        return $result;
    }
    
    /**
     * Remove all records for a given groupname
     * Deletes from sentences, contents, and sources tables
     * Uses efficient SQL subqueries to avoid PHP loops and ensure atomicity
     * 
     * @param string $groupname The groupname to delete
     * @return array Statistics about what was deleted
     */
    public function removeByGroupname($groupname) {
        $values = ['groupname' => $groupname];
        $stats = [
            'sentences' => 0,
            'contents' => 0,
            'sources' => 0
        ];
        
        // Count how many records will be affected (for statistics)
        $sql = "SELECT 
                    (SELECT COUNT(*) FROM sentences WHERE sourceid IN (SELECT sourceid FROM sources WHERE groupname = :groupname)) as sentence_count,
                    (SELECT COUNT(*) FROM contents WHERE sourceid IN (SELECT sourceid FROM sources WHERE groupname = :groupname)) as content_count,
                    (SELECT COUNT(*) FROM sources WHERE groupname = :groupname) as source_count";
        $row = $this->getOneDBRow($sql, $values);
        
        if ($row['source_count'] == 0) {
            return $stats;
        }
        
        // Delete sentences using subquery
        $sql = "DELETE FROM sentences WHERE sourceid IN (SELECT sourceid FROM sources . WHERE groupname = :groupname)";
        $this->executePrepared($sql, $values);
        $stats['sentences'] = $row['sentence_count'];
        
        // Delete contents using subquery
        $sql = "DELETE FROM contents WHERE sourceid IN (SELECT sourceid FROM  sources WHERE groupname = :groupname)";
        $this->executePrepared($sql, $values);
        $stats['contents'] = $row['content_count'];
        
        // Delete sources
        $sql = "DELETE FROM sources WHERE groupname = :groupname";
        $this->executePrepared($sql, $values);
        $stats['sources'] = $row['source_count'];
        
        return $stats;
    }
    
    public function addSearchStat( $searchterm, $pattern, $results, $order,$elapsed ) {
        $sql = "insert into searchstats(searchterm,pattern,results,sort,elapsed) values(:searchterm,:pattern,:results,:sort,:elapsed)";
        $values = [
            'searchterm' => $searchterm,
            'pattern' => $pattern,
            'results' => $results,
            'sort' => $order,
            'elapsed' => $elapsed,
        ];
        $result = $this->executePrepared( $sql, $values );
        return $result;
    }
    public function getsearchstats() {
        $sql = "select * from searchstats order by created";
        $rows = $this->getDBRows( $sql );
        return $rows;
    }
    public function getSummarysearchstats() {
        $sql = "select pattern,count(*) count from searchstats group by pattern order by pattern";
        $rows = $this->getDBRows( $sql );
        return $rows;
    }
    public function getFirstSearchTime() {
        $sql = "select min(created) first from searchstats";
        $row = $this->getOneDBRow( $sql );
        return ($row['first']) ? $row['first'] : '';
    }
    
    // Processing Log Methods - Implementation for ProcessingLogger trait
    protected function startProcessingLogImpl($operationType, $sourceID = null, $groupname = null, $parserKey = null, $metadata = null) {
        $sql = "INSERT INTO processing_log (operation_type, source_id, groupname, parser_key, status, metadata) 
                VALUES (:operation_type, :source_id, :groupname, :parser_key, 'started', :metadata)";
        $values = [
            'operation_type' => $operationType,
            'source_id' => $sourceID,
            'groupname' => $groupname,
            'parser_key' => $parserKey,
            'metadata' => $metadata ? json_encode($metadata) : null,
        ];
        // Temporarily disable output during processing log
        ob_start();
        $result = $this->executePrepared($sql, $values);
        ob_end_clean();
        if ($result) {
            return $this->conn->lastInsertId();
        }
        return null;
    }
    
    protected function completeProcessingLogImpl($logID, $status = 'completed', $sentencesCount = 0, $errorMessage = null) {
        $sql = "UPDATE processing_log 
                SET status = :status, 
                    sentences_count = :sentences_count, 
                    completed_at = NOW(), 
                    error_message = :error_message 
                WHERE log_id = :log_id";
        $values = [
            'log_id' => $logID,
            'status' => $status,
            'sentences_count' => $sentencesCount,
            'error_message' => $errorMessage,
        ];
        // Suppress debug output during processing log
        ob_start();
        $result = $this->executePrepared($sql, $values);
        ob_end_clean();
        return $result;
    }
    
    protected function getProcessingLogsImpl($options = []) {
        $where = [];
        $values = [];
        
        if (isset($options['operation_type'])) {
            $where[] = "operation_type = :operation_type";
            $values['operation_type'] = $options['operation_type'];
        }
        if (isset($options['groupname'])) {
            $where[] = "groupname = :groupname";
            $values['groupname'] = $options['groupname'];
        }
        if (isset($options['status'])) {
            $where[] = "status = :status";
            $values['status'] = $options['status'];
        }
        
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        $limit = isset($options['limit']) ? " LIMIT " . intval($options['limit']) : "";
        
        $sql = "SELECT * FROM processing_log $whereClause ORDER BY started_at DESC $limit";
        return $this->getDBRows($sql, $values);
    }
}
?>
