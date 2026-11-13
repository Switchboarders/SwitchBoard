-- Structure figée de la BD de SwitchBoard à la version 127 (tâche 4.4). Aucune donnée.
-- Relevée sur le serveur principal (voir tests/banc/structure/).
-- Une installation neuve part d'ici, puis reçoit les migrations de dbVersion.json au-dessus de la version 127.
-- Ce fichier ne change JAMAIS : un changement de structure s'ajoute comme migration (updatejson/).

CREATE TABLE `alertecomite` (
  `iduser` int(11) NOT NULL,
  `probleme` varchar(50) NOT NULL,
  `passagesdesuite` int(11) NOT NULL DEFAULT 0,
  `dernierenvoi` datetime DEFAULT NULL,
  PRIMARY KEY (`iduser`,`probleme`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `parameters` (
  `paramname` varchar(30) NOT NULL,
  `paramvalue` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `ringgroupmember` (
  `iduser` int(11) NOT NULL,
  `position` tinyint(4) NOT NULL,
  `phonenumber` varchar(20) NOT NULL,
  `forwardingid` varchar(20) NOT NULL COMMENT 'Identifiant du renvoi d''appel chez voip.ms',
  `ringtime` tinyint(4) NOT NULL DEFAULT 25 COMMENT 'Durée de sonnerie envoyée, en secondes',
  `pressone` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Faire le 1 envoyé (0 ou 1)',
  PRIMARY KEY (`iduser`,`position`),
  KEY `forwardingid` (`forwardingid`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `ringgroupstate` (
  `iduser` int(11) NOT NULL,
  `voipmsusername` varchar(100) NOT NULL COMMENT 'Réglage au moment de l''envoi : s''il change, resynchronisation complète',
  `ringgroupname` varchar(100) NOT NULL COMMENT 'Réglage au moment de l''envoi : s''il change, resynchronisation complète',
  `lastupdate` datetime NOT NULL COMMENT 'Dernier envoi réussi à voip.ms',
  `lastfullsync` datetime NOT NULL COMMENT 'Dernière resynchronisation complète (une par jour, après 3 h)',
  PRIMARY KEY (`iduser`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `sbchat` (
  `iduser` int(11) NOT NULL,
  `sbchatapitoken` varchar(500) DEFAULT NULL,
  `sbchatcalendarurl` varchar(500) DEFAULT NULL,
  `sbchatdidnumber` varchar(11) DEFAULT NULL,
  `sbchatserveraddress` varchar(500) DEFAULT NULL,
  `masurl` varchar(500) DEFAULT NULL,
  `masclientid` varchar(1000) DEFAULT NULL,
  `massecret` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`iduser`),
  UNIQUE KEY `sbchatapitoken` (`sbchatapitoken`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `user` (
  `iduser` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(100) NOT NULL,
  `privilegelevel` int(11) NOT NULL,
  PRIMARY KEY (`iduser`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `userinfo` (
  `iduserinfo` int(11) NOT NULL AUTO_INCREMENT,
  `iduser` int(11) NOT NULL,
  `voipmsusername` varchar(100) NOT NULL,
  `voipmsapipassword` varchar(500) NOT NULL,
  `calendarurl` varchar(500) NOT NULL,
  `validationfeedbackemail` varchar(100) NOT NULL,
  `ringgroupname` varchar(100) NOT NULL,
  `didnumber` varchar(11) NOT NULL,
  `language` varchar(2) NOT NULL,
  `calendartype` enum('secreturl','caldav') NOT NULL DEFAULT 'secreturl' COMMENT 'Type de mécanisme pour récupérer l''agenda : URL secrète (Google/Outlook) ou CalDAV authentifié (cPanel)',
  `calendarusername` varchar(100) DEFAULT NULL COMMENT 'Requis seulement si calendartype = caldav',
  `calendarpassword` varchar(500) DEFAULT NULL COMMENT 'Chiffré via CryptoManager (préfixe enc:v1:), requis seulement si calendartype = caldav',
  `ringtime` tinyint(4) DEFAULT NULL COMMENT 'Durée de sonnerie en secondes (1 à 60), NULL = valeur par défaut (25)',
  `pressone` tinyint(1) DEFAULT NULL COMMENT 'Faire le 1 pour accepter l''appel (0 ou 1), NULL = valeur par défaut (1)',
  PRIMARY KEY (`iduserinfo`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `userservice` (
  `iduser` int(11) NOT NULL,
  `ringgroupupdate` tinyint(1) NOT NULL,
  `smsdaybefore` tinyint(1) NOT NULL,
  `smshourbefore` tinyint(1) NOT NULL,
  `sendstats` tinyint(1) NOT NULL,
  `sbchatupdate` tinyint(1) NOT NULL,
  `mainaccount` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Compte principal (0 ou 1) : paie le compte voip.ms, reçoit le rapport de coûts complet',
  PRIMARY KEY (`iduser`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `usertokenaccess` (
  `iduser` int(11) NOT NULL,
  `token` varchar(200) NOT NULL,
  `expiration` datetime DEFAULT NULL,
  PRIMARY KEY (`iduser`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
