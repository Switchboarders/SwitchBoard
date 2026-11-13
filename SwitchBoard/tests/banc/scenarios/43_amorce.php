#!/usr/bin/env php
<?php
// L'amorce (src/commun/lesconstantes.php) et la règle des chemins de CONVENTIONS.md : le fuseau horaire, vendor/ et
// l'autoload se règlent une seule fois, dans l'amorce, et aucun chemin n'est calculé depuis l'emplacement d'un fichier.
// Chaque vérification qui lance PHP le fait dans un nouveau processus : celui du scénario a déjà tout chargé.
require_once '/banc/commun/banc.php';

const AMORCE = APPLI.'/src/commun/lesconstantes.php';

// Le code de chaque .php de src/, sans les commentaires, par chemin relatif à src/
function codeDeSrc(): array {
	$codes = [];
	$fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPLI.'/src', FilesystemIterator::SKIP_DOTS));
	foreach ($fichiers as $fichier) {
		if ($fichier->getExtension() === 'php') {
			$codes[substr($fichier->getPathname(), strlen(APPLI.'/src/'))] = php_strip_whitespace($fichier->getPathname());
		}
	}
	ksort($codes);
	return $codes;
}

// Les chemins calculés depuis l'emplacement d'un fichier (dirname(__FILE__), __DIR__) ou relatifs au dossier courant
// (include ou require d'un chemin relatif) trouvés dans ce code, sauf la ligne qui charge l'amorce
function cheminsInterdits(string $code): array {
	$code = preg_replace("#\\brequire(_once)?\\s*\\(?\\s*(dirname\\s*\\(\\s*__FILE__\\s*(,\\s*\\d\\s*)?\\)|__DIR__)\\s*\\.\\s*'/commun/lesconstantes\\.php'#", '', $code);
	preg_match_all('#dirname\s*\(\s*__FILE__|__DIR__|\b(include|require)(_once)?\b\s*\(?\s*["\'](?![/$]|\{\$)#', $code, $trouves);
	return $trouves[0];
}

// Les fichiers de src/ dont le code contient ce motif
function fichiersQuiContiennent(array $codes, string $motif): array {
	return array_keys(array_filter($codes, fn(string $code) => preg_match($motif, $code) === 1));
}

// Lance une commande sous ce compte et retourne ['code', 'sortie'] : la sortie exacte, sans rien couper
function lancer(string $commande, string $compte = 'root'): array {
	if ($compte !== 'root') {
		$commande = 'su -s /bin/sh '.$compte.' -c '.escapeshellarg($commande);
	}
	$processus = proc_open($commande, [1 => ['pipe', 'w']], $tuyaux);
	$sortie = stream_get_contents($tuyaux[1]);
	fclose($tuyaux[1]);
	return ['code' => proc_close($processus), 'sortie' => $sortie];
}

// --- La règle des chemins ---
verifie(cheminsInterdits("<?php include 'pages/utilisateur/page.php';") !== [] && cheminsInterdits('<?php require_once dirname(__FILE__)."/x.php";') !== []
	&& cheminsInterdits('<?php $dossier = __DIR__;') !== [] && cheminsInterdits("<?php require_once 'updatemanager.php';") !== [],
	"la règle des chemins attrape un include relatif, dirname(__FILE__) et __DIR__");
verifie(cheminsInterdits("<?php require_once dirname(__FILE__,2).'/commun/lesconstantes.php'; require_once COMMUN_DIRECTORY.'x.php'; include \"\$racine/src/x.php\";") === [],
	"la règle des chemins laisse passer la ligne de l'amorce et les chemins faits de constantes");
$codes = codeDeSrc();
verifie(count($codes) > 50, "les .php de src/ sont lus (".count($codes).")");
$fautes = [];
foreach ($codes as $fichier => $code) {
	if ($fichier === 'utils/jobRestore.php') {
		continue; // tourne sans l'amorce, et aussi depuis un dossier de sauvegarde
	}
	if ($fichier === 'commun/lesconstantes.php') {
		$code = preg_replace("#define\\('BASE_DIRECTORY',\\s*dirname\\(__FILE__,\\s*3\\)\\.'/'\\)#", '', $code);
	}
	if ($fichier === 'miseajour.php') {
		// Ses vérifications d'avant le premier require tournent sans l'amorce
		$code = preg_match('/\brequire(_once)?\b/', $code, $premier, PREG_OFFSET_CAPTURE) === 1 ? substr($code, $premier[0][1]) : $code;
	}
	foreach (cheminsInterdits($code) as $trouve) {
		$fautes[] = "$fichier : $trouve";
	}
}
verifie($fautes === [], "aucun chemin calculé depuis l'emplacement d'un fichier ni relatif au dossier courant dans src/ (".implode(' ; ', $fautes).")");

// --- Fuseau horaire, vendor/ et autoload : seulement dans l'amorce ---
$reglageFuseau = fichiersQuiContiennent($codes, '/\bdate_default_timezone_set\s*\(/');
verifie($reglageFuseau === ['commun/lesconstantes.php', 'utils/jobRestore.php'], "le fuseau horaire se règle dans l'amorce seulement, et dans jobRestore.php qui s'en passe (".implode(', ', $reglageFuseau).")");
$chargeVendor = fichiersQuiContiennent($codes, '/\b(include|require)(_once)?\b[^;]*autoload\.php/');
verifie($chargeVendor === ['commun/lesconstantes.php'], "vendor/autoload.php se charge dans l'amorce seulement (".implode(', ', $chargeVendor).")");
$autoload = fichiersQuiContiennent($codes, '/\bspl_autoload_register\s*\(/');
verifie($autoload === ['commun/lesconstantes.php'], "l'autoload est déclaré dans l'amorce seulement (".implode(', ', $autoload).")");
$chargeModules = fichiersQuiContiennent($codes, '#\b(include|require)(_once)?\b[^;]*modules/#');
verifie($chargeModules === [], "aucune classe de modules/ n'est chargée à la main : l'autoload s'en charge (".implode(', ', $chargeModules).")");

$fuseau = lancer('php -d date.timezone=UTC -r '.escapeshellarg('require "'.AMORCE.'"; echo date_default_timezone_get();'));
verifie($fuseau['sortie'] === FUSEAU_HORAIRE && FUSEAU_HORAIRE === 'America/New_York', "avec date.timezone=UTC, l'amorce met PHP à l'heure du Québec (".$fuseau['sortie'].")");

$classes = lancer('php -r '.escapeshellarg('require "'.AMORCE.'"; echo class_exists("MiseAJour") ? "trouvee" : "absente", ",", class_exists("ClasseInexistanteDuBanc") ? "trouvee" : "absente", ",", class_exists("ICal\\ICal") ? "trouvee" : "absente";').' 2>&1');
verifie($classes['sortie'] === 'trouvee,absente,trouvee', "après l'amorce seule, l'autoload trouve MiseAJour, ignore une classe inconnue sans erreur, et vendor/ est chargé (".$classes['sortie'].")");

// --- Un cron qui ne charge pas TraitementComite.php écrit l'heure du Québec, même avec date.timezone=UTC ---
// mailcanary.php met l'heure dans son courriel de test : sa vraie ligne de cron, avec seulement le réglage de PHP en plus.
viderEtatFaux();
$ligneCanari = current(array_filter(lignesCron(), fn(string $ligne) => str_contains($ligne, 'mailcanary.php')));
$ligneEnUtc = str_replace('/usr/local/bin/php ', '/usr/local/bin/php -d date.timezone=UTC ', $ligneCanari);
verifie($ligneEnUtc !== $ligneCanari, "la ligne de cron de mailcanary.php reçoit date.timezone=UTC");
exec($ligneEnUtc, $sortieCanari, $codeCanari);
$heureEcrite = preg_match('/Généré le : (\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)/', implode('', courriels()), $trouve) === 1 ? $trouve[1] : '';
$moment = DateTime::createFromFormat('Y-m-d H:i:s', $heureEcrite, new DateTimeZone('America/New_York'));
verifie($codeCanari === 0 && $moment !== false && abs($moment->getTimestamp() - time()) < 300,
	"mailcanary.php, avec date.timezone=UTC, écrit l'heure du Québec dans son courriel ($heureEcrite, il est ".(new DateTime('now', new DateTimeZone('America/New_York')))->format('H:i:s')." au Québec)");

// --- L'amorce seule suffit à OAuth, et n'écrit rien sur la sortie ---
$deplacees = ['cheminCertificatSbchatGere', 'sbchatCaBundle', 'problemeCaBundle', 'tlsNonVerifieAutorise', 'problemeCertificatPem',
	'recupererCertificatServeur', 'analyserCertificatPem', 'enregistrerCertificatSbchatGere', 'supprimerCertificatSbchatGere',
	'cheminReglagesOAuthCourriel', 'reglagesOAuthForceesParEnv', 'reglagesOAuthCourriel', 'enregistrerReglagesOAuthCourriel',
	'supprimerReglagesOAuthCourriel', 'cheminJetonOAuthCourriel', 'enregistrerJetonOAuthCourriel', 'lireJetonOAuthCourriel',
	'supprimerJetonOAuthCourriel', 'requeteOAuthPost', 'demarrerAutorisationOAuthMicrosoft365', 'verifierAutorisationOAuthMicrosoft365',
	'rafraichirJetonOAuthMicrosoft365', 'construireUrlAutorisationGoogle', 'echangerCodeOAuthGoogle', 'rafraichirJetonOAuthGoogle',
	'cheminReglagesDeadManSwitch', 'reglageDeadManSwitchForceParEnv', 'reglagesDeadManSwitch', 'enregistrerReglagesDeadManSwitch',
	'supprimerReglagesDeadManSwitch'];
$manquantes = lancer('php -r '.escapeshellarg('require "'.AMORCE.'"; foreach ('.var_export($deplacees, true).' as $f) { if (!function_exists($f)) { echo "$f "; } }').' 2>&1');
verifie($manquantes['sortie'] === '', "après l'amorce seule, les ".count($deplacees)." fonctions des certificats, d'OAuth et de la surveillance existent (manquantes : ".$manquantes['sortie'].")");
$seule = lancer('php -r '.escapeshellarg('require "'.AMORCE.'";').' 2>&1');
verifie($seule['code'] === 0 && $seule['sortie'] === '', "l'amorce seule n'écrit rien, même sur la sortie d'erreur (".$seule['sortie'].")");

// Le php -r de Docker/generate_mail_config.sh, recopié tel quel, sur des réglages faits depuis l'assistant de la console
$reglagesOAuth = CONFIG_DIRECTORY.'mail-oauth-settings.json';
file_put_contents($reglagesOAuth, json_encode(['fournisseur' => 'google', 'mailbox_user' => 'oauth@banc.test', 'client_id' => 'client-banc', 'client_secret' => '', 'tenant' => '']));
$commandeDocker = <<<'COMMANDE'
php -r '
        require "/var/www/html/SwitchBoard/SwitchBoard/src/commun/lesconstantes.php";
        $r = reglagesOAuthCourriel();
        echo $r["fournisseur"]."\n".$r["mailbox_user"]."\n".$r["client_id"];
    ' 2>/dev/null
COMMANDE;
$docker = lancer($commandeDocker);
verifie($docker['sortie'] === "google\noauth@banc.test\nclient-banc", "le php -r de generate_mail_config.sh lit les réglages OAuth, sans sortie parasite (".json_encode($docker['sortie']).")");
// msmtp prend la sortie de mailoauthtoken.php pour le mot de passe : sans jeton, rien n'y sort
$jeton = lancer('php '.APPLI.'/src/mailoauthtoken.php 2>/dev/null', 'www-data');
verifie($jeton['code'] !== 0 && $jeton['sortie'] === '', "mailoauthtoken.php sans jeton : il échoue sans rien écrire sur la sortie standard (".json_encode($jeton['sortie']).")");
unlink($reglagesOAuth);

finDuScenario();
