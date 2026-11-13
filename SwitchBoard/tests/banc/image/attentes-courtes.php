<?php
// Chargé avant chaque script du banc (auto_prepend_file, voir le Dockerfile) : le second essai d'un courriel attend 5 s
// et celui du canari 30 s en production, ce qui ralentirait chaque scénario qui provoque une panne de courriel.
// Le scénario 35_alertes vérifie que le code garde ses vraies valeurs sans ce fichier.
define('DELAI_SECOND_ESSAI_COURRIEL', 0);
define('DELAI_SECOND_ESSAI_CANARI', 0);
