-- Compte régional : reçoit le rapport de coûts complet de tous les DID de son compte voip.ms
-- (un compte voip.ms partagé par plusieurs comités, p. ex. le trésorier d'une région)
ALTER TABLE userservice
    ADD COLUMN regionalaccount TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Compte régional (0 ou 1) : rapport de coûts complet, statistiques mensuelles seulement';
