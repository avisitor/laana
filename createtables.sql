CREATE TABLE `sentences` (
  `sentenceID` int(11) NOT NULL AUTO_INCREMENT,
  `sourceID` int(11) NOT NULL,
  `hawaiianText` varchar(255) NOT NULL,
  `englishText` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`sentenceID`),
  UNIQUE KEY `sentenceID` (`sentenceID`)
) ENGINE=InnoDB AUTO_INCREMENT=27906 DEFAULT CHARSET=utf8;

CREATE TABLE `sources` (
  `sourceID` int(11) NOT NULL AUTO_INCREMENT,
  `sourceName` text DEFAULT NULL,
  `authors` text DEFAULT NULL,
  `link` text DEFAULT NULL,
  `start` int(11) DEFAULT NULL,
  `end` int(11) DEFAULT NULL,
  PRIMARY KEY (`sourceID`),
  UNIQUE KEY `sourceID` (`sourceID`),
  UNIQUE KEY `sourceName` (`sourceName`) USING HASH
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8;

CREATE TABLE `searchstats` (
  `searchterm` varchar(255) NOT NULL,
  `count` int(11) NOT NULL,
  UNIQUE KEY `searchterm` (`searchterm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `fulltext` (
  `textID` int(11) NOT NULL AUTO_INCREMENT,
  `sourceID` int(11) NOT NULL,
  `text` text,
  PRIMARY KEY (`textID`),
  UNIQUE KEY `textID` (`textID`)
) ENGINE=InnoDB AUTO_INCREMENT=27906 DEFAULT CHARSET=utf8;

