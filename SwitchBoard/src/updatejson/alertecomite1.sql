-- La mémoire des alertes (CONVENTIONS.md, section 6) : la date du dernier courriel réussi pour un problème d'un
-- comité, et le nombre de passages en échec de suite. La ligne iduser = 0 compte les passages où tous les comités échouent.
CREATE TABLE IF NOT EXISTS `alertecomite` (
	`iduser` int(11) NOT NULL,
	`probleme` varchar(50) NOT NULL,
	`passagesdesuite` int(11) NOT NULL DEFAULT 0,
	`dernierenvoi` datetime NULL,
	PRIMARY KEY (`iduser`, `probleme`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
