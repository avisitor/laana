<?php
require_once( 'config.php' );

function debuglog( $msg ) {
    if( is_object( $msg ) || is_array( $msg ) ) {
        $msg = var_export( $msg, true );
    }
    $defaultTimezone = 'Pacific/Honolulu';
    $now = new DateTimeImmutable( "now", new DateTimeZone( $defaultTimezone ) );
    $now = $now->format( 'Y-m-d H:i:s' );
    error_log( "$now " . $_SERVER['SCRIPT_NAME'] . ': ' . $msg . "\n", 3, "/tmp/php_errorlog" );
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
            $servername = $dbconfig["servername"];
            if( $servername == $_SERVER['HTTP_HOST'] ) {
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

        public function getDBRows( $sql, $values = '' ) {
            if( $this->conn == null ) {
                return [];
            }
            debuglog( "getDBRows: " . $sql );
            debuglog( "getDBRows: " . $values );
            try {
                $stmt = $this->conn->prepare( $sql );
                if( $values ) {
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
                //debuglog( 'getDBRows: ' . var_export( $results, true ) );
                return $results;
            } catch(PDOException $e) {
                echo "Execution failed: " . $e->getMessage();
                debuglog( "Execution failed for $sql: " . $e->getMessage() );
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

        public function executePrepared( $sql, $values ) {
            if( $this->conn == null ) {
                return [];
            }
            try {
                $stmt = $this->conn->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
                $stmt->execute( $values );
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

    function sqlNormalize() {
        global $a, $b;
        $search = "replace(hawaiianText,'ō','o')";
        for( $i = 1; $i < sizeof( $a ); $i++ ) {
            $search = "replace($search,'" . $a[$i] . "','" . $b[$i] . "')";
        }
        return $search;
    }

    function normalizeString( $term ) {
        global $a, $b;
        return str_replace($a, $b, $term);
    }

    class Laana extends DB {
        private $pageNumber = 0;
        private $pageSize = 10;
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
        public function getSentences( $term, $pattern, $pageNumber = -1 ) {
            $normalizedTerm = normalizeString( $term );
            $words = preg_split( "/[\s,\?!\.\;\:\(\)]+/",  $term );
            if( $normalizedTerm == $term ) {
                $search = sqlNormalize();
            } else {
                $search = "hawaiianText";
            }
            if( sizeof( $words ) < 2 || $pattern == 'exact' ) {
                $sql = "select authors,sourceName,s.sourceID,link,hawaiianText from sentences s, sources o where $search REGEXP " . "'" . "[[:space:]]" . $term . "[[:space:]]" . "' and s.sourceID = o.sourceID";
            } else {
                $sql = "select authors,sourceName,hawaiianText from sentences s, sources o where $search REGEXP '(";
                if( $pattern == 'order' ) {
                    $sql .= '.*';
                    foreach( $words as $word ) {
                        $sql .= "[[:space:]]" . $word . "[[:space:]]" . '.*';
                    }
                } else {
                    // any
                    foreach( $words as $word ) {
                        $sql .= "[[:space:]]" . $word . "[[:space:]]" . '|';
                    }
                }
                $sql = trim( $sql, '.*|' );
                $sql .= ")' and s.sourceID = o.sourceID group by sentenceID";
            }
            $sql .= " order by rand()";
            if( $pageNumber >= 0 ) {
                $sql .= " limit " . $pageNumber * $this->pageSize . "," . $this->pageSize;
            }
            debuglog( "getSentences: " . $sql );
            $rows = $this->getDBRows( $sql );
            debuglog( "getSentences result: " . var_export( $rows, true ) );
            return $rows;
        }
        public function getSources() {
            $sql = "select o.sourceID,sourceName,authors,link,count(sentenceID) count from sources o,sentences s where o.sourceID = s.sourceID group by o.sourceID";
            $rows = $this->getDBRows( $sql );
            return $rows;
        }
        public function getSourceByName( $name ) {
            //$sql = "select o.sourceID,sourceName,authors,link,count(sentenceID) count from sources o,sentences s where o.sourceID = s.sourceID and sourceName = '$name' group by o.sourceID";
            $sql = "select o.sourceID,sourceName,authors,link from sources o where sourceName = '$name' group by o.sourceID";
            $rows = $this->getDBRows( $sql );
            return ($rows && sizeof($rows) > 0) ? $rows[0] : [];
        }
        public function getSentenceCount() {
            $sql = "select count(sentenceID) count from sentences";
            $rows = $this->getDBRows( $sql );
            debuglog( "getSentenceCount: " . var_export( $rows, true ) );
            return $rows[0]['count'];
        }
    }

    //$db = new Laana();
    //$rows = $db->getSentences( 'malie' );
?>
