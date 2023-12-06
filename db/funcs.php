<?php
require_once( 'config.php' );

function printObject( $obj ) {
    echo( var_export( $obj, true ) . "\n" );
}

function debuglog( $msg ) {
    if( is_object( $msg ) || is_array( $msg ) ) {
        $msg = var_export( $msg, true );
    }
    $defaultTimezone = 'Pacific/Honolulu';
    $now = new DateTimeImmutable( "now", new DateTimeZone( $defaultTimezone ) );
    $now = $now->format( 'Y-m-d H:i:s' );
    error_log( "$now " . $_SERVER['SCRIPT_NAME'] . ': ' . $msg . "\n", 3, "/tmp/php_errorlog" );
    //error_log( "$now " . $_SERVER['SCRIPT_NAME'] . ': ' . $msg . "\n" );
}

function logRequest() {
    debuglog( "REQUEST: " . var_export($_REQUEST, true) );
    debuglog( "SESSION: " . var_export($_SESSION, true) );
    $remote = $_SERVER['REMOTE_ADDR'] . ' - ' . $_SERVER['HTTP_USER_AGENT'];
    debuglog( "remote: $remote" );
    //debuglog( "SERVER: " . var_export($_SERVER, true) );
    debuglog( "COOKIE: " . var_export($_COOKIE, true) );
}

class DB {
    public $conn;
    public function __construct() {
        $this->connect();
        if( $this->conn == null ) {
            debuglog( "Unable to connect to DB" );
        }
    }

    public function connect() {
        global $dbconfig;
	    error_log( "connect: " . var_export( $dbconfig, true ) );
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
                    new PDO("mysql:host=$servername;dbname=$myDB;charset=utf8", $username, $password);
            } catch(PDOException $e) {
                $this->conn =
                    new PDO("mysql:unix_socket=$socket;dbname=$myDB;charset=utf8", $username, $password);
            }
            // set the PDO error mode to exception
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            echo "Connection failed: " . $e->getMessage();
            error_log( "Connection failed: " . $e->getMessage() );
        }
    }

    public function getDBRows( $sql, $values = [] ) {
        if( $this->conn == null ) {
            return [];
        }
        debuglog( "getDBRows sql: " . $sql );
        if( $values && sizeof( $values ) > 0 ) {
            debuglog( "getDBRows values: " . var_export( $values, true ) );
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
            debuglog( 'getDBRows: ' . sizeof( $results ) . " rows returned" );
            return $results;
        } catch(PDOException $e) {
            echo "Execution failed: " . $e->getMessage();
            debuglog( "Execution failed for $sql: " . $e->getMessage() );
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
        if( $this->conn == null ) {
            debuglog( "executePrepared: null connection");
            return [];
        }
        //echo( $sql . " - " . ($values) ? var_export( $values, true ) : '' . "\n" );
        debuglog( "executePrepared: $sql");
        if( $values && sizeof( $values ) > 0 ) {
            $v = $this->abbreviatedValues( $values );
            debuglog( "executePrepared: " . var_export( $v, true) );
        }
        try {
            $stmt = $this->conn->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
            $stmt->execute( $values );
            //echo "sql execution succeeded: $sql\n";
            return 1;
        } catch(PDOException $e) {
            //echo "Execution failed: " . $e->getMessage();
            debuglog( "Execution failed for $sql with " . var_export( $values ) . ": " . $e->getMessage() );
            return 0;
        }
    }

    function updateOneDBRecord( $potential, $params, $table ) {
        debuglog( 'potential - ' . var_export($potential, true) );
        debuglog( 'params - ' . var_export($params, true) );
        $answers = array();
        foreach( $potential as $key ) {
            if( isset($params[$key]) ) {
                $val = htmlspecialchars($params[$key]);
                $answers[$key] = $val;
            }
        }
        if( count( $answers ) < 1 ) {
            debuglog( "updateOneDBRecord: No parameters to update" );
            return $answers;
        }

        $updates = array();
        $keys = "(";
        $values = "values (";
        foreach( $answers as $key=>$value ) {
            $keys .= $key . ",";
            $values .= ":" . $key . ",";
            $sql .= "," . $key;
            array_push( $updates, $key . "=:" . $key );
        }
        $keys = trim( $keys, " ," ) . ")";
        $values = trim( $values, " ," ) . ")";
        $create = "insert into $table $keys $values";
        debuglog( "updateOneDBRecord sql: $create" );
        debuglog( "updateOneDBRecord values: " . var_export( $answers, true ) );
        if( count( $updates ) > 0 ) {
            // Try inserting as a new record
            try {
                $stmt = $this->conn->prepare( $create );
                $stmt->execute( $answers );
            } catch(PDOException $e) {
                $code = $e->getCode();
                if( $code != 23000 ) {
                    debuglog( "updateOneDBRecord: " . $e->getMessage() . ", code - " . $code );
                } else {
                    // duplicate entry exception
                    // Try updating an existing record
                    /*
                       $email = $params['email'];
                       $answers['email'] = $email;
                     */
                    $sql = "update $table set " . implode( ",", $updates ) . " where email=:email";
                    debuglog( "updateOneDBRecord SQL: $sql\n" );
                    try {
                        $stmt = $this->conn->prepare( $sql );
                        $stmt->execute( $answers );
                    } catch(PDOException $e) {
                        echo "Update failed: " . $e->getMessage() . "\n";
                        debuglog( $e->getMessage(), "updateOneDBRecord" );
                    }
                }
            }
        } else {
            debuglog( "updateOneDBRecord: No parameters to update" );
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
    private $pageSize = 100;
    
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
    
    public function getSentences( $term, $pattern, $pageNumber = -1, $options = [] ) {
        debuglog("Laana::getSentences($term,$pattern,$pageNumber)");
        $useRegex = true;
        $useRegex = false;
        $normalizedTerm = normalizeString( $term );
        $words = preg_split( "/[\s,\?!\.\;\:\(\)]+/",  $term );
        $search = "hawaiianText";
        if( $useRegex ) {
            if( $pattern != 'exact' ) {
                // Match with or without diacriticals
                //$search = sqlNormalize();
                //$term = str_replace( 'ʻ', 'ʻ*', $term );

                if( $pattern == 'regex' ) {
                    if( isset( $options['nodiacriticals'] ) ) {
                        $search = 'simplified';
                        $term = $normalizedTerm;
                    }
                } else {
                    $search = 'simplified';
                    $term = $normalizedTerm;
                    $words = preg_split( "/[\s,\?!\.\;\:\(\)]+/",  $term );
                }
            }
            debuglog("Laana::getSentences term=$term, normalizedTerm=$normalizedTerm, words=" . var_export( $words, true ) );
        } else {
            if( $normalizedTerm == $term ) {
                // No diacriticals in the search term
                $search = sqlNormalize();
            } else {
                $search = "hawaiianText";
            }
            //$search = sqlNormalizeOkina();
            $words = preg_split( "/[\s,\?!\.\;\:\(\)]+/",  $normalizedTerm );
            $term = $normalizedTerm;
        }
        $values = [];
        if( $pattern == 'regex' ) {
            $sql = "select authors,sourceName,s.sourceID,link,hawaiianText,sentenceid from sentences s, sources o where $search REGEXP :term and s.sourceID = o.sourceID";
            $term = preg_replace( '/\\\/', '\\\\', $term );
            $values = [
                'term' => $term,
            ];
        } else if( sizeof( $words ) < 2 || $pattern == 'exact' ) {
            if( $useRegex ) {
                $sql = "select authors,sourceName,s.sourceID,link,hawaiianText,sentenceid from sentences s, sources o where $search REGEXP " . "'" . "[[:<:]]" . $term . "[[:>:]]" . "' and s.sourceID = o.sourceID";
            } else {
                $sql = "select authors,sourceName,s.sourceID,link,hawaiianText,sentenceid from sentences s, sources o where ($search like '% $term %' or $search like '% $term.%' or $search like '% $term,%' or $search like '% $term;%' or $search like '% $term!%') and s.sourceID = o.sourceID";

                
                $sql = "select authors,sourceName,s.sourceID,link,hawaiianText,sentenceid from sentences o inner join sources s on s.sourceID = o.sourceID where match(o.hawaiianText) against ('\"$term\"') and hawaiianText like '%$term%'";
            }
        } else {
            if( $useRegex ) {
                $sql = "select authors,sourceName,s.sourceID,hawaiianText,sentenceid from sentences s, sources o where $search REGEXP '(";
                if( $pattern == 'order' ) {
                    $sql .= '.*';
                    foreach( $words as $word ) {
                        $sql .= "[[:<:]]" . $word . "[[:>:]].*";
                    }
                } else {
                    // any
                    foreach( $words as $word ) {
                        $sql .= "[[:<:]]" . $word . "[[:>:]]|";
                    }
                }
                $sql = trim( $sql, '.*|' );
                $sql .= ")'";
            } else {
                $sql = "select authors,sourceName,s.sourceID,hawaiianText,sentenceid from sentences s, sources o where (";
                if( $pattern == 'order' ) {
                    $sql .= " $search like '%";
                    foreach( $words as $word ) {
                        $sql .= $word . " %";
                    }
                    $sql .= "'";
                } else {
                    // any
                    foreach( $words as $word ) {
                        $sql .= "$search like '% $word %' or ";
                    }
                }
                $sql = trim( $sql, ' or ' );
                $sql .= ')';
            }
            $sql .= " and s.sourceID = o.sourceID group by sentenceID";

                
                if( $pattern != 'order' ) {
                    $sql = "select authors,sourceName,s.sourceID,link,hawaiianText,sentenceid from sentences o inner join sources s on s.sourceID = o.sourceID where match(o.hawaiianText) against ('$term')";
                }

        }
        $sql .= " order by rand()";
        if( $pageNumber >= 0 ) {
            $sql .= " limit " . $pageNumber * $this->pageSize . "," . $this->pageSize;
        }
        $rows = $this->getDBRows( $sql, $values );
        debuglog( "getSentences result: " . var_export( $rows, true ) );
        return $rows;
    }
    
    public function getSentencesBySourceID( $sourceid ) {
        $sql = "select sentenceID, hawaiianText from sentences where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $rows = $this->getDBRows( $sql, $values );
        return $rows;
    }
    
    public function getSentence( $sentenceid ) {
        $sql = "select * from sentences where sentenceid = :id";
        $values = [
            'id' => $sentenceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row;
    }
    
    public function getSources() {
        $sql = "select o.*,count(sentenceID) count from sources o,sentences s where o.sourceID = s.sourceID group by o.sourceID order by sourceName";
        $rows = $this->getDBRows( $sql );
        return $rows;
    }
    
    public function getSourceCount() {
        $sql = "select count(*) c from sources";
        $row = $this->getOneDBRow( $sql );
        return $row['c'];
    }
    
    public function getSource( $sourceid ) {
        $sql = "select * from sources o where sourceid = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
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
    
    public function getSentenceCount() {
        $sql = "select count(sentenceID) count from sentences";
        $sql = "select count(*) count from sentences";
        $sql = "select value count from stats where name = 'sentences'";
        $row = $this->getOneDBRow( $sql );
        debuglog( "getSentenceCount: " . var_export( $row, true ) );
        return $row['count'];
    }

    public function updateCounts() {
        $sql = "update stats set value=(select count(*) from sentences) where name = 'sentences'";
        $status = $this->executeSQL( $sql );
        if( $status == 1 ) {
            $sql = "update stats set value=(select count(*) from sources) where name = 'sources'";
            $status = $this->executeSQL( $sql );
        }
        return $status;
    }

    public function getSourceIDs() {
        $sql = "select sourceID from sources order by sourceID";
        $rows = $this->getDBRows( $sql );
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
        $name = $params['sourcename'];
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
            $row = $this->getSourceByName( $name );
            return $row['sourceid'];
        } else {
            return null;
        }
    }

    public function addSource( $name, $params = [] ) {
        $params['sourcename'] = $name;
        if( !$params['date'] ) {
            unset( $params['date'] );
        }
        $link = $params['link'] ?: '';
        $row = $this->getSourceByName( $name );
        if( $row['sourceid'] && $link ) {
            $datespec = ($params['date']) ? ", date = :date" : "";
            $sql = "update sources set link = :link, groupname = :groupname, authors = :authors, " .
                   "title = :title$datespec where sourceName = :name";
            return $this->patchSource( $sql, $params );
        } else {
            $authors = $params['authors'] ?: '';
            $datekey = ($params['date']) ? ", date" : "";
            $datevalue = ($params['date']) ? ", :date" : "";
            $sql = "replace into sources(sourceName, link, authors, groupname$datekey, title) " .
                   "values(:name, :link, :authors, :groupname$datevalue, :title)";
            return $this->patchSource( $sql, $params );
        }
    }
    
    public function addSomeSentences( $sourceID, $sentences ) {
        $sql = "insert ignore into sentences(sourceID, hawaiianText) values ";
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
            return sizeof( $sentences );
        } else {
            return null;
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
        return $count;
    }
    public function addContentRow( $sourceID ) {
        $sql = "insert ignore into contents(sourceID) values(:sourceID)";
        $values = [
            'sourceID' => $sourceID,
        ];
        $result = $this->executePrepared( $sql, $values );
        debuglog( "Laana::addContentRows($sourceID) returned $result" );
        return $result;
    }
    public function addFullText( $sourceID, $sentences ) {
        $this->addContentRow( $sourceID );
        $sql = "update contents set text=:text where sourceid=:sourceID";
        $values = [
            'sourceID' => $sourceID,
            'text' => implode( "\n", $sentences),
        ];
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
        return $row['html'] ?: '';
    }
    public function getText( $sourceid ) {
        $sql = "select text from contents where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $row = $this->getOneDBRow( $sql, $values );
        return $row['text'];
    }
    public function removeContents( $sourceid ) {
        $sql = "delete from contents where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $result = $this->executePrepared( $sql, $values );
        return $result;
    }
    public function removeSentences( $sourceid ) {
        $sql = "delete from sentences where sourceID = :sourceid";
        $values = [
            'sourceid' => $sourceid,
        ];
        $result = $this->executePrepared( $sql, $values );
        return $result;
    }
}
?>
