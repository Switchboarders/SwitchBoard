<?php
// Sur une installation cron classique (WHC et la plupart des hébergements conventionnels), cron
// lui-même envoie un courriel contenant toute la sortie standard (stdout/stderr) d'une tâche dès
// qu'elle en produit UNE LIGNE - même si tout s'est bien passé. Comme ce script affiche toujours
// plusieurs echo (progression, résumé...), ça veut dire un courriel de cron à CHAQUE exécution,
// pure nuisance quand tout va bien. D'où ">/dev/null 2>&1" ajouté à la commande suggérée : ça
// coupe ce courriel de sortie généré par cron.
//
// Ceci ne touche PAS aux courriels de rapport d'erreur envoyés par l'application elle-même
// (Courriel::sendemail()/sendemailtoadmin() - voir TraitementComite.php) quand un agenda/
// comité précis a un vrai problème : ces courriels-là restent voulus, au plus un par 24 heures
// et par problème (CONVENTIONS.md, section 6), à l'utilisateur concerné ou à l'admin selon le
// problème. --silencieux les aurait supprimés à tort, donc ce drapeau n'existe plus. Rediriger la
// sortie standard ne les affecte pas : mail() parle directement au serveur de courriel, pas à stdout.
//
// On ne suggère ">/dev/null 2>&1" que si un dead-man's-switch est DÉJÀ configuré pour ce check
// (?page=deadmanswitch) : sans lui, ce courriel de sortie de cron reste le seul filet de sécurité
// en cas de plantage complet du script (erreur fatale non rattrapée) sur une installation qui n'a
// pas encore de surveillance externe.
//
// On a conventional cron install (WHC and most regular hosting), cron itself emails a task's
// full standard output (stdout/stderr) as soon as it produces even ONE line - even when
// everything went fine. Since this script always prints several echo lines (progress,
// summary...), that means a cron email on EVERY run, pure noise when all is well. Hence
// ">/dev/null 2>&1" added to the suggested command: it stops that cron-generated output email.
//
// This does NOT affect the error-report emails sent by the application itself
// (Courriel::sendemail()/sendemailtoadmin() - see TraitementComite.php) when a specific
// calendar/committee has a real problem: those emails stay wanted, at most one per 24 hours and
// per problem (CONVENTIONS.md, section 6), to the affected user or the admin depending on the
// problem. A --silencieux flag would have wrongly suppressed them, hence it no longer exists.
// Redirecting standard output doesn't affect them: mail() talks directly to the mail server, not
// to stdout.
//
// ">/dev/null 2>&1" is only suggested if a dead-man's-switch is ALREADY configured for that check
// (?page=deadmanswitch): without it, that cron output email remains the only safety net for a
// complete script crash (uncaught fatal error) on an install that has no external monitoring yet.
$dmsRinggroupConfigure = reglagesDeadManSwitch('ringgroup')['success_url'] !== '';
$dmsSbchatConfigure = reglagesDeadManSwitch('sbchat')['success_url'] !== '';
$suffixeRinggroup = $dmsRinggroupConfigure ? ' >/dev/null 2>&1' : '';
$suffixeSbchat = $dmsSbchatConfigure ? ' >/dev/null 2>&1' : '';
// Le PHP de cette installation, pas celui que le serveur offre par défaut (sur cPanel, /usr/local/bin/php peut être une
// vieille version) : PHP_BINDIR est le dossier des programmes de la version qui affiche cette page.
$php = is_file(PHP_BINDIR.'/php') ? PHP_BINDIR.'/php' : '/usr/local/bin/php';

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/admin/cronsuggestion.vue.php';
