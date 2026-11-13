-- « Compte régional » devient « compte principal » (vocabulaire choisi le 2026-11-02) : le user qui
-- paie le compte voip.ms d'un regroupement de comités locaux (une région ou une organisation).
-- Même colonne, nouveau nom : les valeurs déjà cochées sont conservées.
ALTER TABLE userservice
    CHANGE COLUMN regionalaccount mainaccount TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Compte principal (0 ou 1) : paie le compte voip.ms, reçoit le rapport de coûts complet';
