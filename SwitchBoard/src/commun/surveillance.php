<?php
// Chargé par l'amorce (lesconstantes.php), comme oauthcourriel.php : ces fonctions sont disponibles partout.

// --- Surveillance dead-man's-switch (?page=deadmanswitch) ------------------------------
//
// Chaque "check" ($check ex.: "ringgroup", "sbchat", "mail", "infra") a une URL de ping de
// succès et, optionnellement, une URL de ping d'échec dédiée (voir SignalDeVie).
// Volontairement PAS chiffré via Chiffrement comme le secret OAuth : ces URLs sont
// sensibles (quiconque les connaît peut simuler un "succès") mais leur compromission
// n'expose qu'un faux signal de santé, pas un compte courriel/API - masquées dans
// l'assistant web comme le secret OAuth, par prudence, mais stockées en clair comme
// CRON_WEB_KEY.

if (!function_exists('cheminReglagesDeadManSwitch')):
	function cheminReglagesDeadManSwitch() {
		return CONFIG_DIRECTORY.'deadmanswitch-settings.json';
	}
endif;

// Vrai si SB_DMS_<CHECK>_SUCCESS_URL est imposé par l'environnement (Docker .env) - même
// principe que reglagesOAuthForceesParEnv() : le formulaire d'édition de l'assistant web est
// alors désactivé pour CE check précis.
if (!function_exists('reglageDeadManSwitchForceParEnv')):
	function reglageDeadManSwitchForceParEnv($check) {
		$valeur = getenv('SB_DMS_'.strtoupper($check).'_SUCCESS_URL');
		return is_string($valeur) && $valeur !== '';
	}
endif;

// Lit les URLs de ping d'un check : variable d'environnement en priorité, puis le fichier
// géré par l'assistant web (?page=deadmanswitch), puis config/config.php (même convention
// à trois niveaux que reglagesOAuthCourriel(), dans oauthcourriel.php).
if (!function_exists('reglagesDeadManSwitch')):
	function reglagesDeadManSwitch($check) {
		$prefixe = 'SB_DMS_'.strtoupper($check);
		$successUrl = getenv($prefixe.'_SUCCESS_URL');
		$failUrl = getenv($prefixe.'_FAIL_URL');
		if (!is_string($successUrl) || $successUrl === '') {
			$fichier = cheminReglagesDeadManSwitch();
			if (is_readable($fichier)) {
				$donnees = json_decode((string)file_get_contents($fichier), true);
				if (is_array($donnees) && isset($donnees[$check])) {
					$successUrl = $donnees[$check]['success_url'] ?? '';
					$failUrl = $donnees[$check]['fail_url'] ?? '';
				}
			}
		}
		if (!is_string($successUrl) || $successUrl === '') {
			$fichierConfig = CONFIG_DIRECTORY.'config.php';
			if (is_readable($fichierConfig)) {
				include $fichierConfig;
				$tousLesChecks = $deadmanswitchsettings ?? [];
				if (isset($tousLesChecks[$check])) {
					$successUrl = $tousLesChecks[$check]['success_url'] ?? '';
					$failUrl = $tousLesChecks[$check]['fail_url'] ?? '';
				}
			}
		}
		return [
			'success_url' => is_string($successUrl) ? $successUrl : '',
			'fail_url' => is_string($failUrl) ? $failUrl : '',
		];
	}
endif;

// Écrit les URLs choisies dans l'assistant web (?page=deadmanswitch) pour UN check précis,
// sans toucher aux autres. Sans effet si reglageDeadManSwitchForceParEnv($check) est vrai.
if (!function_exists('enregistrerReglagesDeadManSwitch')):
	function enregistrerReglagesDeadManSwitch($check, $successUrl, $failUrl) {
		if (reglageDeadManSwitchForceParEnv($check)) {
			return false;
		}
		$chemin = cheminReglagesDeadManSwitch();
		$donnees = [];
		if (is_readable($chemin)) {
			$existant = json_decode((string)file_get_contents($chemin), true);
			if (is_array($existant)) {
				$donnees = $existant;
			}
		}
		$donnees[$check] = ['success_url' => $successUrl, 'fail_url' => $failUrl];
		if (@file_put_contents($chemin, json_encode($donnees, JSON_PRETTY_PRINT)) === false) {
			return false;
		}
		@chmod($chemin, 0600);
		return true;
	}
endif;

// Retire la configuration d'UN check précis depuis l'assistant web. Sans effet si
// reglageDeadManSwitchForceParEnv($check) est vrai.
if (!function_exists('supprimerReglagesDeadManSwitch')):
	function supprimerReglagesDeadManSwitch($check) {
		if (reglageDeadManSwitchForceParEnv($check)) {
			return false;
		}
		$chemin = cheminReglagesDeadManSwitch();
		if (!is_readable($chemin)) {
			return true;
		}
		$donnees = json_decode((string)file_get_contents($chemin), true);
		if (!is_array($donnees)) {
			$donnees = [];
		}
		unset($donnees[$check]);
		return @file_put_contents($chemin, json_encode($donnees, JSON_PRETTY_PRINT)) !== false;
	}
endif;

// Après la boucle d'un cron : les courriels à l'admin que le passage a laissés (voir BilanPassage), dans la langue de
// l'admin. Le cron a déjà traité ses comités : rien ici ne doit l'arrêter, sinon le reste de sa fin n'aurait pas lieu.
if (!function_exists('envoyerAlertesDuPassage')):
	function envoyerAlertesDuPassage(BilanPassage $bilan, Langue $languagemanager): void {
		try {
			$bd = new DatabaseManager();
			$languagemanager->setLanguage($bd->getAdminId());
			$envoyes = $bilan->envoyerCourriels(new MemoireAlertes($bd), new Courriel((string)$bd->getAdminMail()));
			if ($envoyes > 0) {
				echo "Courriels d'alerte envoyés à l'admin : $envoyes\n";
			}
		} catch (\Throwable $erreur) {
			echo "Alertes du passage non envoyées : ".$erreur->getMessage()."\n";
		}
	}
endif;
