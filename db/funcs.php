<?php
require_once( __DIR__ . '/config.php' );

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
    $msg = formatLogMessage( $msg, $intro );
    error_log( "$msg \n", 3, "/tmp/php_errorlog" );
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

class DB {
    public $conn;
    public function __construct() {
        $this->connect();
        if( $this->conn == null ) {
            debuglog( "Unable to connect to DB" );
            exit;
        }
    }

    public function connect() {
        global $dbconfig;
	    //error_log( "connect: " . var_export( $dbconfig, true ) );
        $servername = $dbconfig["servername"];
        if( isset($_SERVER['HTTP_HOST']) && $servername == $_SERVER['HTTP_HOST'] ) {
            $servername = "localhost";
        }
        $socket = $dbconfig["socket"];
        $username = $dbconfig["username"];
        $password = $dbconfig["password"];
        $myDB = $dbconfig["db"];
        $this->conn = null;
        try {
            try {
                $this->conn =
                    new PDO("mysql:host=$servername;dbname=$myDB;charset=utf8mb4", $username, $password);
            } catch(PDOException $e) {
                $this->conn =
                    new PDO("mysql:unix_socket=$socket;dbname=$myDB;charset=utf8mb4", $username, $password);
            }
            $this->conn->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            // set the PDO error mode to exception
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            echo "Connection failed: " . $e->getMessage();
            error_log( "Connection failed: " . $e->getMessage() );
        }
    }

    public function getDBRows( $sql, $values = [] ) {
        $funcName = "getDBRows";
        debuglog( "$funcName sql: " . $sql );
        if( $this->conn == null ) {
            return [];
        }
        if( $values && sizeof( $values ) > 0 ) {
            debuglog( $values, $funcName );
        }
        try {
            $stmt = $this->conn->prepare( $sql );
            if( $values && sizeof($values) > 0 ) {
                $stmt->execute( $values );
            } else {
                $stmt->execute();
            }
            // set the resulting array to associative
            $result = $stmt->setFetchMode(PDO::FETCH_ASSOC);
            $rows = $stmt->fetchAll();
            // change keys to all lower case
            $results = [];
            foreach( $rows as $row ) {
                $newrow = [];
                foreach( $row as $key => $value ) {
                    $newrow[strtolower($key)] = $value;
                }
                array_push( $results, $newrow );
            }
            debuglog( "$funcName: " . sizeof( $results ) . " rows returned" );
            return $results;
        } catch(PDOException $e) {
            echo "$funcName: Execution failed: " . $e->getMessage();
            debuglog( "Execution failed for $sql: " . $e->getMessage(), $funcName );
            return [];
        }
    }

    public function getOneDBRow( $sql, $values = '' ) {
        $rows = $this->getDBRows( $sql, $values );
        if( $rows && sizeof($rows) > 0 ) {
            return $rows[0];
        } else {
            return [];
        }
    }

    public function executeSQL( $sql ) {
        if( $this->conn == null ) {
            return [];
        }
        try {
            $stmt = $this->conn->prepare( $sql );
            $stmt->execute();
            return 1;
        } catch(PDOException $e) {
            echo "Execution failed: " . $e->getMessage();
            debuglog( "Execution failed for $sql: " . $e->getMessage() );
            return [];
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
    
    public function executePrepared( $sql, $values ) {
        $funcName = "executePrepared";
        if( $this->conn == null ) {
            debuglog( "$funcName: null connection");
            return [];
        }
        //echo( $funcName: $sql . " - " . ($values) ? var_export( $values, true ) : '' . "\n" );
        debuglog( $sql, $funcName );
        if( $values && sizeof( $values ) > 0 ) {
            $v = $this->abbreviatedValues( $values );
            debuglog( $v, $funcName );
        }
        try {
            $stmt = $this->conn->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
            $stmt->execute( $values );
            //echo "sql execution succeeded: $sql\n";
            return 1;
        } catch(PDOException $e) {
            //echo "Execution failed: " . $e->getMessage();
            debuglog( "Execution failed for $sql with " . var_export( $values ) . ": " . $e->getMessage(), $funcName );
            return 0;
        }
    }

    function updateOneDBRecord( $potential, $params, $table ) {
        $funcName = "updateOneDBRecord";
        debuglog( $potential, "$funcName potential" );
        debuglog( $params, "$funcName params" );
        $answers = array();
        foreach( $potential as $key ) {
            if( isset($params[$key]) ) {
                $val = htmlspecialchars($params[$key]);
                $answers[$key] = $val;
            }
        }
        if( count( $answers ) < 1 ) {
            debuglog( "$funcName: No parameters to update" );
            return $answers;
        }

        $updates = array();
        $keys = "(";
        $values = "values (";
        foreach( $answers as $key=>$value ) {
            $keys .= $key . ",";
            $values .= ":" . $key . ",";
            array_push( $updates, $key . "=:" . $key );
        }
        $keys = trim( $keys, " ," ) . ")";
        $values = trim( $values, " ," ) . ")";
        $create = "insert into $table $keys $values";
        debuglog( $create, "$funcName sql" );
        debuglog( $answers, "$funcName values" );
        if( count( $updates ) > 0 ) {
            // Try inserting as a new record
            try {
                $stmt = $this->conn->prepare( $create );
                $stmt->execute( $answers );
            } catch(PDOException $e) {
                $code = $e->getCode();
                if( $code != 23000 ) {
                    debuglog( "$funcName: " . $e->getMessage() . ", code - " . $code );
                } else {
                    // duplicate entry exception
                    // Try updating an existing record
                    /*
                       $email = $params['email'];
                       $answers['email'] = $email;
                     */
                    $sql = "update $table set " . implode( ",", $updates ) . " where email=:email";
                    debuglog( "$funcName SQL: $sql\n" );
                    try {
                        $stmt = $this->conn->prepare( $sql );
                        $stmt->execute( $answers );
                    } catch(PDOException $e) {
                        echo "$funcName: Update failed: " . $e->getMessage() . "\n";
                        debuglog( $e->getMessage(), $funcName );
                    }
                }
            }
        } else {
            debuglog( "No parameters to update", $funcName );
            return $answers;
        }

        //$stmt->debugDumpParams();
        return $params;
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

class Laana extends DB {
    private $pageNumber = 0;
    public $pageSize = 1000;
    
    public function getRandomWord( $minlength = 5 ) {
        $count = $this->getSentenceCount();
        $len = 0;
        $times = 10;
        while( ($len < $minlength) && ($times > 0) ) {
            $start = random_int( 0, $count - 1 );
            $sql = "select hawaiianText from " . SENTENCES . " limit $start, 1";
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
    
    public function getSentences( $term, $pattern, $pageNumber = -1, $options = [] ) {
        $funcName = "Laana::getSentences";
        debuglog( $options, "$funcName($term,$pattern,$pageNumber");
        $countOnly = isset($options['count']) ? $options['count'] : false;
        $nodiacriticals = isset($options['nodiacriticals']) ? $options['nodiacriticals'] : false;
        $orderBy = isset($options['orderby']) ? "order by " . $options['orderby'] : '';
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
        } else {
            $targets = "authors,date,sourceName,s.sourceID,link,hawaiianText,sentenceid";
        }
        $quoted = preg_match( '/^".*"$/', $term );
        $booleanMode = "";
        if( $pattern == 'regex' ) {
            if( $countOnly ) {
                $sql = "select $targets from " . SENTENCES . " s where $search REGEXP :term";
            } else {
                $sql = "select $targets from " . SENTENCES . " s, " . SOURCES . " o where $search REGEXP :term and s.sourceID = o.sourceID";
            }
            $term = preg_replace( '/\\\/', '\\\\', $term );
            $values = [
                'term' => $term,
            ];
        } else {
            if( !$quoted ) {
                if( $pattern == 'exact' ) {
                    $term = '"' . $term . '"';
                } else if( $pattern == 'all' ) {
                    $words = preg_split( "/[\s,\?!\.\;\:\(\)]+/",  $term );
                    $term = "";
                    foreach( $words as $word ) {
                        $term .= "+$word ";
                    }
                    $term = trim( $term );
                    $booleanMode = "IN BOOLEAN MODE";
                }
            }
            $values = [
                'term' => $term,
            ];
            if( $countOnly ) {
                $sql = "select $targets from " . SENTENCES . " o where match(o.$search) against (:term $booleanMode)";
            } else if( $pattern == 'clause' ) {
                $sql = "select $targets from " . SENTENCES . " o inner join " . SOURCES . " s on s.sourceID = o.sourceID where $term";
            $values = [
            ];
            } else {
                $sql = "select $targets from " . SENTENCES . " o inner join " . SOURCES . " s on s.sourceID = o.sourceID where match(o.$search) against (:term $booleanMode)";
            }
        }
        // NUll dates show up as first
        if( isset( $options['orderby'] ) && preg_match( '/^date/', $options['orderby'] ) ) {
            $sql .= ' and not isnull(date)';
        }
        if( isset( $options['from'] ) ) {
            $sql .= " and date >= '" . $options['from'] . "-01-01'";
        }
        if( isset( $options['to'] ) ) {
            $sql .= " and date <= '" . $options['to'] . "-12-31'";
        }
        $sql .= " $orderBy";
        if( $pageNumber >= 0 && !$countOnly ) {
            $sql .= " limit " . $pageNumber * $this->pageSize . "," . $this->pageSize;
        }
        //echo "sql: $sql, values: " . var_export( $values, true ) . "\n";
        $rows = $this->getDBRows( $sql, $values );
        debuglog( $rows, "$funcName getSentences result" );
        return $rows;
    }
    
    public function getMatchingSentenceCount( $term, $pattern, $pageNumber = -1, $options = [] ) {
        $funcName = "Laana::getMatchingSentenceCount";
        debuglog( $options, "$funcName($term,$pattern,$pageNumber)" );
        $options['count'] = true;
        $rows = $this->getSentences( $term, $pattern, $pageNumber, $options );
        if( sizeof( $rows ) > 0 ) {
            return $rows[0]['count'];
        }
        return 0;
    }
    
    public function getSentenceCountBySourceID( $sourceid ) {
        $sql = "select count(hawaiianText) cnt from " . SENTENCES . " where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row['cnt'];
    }
    
    public function getSentencesBySourceID( $sourceid ) {
        $sql = "select sentenceID, hawaiianText from " . SENTENCES . " where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $rows = $this->getDBRows( $sql, $values );
        return $rows;
    }
    
    public function getSentence( $sentenceid ) {
        $sql = "select * from " . SENTENCES . " where sentenceid = :id";
        $values = [
            'id' => $sentenceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row;
    }
    
    public function getSources( $groupname = '', $properties = [] ) {
        $criteria = "having count > 0 ";
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
            //$sql = "select $selection,count(sentenceID) sentencecount from " . SOURCES . " o," . SENTENCES . " s where o.sourceID = s.sourceID group by o.sourceID order by sourceName";
            $sql = "select $selection,count(sentenceID) sentencecount from " . SOURCES . " o left join " . SENTENCES . " s on o.sourceID = s.sourceID group by o.sourceID order by sourceName";
            //echo $sql;
            $rows = $this->getDBRows( $sql );
        } else {
            $sql = "select $selection from (select o.*,count(sentenceID) sentencecount from " . SOURCES . " o  left join " . SENTENCES . " s on o.sourceID = s.sourceID  group by o.sourceID order by date,sourceName) h where groupname = :groupname";
            $values = [
                'groupname' => $groupname,
            ];
            //echo $sql;
            $rows = $this->getDBRows( $sql, $values );
        }
        return $rows;
    }
    
    public function getSourceCount() {
        $sql = "select count(distinct sourceid) c from " . SENTENCES;
        $row = $this->getOneDBRow( $sql );
        return $row['c'];
    }
    
    public function getSourceGroupCounts() {
        $sql = "SELECT g.groupname, COUNT(DISTINCT s.sourceid) AS c FROM " . SOURCES . " g LEFT JOIN sentences s ON g.sourceid = s.sourceid GROUP BY g.groupname";
        $rows = $this->getDBRows( $sql );
        $results = [];
        foreach( $rows as $row ) {
            $results[$row['groupname']] = $row['c'];
        }
        return $results;
    }
    
    public function getTotalSourceGroupCounts() {
        $sql = "SELECT groupname, COUNT(DISTINCT sourceid) AS c FROM " . SOURCES . " GROUP BY groupname";
        $rows = $this->getDBRows( $sql );
        $results = [];
        foreach( $rows as $row ) {
            $results[$row['groupname']] = $row['c'];
        }
        return $results;
    }
    
    public function getSource( $sourceid ) {
        //$sql = "select * from " . SOURCES . " o where sourceid = :sourceid";
        $sql = "select sources.*,count(*) sentencecount from " . SOURCES . " left join " . SENTENCES . " on " . SOURCES . ".sourceid = " . SENTENCES . ".sourceid where " . SOURCES . ".sourceid = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row;
    }
    
    public function getSourceByName( $name ) {
        $sql = "select * from " . SOURCES . " o where sourceName = :name group by o.sourceID";
        $values = [
            'name' => $name,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row;
    }
    
    public function getSourceByLink( $link ) {
        $sql = "select * from " . SOURCES . " o where link = :link";
        $values = [
            'link' => $link,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row;
    }
    
    public function getLatestSourceDates() {
        $sql = "select groupname,max(date) date from " . SOURCES . " o group by groupname";
        $rows = $this->getDBRows( $sql );
        return $rows;
    }

    function hasSomething( $sourceid, $what ) {
        $sql = "select count(*) cnt from " . SOURCES . " s," . CONTENTS . " c where s.sourceid=c.sourceid and s.sourceid = :sourceid and not (isnull($what) or length($what) < 1)";
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
        $sql = "select value count from " . STATS . " where name = 'sentences'";
        $row = $this->getOneDBRow( $sql );
        debuglog( $row, $funcName );
        return $row['count'];
    }

    public function updateCounts() {
        $sql = "update " . STATS . " set value=(select count(*) from " . SENTENCES . ") where name = 'sentences'";
        $status = $this->executeSQL( $sql );
        if( $status == 1 ) {
            $sql = "update " . STATS . " set value=(select count(*) from " . SOURCES . ") where name = 'sources'";
            $status = $this->executeSQL( $sql );
        }
        return $status;
    }

    public function getSourceIDs( $groupname = '' ) {
        if( !$groupname ) {
            $sql = "select sourceID from " . SOURCES . " order by sourceID";
            $rows = $this->getDBRows( $sql );
        } else {
            $sql = "select sourceID from " . SOURCES . " where groupname = :groupname order by sourceID";
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
        $sql = "update " . SOURCES . " set link = :link, " .
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
            $sql = "replace into " . SOURCES . "(sourceName, link, authors, groupname, title, date) " .
                   "values(:name, :link, :authors, :groupname, :title, :date)";
            return $this->patchSource( $sql, $params );
        }
    }
    
    public function addSomeSentences( $sourceID, $sentences ) {
        $sql = "insert ignore into " . SENTENCES . "(sourceID, hawaiianText) values ";
        $values = [
            'sourceID' => $sourceID,
        ];
        $i = 0;
        for( $i = 0; $i < sizeof($sentences); $i++ ) {
            $sentence = $sentences[$i];
            if( strlen($sentence) < 5 ) {
                continue;
            }
            $sql .= "(:sourceID, :text_$i),";
            $values["text_$i"] = trim( $sentence );
        }
        $sql = trim( $sql, "," );
        //echo( var_export( $sql,true ) . "\n" );
        //echo( var_export( $values,true ) . "\n" );
        //return;

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
    public function addSentences( $sourceID, $sentences ) {
        $maxValues = 30;
        $count = 0;
        for( $i = 0; $i < sizeof( $sentences ); $i += $maxValues ) {
            $len = min( $maxValues, sizeof($sentences) - $i );
            $subset = array_slice( $sentences, $i, $len );
            $status = $this->addSomeSentences( $sourceID, $subset );
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
        $sql = "insert ignore into " . CONTENTS . "(sourceID) values(:sourceID)";
        $values = [
            'sourceID' => $sourceID,
        ];
        $result = $this->executePrepared( $sql, $values );
        debuglog( "Laana::addContentRow($sourceID) returned $result" );
        return $result;
    }
    public function addFullText( $sourceID, $sentences ) {
        $this->addContentRow( $sourceID );
        $sql = "update " . CONTENTS . " set text=:text where sourceid=:sourceID";
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
        $sql = "update " . CONTENTS . " set html=:text where sourceid=:sourceID";
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
        $sql = "replace into " . CONTENTS . "(sourceID, text) values(:sourceID,:text)";
        $values = [
            'sourceID' => $sourceID,
            'text' => $text,
        ];
        $result = ($this->executePrepared( $sql, $values )) ? strlen( $text ) : 0;
        debuglog( "Laana::addText($sourceID) $sql returned $result" );
        return $result;
    }
    public function getRawText( $sourceid ) {
        $sql = "select html from " . CONTENTS . " where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row['html'] ?: '';
    }
    public function getText( $sourceid ) {
        $sql = "select text from " . CONTENTS . " where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row['text'];
    }
    public function removeContents( $sourceid ) {
        $sql = "delete from " . CONTENTS . " where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $result = $this->executePrepared( $sql, $values );
        return $result;
    }
    public function removeSentences( $sourceid ) {
        $sql = "delete from " . SENTENCES . " where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $result = $this->executePrepared( $sql, $values );
        return $result;
    }
    public function addSearchStat( $searchterm, $pattern, $results, $order,$elapsed ) {
        $sql = "insert into " . SEARCHSTATS . "(searchterm,pattern,results,sort,elapsed) values(:searchterm,:pattern,:results,:sort,:elapsed)";
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
    public function getSearchStats() {
        $sql = "select * from " . SEARCHSTATS . " order by created";
        $rows = $this->getDBRows( $sql );
        return $rows;
    }
    public function getSummarySearchStats() {
        $sql = "select pattern,count(*) count from " . SEARCHSTATS . " group by pattern order by pattern";
        $rows = $this->getDBRows( $sql );
        return $rows;
    }
    public function getFirstSearchTime() {
        $sql = "select min(created) first from " . SEARCHSTATS;
        $row = $this->getOneDBRow( $sql );
        return ($row['first']) ? $row['first'] : '';
    }
}
?>
