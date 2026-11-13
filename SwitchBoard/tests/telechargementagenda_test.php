<?php
// Test du téléchargement des agendas (issue #74) : TraitementComite::telechargerAgenda(), la seule
// fonction qui télécharge un agenda, utilisée par le cron et par la page de diagnostic.
// Vérifie qu'un agenda qui refuse les requêtes HEAD (Outlook / Office 365) est bien téléchargé,
// qu'une page d'erreur n'est jamais prise pour un agenda, et que CalDAV fonctionne.
//
// Usage : démarrer d'abord le faux serveur d'agendas :
//   php -S 127.0.0.1:8995 stubagendaserver.php &
// puis : php telechargementagenda_test.php (aucune BD ; demande les dépendances de composer
// install, comme l'application)

const SERVEUR = 'http://127.0.0.1:8995';

// Versions minimales des classes dont TraitementComite a besoin
class InformationsUtilisateur {
	public string $calendarurl = '';
	public string $calendartype = 'secreturl';
	public string $calendarusername = '';
	public string $calendarpassword = '';
}
class Courriel {}
require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/ClientVoipms.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/GroupeSonnerie.php';

require_once __DIR__.'/verifie.php';

// Sans passer par le constructeur, qui ouvre une connexion à la vraie BD
$infos = new InformationsUtilisateur();
$calendrier = (new ReflectionClass(TraitementComite::class))->newInstanceWithoutConstructor();
$propriete = new ReflectionProperty(TraitementComite::class, 'userinformations');
$propriete->setAccessible(true);
$propriete->setValue($calendrier, $infos);

// Télécharge l'agenda configuré dans $infos : ['contenu' => ..., 'erreur' => ...], l'un des deux vide
function telecharger(): array {
	global $calendrier;
	try {
		return ['contenu' => $calendrier->telechargerAgenda(), 'erreur' => ''];
	} catch (Exception $e) {
		return ['contenu' => '', 'erreur' => $e->getMessage()];
	}
}

// --- URL secrète ---------------------------------------------------------------------------
$infos->calendarurl = SERVEUR.'/agenda.ics';
$resultat = telecharger();
verifie(strpos($resultat['contenu'], 'BEGIN:VCALENDAR') !== false,
	"Agenda qui refuse HEAD (comme Outlook) : téléchargé par GET ({$resultat['erreur']})");

$infos->calendarurl = SERVEUR.'/redirection';
$resultat = telecharger();
verifie(strpos($resultat['contenu'], 'BEGIN:VCALENDAR') !== false, "Redirection suivie ({$resultat['erreur']})");

$infos->calendarurl = SERVEUR.'/pagehtml';
$resultat = telecharger();
verifie($resultat['contenu'] === '' && strpos($resultat['erreur'], 'BEGIN:VCALENDAR') !== false,
	"Page HTML avec code 200 : refusée, ce n'est pas un agenda ({$resultat['erreur']})");

$infos->calendarurl = SERVEUR.'/absent';
$resultat = telecharger();
verifie($resultat['contenu'] === '' && strpos($resultat['erreur'], '404') !== false,
	"Adresse inexistante : erreur avec le code 404 ({$resultat['erreur']})");

$infos->calendarurl = 'http://127.0.0.1:1/agenda.ics';
$resultat = telecharger();
verifie($resultat['contenu'] === '' && $resultat['erreur'] !== '',
	"Serveur injoignable : erreur claire ({$resultat['erreur']})");

$infos->calendarurl = '';
$resultat = telecharger();
verifie($resultat['contenu'] === '' && $resultat['erreur'] !== '', "Aucune adresse configurée : erreur ({$resultat['erreur']})");

// --- CalDAV --------------------------------------------------------------------------------
$infos->calendartype = 'caldav';
$infos->calendarurl = SERVEUR.'/caldav';
$infos->calendarusername = 'agenda';
$infos->calendarpassword = 'secret';
$resultat = telecharger();
verifie(strpos($resultat['contenu'], 'BEGIN:VCALENDAR') !== false && strpos($resultat['contenu'], 'SUMMARY:514-555-1234') !== false,
	"CalDAV : réponse 207 fusionnée en un agenda ({$resultat['erreur']})");

$infos->calendarpassword = 'mauvais';
$resultat = telecharger();
verifie($resultat['contenu'] === '' && strpos($resultat['erreur'], '401') !== false,
	"CalDAV, mauvais mot de passe : erreur avec le code 401 ({$resultat['erreur']})");

echo $echecs === 0 ? "\nTous les tests du téléchargement des agendas ont réussi.\n" : "\n$echecs test(s) en échec.\n";
exit($echecs === 0 ? 0 : 1);
