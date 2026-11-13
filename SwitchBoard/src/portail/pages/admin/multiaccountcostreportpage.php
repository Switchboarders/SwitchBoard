<?php
// Comparaison des coûts entre plusieurs comptes voip.ms (mois par mois) + solde actuel de
// chacun, pour appuyer une décision de consolidation de plusieurs comptes en un seul. Réutilise
// ComparaisonCouts (calcul par petites étapes) et TacheComparaisonCouts (pilotage de
// ces étapes) - voir ces fichiers pour la logique.
//
// Cette page ne fait qu'afficher le formulaire de sélection : le calcul (potentiellement long -
// une période longue ou plusieurs comptes peut dépasser le délai d'exécution par défaut de PHP
// s'il était fait en un seul appel) est fractionné en petites étapes pilotées par le JS ci-dessous
// via de petits appels POST successifs (action=init_job puis plusieurs action=process_step),
// chacun assez court pour ne jamais dépasser ce délai - voir AdminPanel::
// displayMultiAccountCostReportPage() pour leur traitement, qui les intercepte AVANT le rendu
// habituel de cette page (nécessaire pour répondre en JSON pur, sans le HTML déjà émis par
// userpages/header.php à ce stade).

$manager = new ComparaisonCouts();
$comptesAdmissibles = $manager->listEligibleAccounts($this->webpageparent->databasemanager);

// Le jeton CSRF que le JavaScript de la page joint à ses appels d'étapes
$jetonCsrf = $_SESSION['csrf_token'] ?? '';

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/admin/multiaccountcostreportpage.vue.php';
