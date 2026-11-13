-- Durée de sonnerie et « Faire le 1 » (issues #62 et #35)

-- Réglage du comité, utilisé quand l'agenda ne donne pas de repère pour un répondant
ALTER TABLE userinfo
    ADD COLUMN ringtime TINYINT NULL DEFAULT NULL
        COMMENT 'Durée de sonnerie en secondes (1 à 60), NULL = valeur par défaut (25)',
    ADD COLUMN pressone TINYINT(1) NULL DEFAULT NULL
        COMMENT 'Faire le 1 pour accepter l''appel (0 ou 1), NULL = valeur par défaut (1)';

-- Ce qui a été envoyé pour chaque répondant : un changement de réglage doit être renvoyé à voip.ms
ALTER TABLE ringgroupmember
    ADD COLUMN ringtime TINYINT NOT NULL DEFAULT 25
        COMMENT 'Durée de sonnerie envoyée, en secondes',
    ADD COLUMN pressone TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Faire le 1 envoyé (0 ou 1)';
