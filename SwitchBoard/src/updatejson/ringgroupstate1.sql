-- Liste conservée de chaque comité (issue #63) : ce qui a été envoyé avec succès à voip.ms au
-- dernier passage, pour ne rien envoyer quand les répondants n'ont pas changé.
CREATE TABLE IF NOT EXISTS `ringgroupstate` (
	`iduser` int(11) NOT NULL,
	`voipmsusername` varchar(100) NOT NULL COMMENT 'Réglage au moment de l''envoi : s''il change, resynchronisation complète',
	`ringgroupname` varchar(100) NOT NULL COMMENT 'Réglage au moment de l''envoi : s''il change, resynchronisation complète',
	`lastupdate` datetime NOT NULL COMMENT 'Dernier envoi réussi à voip.ms',
	`lastfullsync` datetime NOT NULL COMMENT 'Dernière resynchronisation complète (une par jour, après 3 h)',
	PRIMARY KEY (`iduser`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Les répondants envoyés, dans l'ordre du groupe de sonnerie
CREATE TABLE IF NOT EXISTS `ringgroupmember` (
	`iduser` int(11) NOT NULL,
	`position` tinyint(4) NOT NULL,
	`phonenumber` varchar(20) NOT NULL,
	`forwardingid` varchar(20) NOT NULL COMMENT 'Identifiant du renvoi d''appel chez voip.ms',
	PRIMARY KEY (`iduser`, `position`),
	KEY `forwardingid` (`forwardingid`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
