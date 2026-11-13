<?php
// Mise à jour de SwitchBoard par SSH, le même moteur que le bouton de la console :
//   php src/miseajour.php
// À préférer à la console quand un navigateur ou le serveur web peut couper la requête en cours de route.
//
// Ligne de commande seulement : un déclenchement par URL n'a pas sa place ici, même avec la clé de cron.
//
// Avant le premier require, rien d'autre que la syntaxe de PHP 7.4 : le php par défaut de certains serveurs
// est 7.4, et c'est ce fichier qui doit lui dire comment appeler le bon. Ces messages n'ont pas de _() parce
// que gettext n'est peut-être pas chargé.
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit("Accès refusé : ce script se lance en ligne de commande, avec un PHP de type cli.\n"
		."Sur cPanel : /opt/cpanel/ea-php84/root/usr/bin/php src/miseajour.php\n");
}
if (PHP_VERSION_ID < 80300) {
	fwrite(STDERR, "PHP 8.3 minimum est requis, et ce php est en version ".PHP_VERSION.".\n"
		."Sur cPanel, appelez le bon PHP : /opt/cpanel/ea-php84/root/usr/bin/php ".__FILE__."\n");
	exit(1);
}
// Sous root, le nouveau src/ appartiendrait à root, et les crons et le portail ne pourraient plus y écrire
if (function_exists('posix_geteuid') && (posix_geteuid() === 0 || posix_geteuid() !== fileowner(__DIR__))) {
	$proprietaire = posix_getpwuid(fileowner(__DIR__));
	$nom = $proprietaire === false ? fileowner(__DIR__) : $proprietaire['name'];
	fwrite(STDERR, "Ce script doit tourner sous le propriétaire de src/ ($nom), jamais sous root.\n"
		."Sous Docker : docker exec -u www-data <conteneur php> php ".__FILE__."\n");
	exit(1);
}

ini_set('memory_limit', '512M');
require_once __DIR__.'/commun/lesconstantes.php';
require_once SRC_DIRECTORY.'updatemanager.php';
require_once SRC_DIRECTORY.'utils/verrouexclusif.php';

// lesconstantes.php coupe l'affichage des erreurs : ici, personne d'autre que l'admin ne lit la sortie, et
// une erreur fatale muette serait pire que tout.
ini_set('display_errors', 'stderr');

try {
	$miseAJour = new MiseAJour(new DatabaseManager(), new Fichiers(), new UpdateManager(), function ($message) {
		echo $message."\n";
	});
	$miseAJour->executer();
} catch (\Throwable $erreur) {
	fwrite(STDERR, _("La mise à jour a échoué : ").$erreur->getMessage()."\n");
	exit(1);
}
