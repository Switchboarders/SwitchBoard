ALTER TABLE userservice
ADD COLUMN sbchatupdate tinyint(1) NOT NULL;
CREATE TABLE `sbchat` (
				`iduser` int(11) NOT NULL,
				`sbchatapitoken` varchar(128), 
				`sbchatcalendarurl` varchar(500),
                `sbchatdidnumber` varchar(11),
                `sbchatserveraddress` varchar(500)
		) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
ALTER TABLE `sbchat`
			ADD PRIMARY KEY (`iduser`),
			ADD UNIQUE KEY `sbchatapitoken` (`sbchatapitoken`);