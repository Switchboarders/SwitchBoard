ALTER TABLE userinfo
    ADD COLUMN calendartype ENUM('secreturl', 'caldav') NOT NULL DEFAULT 'secreturl'
        COMMENT 'Type de mécanisme pour récupérer l''agenda : URL secrète (Google/Outlook) ou CalDAV authentifié (cPanel)',
    ADD COLUMN calendarusername VARCHAR(100) NULL
        COMMENT 'Requis seulement si calendartype = caldav',
    ADD COLUMN calendarpassword VARCHAR(500) NULL
        COMMENT 'Chiffré via CryptoManager (préfixe enc:v1:), requis seulement si calendartype = caldav';
