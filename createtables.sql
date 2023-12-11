CREATE TABLE `sentences` (
  `sentenceID` int(11) NOT NULL AUTO_INCREMENT,
  `sourceID` int(11) NOT NULL,
  `hawaiianText` text,
  `englishText` varchar(255) DEFAULT NULL,
  created DATETIME DEFAULT CURRENT_TIMESTAMP,
  FULLTEXT (hawaiianText),
  KEY `sourceID` (`sourceID`),
  PRIMARY KEY (sourceID, hawaiianText(100)),
  UNIQUE KEY `sentenceID` (`sentenceID`)
) ENGINE=InnoDB AUTO_INCREMENT=27906 DEFAULT CHARSET=utf8;

CREATE TABLE `sources` (
  `sourceID` int(11) NOT NULL AUTO_INCREMENT,
  `sourceName` text DEFAULT NULL,
  `authors` text DEFAULT NULL,
  `link` text DEFAULT NULL,
  `start` int(11) DEFAULT NULL,
  `end` int(11) DEFAULT NULL,
  groupname varchar(20)b,
  title varchar(100),
  date DATE,
  created DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sourceID`),
  UNIQUE KEY `sourceID` (`sourceID`),
  UNIQUE KEY `sourceName` (`sourceName`) USING HASH
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8;

CREATE TABLE `searchstats` (
  `searchterm` varchar(255) NOT NULL,
  `count` int(11) NOT NULL,
  UNIQUE KEY `searchterm` (`searchterm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `stats` (
  `name` varchar(255) NOT NULL,
  `value` int(11) NOT NULL,
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `contents` (
  `sourceID` int(11) NOT NULL,
  `html` text,
  `text` text,
  created DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sourceID`),
  UNIQUE KEY `sourceID` (`sourceID`)
) ENGINE=InnoDB AUTO_INCREMENT=27906 DEFAULT CHARSET=utf8;

DELIMITER //
CREATE TRIGGER insert_sentences AFTER INSERT ON sentences
FOR EACH ROW UPDATE stats SET value = value + 1 where name = 'sentences';

DELIMITER //
CREATE TRIGGER update_sentences AFTER INSERT ON sentences
FOR EACH ROW
update sentences set simplified = replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(hawaiianText,'ō','o'),'ī','i'),'ē','e'),'ū','u'),'ā','a'),'Ō','O'),'Ī','I'),'Ē','E'),'Ū','U'),'Ā','A'),'‘',''),'ʻ','') where sentenceid = NEW.sentenceid;
//
DELIMITER ;

DELIMITER //
CREATE TRIGGER delete_sentences AFTER DELETE ON sentences
FOR EACH ROW UPDATE stats SET value = value - 1 where name = 'sentences';
//
CREATE TRIGGER insert_sources AFTER INSERT ON sources
FOR EACH ROW UPDATE stats SET value = value + 1 where name = 'sources';
//
CREATE TRIGGER delete_sources AFTER DELETE ON sources
FOR EACH ROW UPDATE stats SET value = value - 1 where name = 'sources';
//
DELIMITER ;

