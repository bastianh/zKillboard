
DROP TABLE IF EXISTS `zz_manual_mails`;
CREATE TABLE `zz_manual_mails` (
  `mKillID` int(11) NOT NULL AUTO_INCREMENT,
  `hash` varchar(64) NOT NULL,
  `killID` int(11) DEFAULT NULL,
  `eveKillID` int(11) DEFAULT NULL,
  PRIMARY KEY (`mKillID`),
  UNIQUE KEY `hash` (`hash`),
  KEY `killID` (`killID`),
  KEY `eveKillID` (`eveKillID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=COMPRESSED;

