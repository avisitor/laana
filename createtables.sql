CREATE TABLE `sentences` (
  `sentenceID` int(11) NOT NULL AUTO_INCREMENT,
  `sourceID` int(11) NOT NULL,
  `hawaiianText` text DEFAULT NULL,
  `englishText` varchar(255) DEFAULT NULL,
  `created` datetime DEFAULT current_timestamp(),
  `simplified` text DEFAULT NULL,
  wordCount INT DEFAULT 0,
  UNIQUE KEY `sentenceID` (`sentenceID`),
  KEY `hawaiian` (`sourceID`,`hawaiianText`(100)),
  KEY `sourceID` (`sourceID`),
  KEY `wordCount` (`wordCount`),
  FULLTEXT KEY `hawaiianText` (`hawaiianText`),
  FULLTEXT KEY `simplified` (`simplified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `sentence_patterns` (
  `patternid` int(11) NOT NULL AUTO_INCREMENT,
  `sentenceid` bigint(20) DEFAULT NULL,
  `pattern_type` text NOT NULL,
  `signature` text DEFAULT NULL,
  `confidence` float DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`patternid`),
  KEY `idx_pattern_type` (`pattern_type`(768)),
  KEY `idx_sentenceid` (`sentenceid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sources` (
  `sourceID` int(11) NOT NULL AUTO_INCREMENT,
  `sourceName` varchar(200) NOT NULL,
  `authors` text DEFAULT NULL,
  `link` text NOT NULL,
  `created` datetime DEFAULT current_timestamp(),
  `groupname` varchar(20) NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `date` date DEFAULT NULL,
  PRIMARY KEY (`sourceID`),
  UNIQUE KEY `sourceID` (`sourceID`),
  UNIQUE KEY `link` (`link`) USING HASH,
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `searchstats` (
  `searchterm` varchar(255) NOT NULL,
  `created` datetime DEFAULT current_timestamp(),
  `results` int(11) DEFAULT NULL,
  `pattern` varchar(10) DEFAULT NULL,
  `elapsed` int(11) DEFAULT NULL,
  `sort` varchar(15) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `stats` (
  `name` varchar(255) NOT NULL,
  `value` int(11) NOT NULL,
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `contents` (
  `sourceID` int(11) NOT NULL,
  `html` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `text` mediumtext DEFAULT NULL,
  wordCount INT DEFAULT 0,
  `created` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`sourceID`),
  UNIQUE KEY `sourceID` (`sourceID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `processing_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `operation_type` varchar(50) NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `groupname` varchar(50) DEFAULT NULL,
  `parser_key` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'started',
  `sentences_count` int(11) DEFAULT 0,
  `started_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `operation_type` (`operation_type`),
  KEY `source_id` (`source_id`),
  KEY `groupname` (`groupname`),
  KEY `status` (`status`),
  KEY `started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `grammar_pattern_counts` (
  `pattern_type` varchar(255) NOT NULL,
  `total_count` int(11) NOT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`pattern_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //
CREATE PROCEDURE refresh_grammar_counts()
BEGIN
    REPLACE INTO grammar_pattern_counts (pattern_type, total_count)
    SELECT pattern_type, COUNT(*) 
    FROM sentence_patterns
    GROUP BY pattern_type;
END //
DELIMITER ;

CREATE EVENT hourly_grammar_refresh
ON SCHEDULE EVERY 1 HOUR
DO CALL refresh_grammar_counts();

DELIMITER //
CREATE FUNCTION simplify_hawaiian(str TEXT)
RETURNS TEXT
DETERMINISTIC
BEGIN
    IF str IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
           REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
               str,
               'ō','o'),'ī','i'),'ē','e'),'ū','u'),'ā','a'),
               'Ō','O'),'Ī','I'),'Ē','E'),'Ū','U'),'Ā','A'),
               '‘',''),'ʻ','');
END;
//
DELIMITER ;

DELIMITER //
CREATE FUNCTION hawaiian_word_count(str TEXT)
RETURNS INT
DETERMINISTIC
BEGIN
    IF str IS NULL OR TRIM(str) = '' THEN
        RETURN 0;
    END IF;

    SET str = TRIM(REPLACE(REPLACE(str, ',', ' '), ';', ' '));
    IF str = '' THEN
      RETURN 0;
    END IF;
    RETURN LENGTH(str)
      - LENGTH(REPLACE(str, ' ', ''))
      + 1;
END;
//
DELIMITER ;

DELIMITER //
CREATE FUNCTION hawaiian_syllable_count(str TEXT)
RETURNS INT
DETERMINISTIC
BEGIN
    DECLARE cleaned TEXT;
    DECLARE i INT DEFAULT 1;
    DECLARE len INT;
    DECLARE c CHAR(1);
    DECLARE in_vowel BOOLEAN DEFAULT FALSE;
    DECLARE syllables INT DEFAULT 0;

    IF str IS NULL OR TRIM(str) = '' THEN
        RETURN 0;
    END IF;

    -- Normalize macrons to plain vowels
    SET cleaned = simplify_hawaiian(str);
    SET len = CHAR_LENGTH(cleaned);

    WHILE i <= len DO
        SET c = SUBSTRING(cleaned, i, 1);

        IF c IN ('a','e','i','o','u','A','E','I','O','U') THEN
            IF NOT in_vowel THEN
                SET syllables = syllables + 1;
                SET in_vowel = TRUE;
            END IF;
        ELSE
            SET in_vowel = FALSE;
        END IF;

        SET i = i + 1;
    END WHILE;

    RETURN syllables;
END;
//
DELIMITER ;

DELIMITER //
CREATE TRIGGER sentences_before_insert
BEFORE INSERT ON sentences
FOR EACH ROW
BEGIN
    SET NEW.simplified     = simplify_hawaiian(NEW.hawaiianText);
    SET NEW.wordCount     = hawaiian_word_count(NEW.hawaiianText);
END;
//
DELIMITER ;

DELIMITER //
CREATE TRIGGER sentences_before_update
BEFORE UPDATE ON sentences
FOR EACH ROW
BEGIN
    IF NEW.hawaiianText <> OLD.hawaiianText THEN
        SET NEW.simplified     = simplify_hawaiian(NEW.hawaiianText);
        SET NEW.wordCount     = hawaiian_word_count(NEW.hawaiianText);
    END IF;
END;
//

DELIMITER ;

DELIMITER //
CREATE TRIGGER contents_before_insert
BEFORE INSERT ON contents
FOR EACH ROW
BEGIN
    SET NEW.wordCount =
        hawaiian_word_count(NEW.text);
END;
//
DELIMITER ;

DELIMITER //
CREATE TRIGGER contents_before_update
BEFORE UPDATE ON contents
FOR EACH ROW
BEGIN
    IF NEW.text <> OLD.text THEN
        SET NEW.wordCount =
            hawaiian_word_count(NEW.text);
    END IF;
END;
//
DELIMITER ;


DELIMITER //
//
CREATE TRIGGER insert_sentences AFTER INSERT ON sentences
FOR EACH ROW
  INSERT INTO stats (name, value)
  VALUES ('sentences', 1)
  ON DUPLICATE KEY UPDATE value = value + 1;
//
CREATE TRIGGER delete_sentences AFTER DELETE ON sentences
FOR EACH ROW 
BEGIN
  DELETE FROM sentence_patterns WHERE sentenceid = OLD.sentenceID;
  UPDATE stats SET value = value - 1 WHERE name = 'sentences';
END;
//
CREATE TRIGGER insert_sources AFTER INSERT ON sources
FOR EACH ROW
  INSERT INTO stats (name, value)
  VALUES ('sources', 1)
  ON DUPLICATE KEY UPDATE value = value + 1;
//
CREATE TRIGGER delete_sources AFTER DELETE ON sources
FOR EACH ROW
BEGIN
  DELETE FROM sentences WHERE sourceid = OLD.sourceID;
  UPDATE stats SET value = value - 1 where name = 'sources';
END;
//
CREATE TRIGGER insert_sentence_patterns AFTER INSERT ON sentence_patterns
FOR EACH ROW
  INSERT INTO stats (name, value)
  VALUES ('sentence_patterns', 1)
  ON DUPLICATE KEY UPDATE value = value + 1;
//
CREATE TRIGGER delete_sentence_patterns AFTER DELETE ON sentence_patterns
FOR EACH ROW UPDATE stats SET value = value - 1 where name = 'sentence_patterns';
//
CREATE TRIGGER insert_contents AFTER INSERT ON contents
FOR EACH ROW
  INSERT INTO stats (name, value)
  VALUES ('contents', 1)
  ON DUPLICATE KEY UPDATE value = value + 1;
//
CREATE TRIGGER delete_contents AFTER DELETE ON contents
FOR EACH ROW UPDATE stats SET value = value - 1 where name = 'contents';
//
DELIMITER ;


