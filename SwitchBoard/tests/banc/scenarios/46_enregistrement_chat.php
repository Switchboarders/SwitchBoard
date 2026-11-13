#!/usr/bin/env php
<?php
// La suite de #77 pour SwitchBoard Chat : l'envoi de l'entente de confidentialité, la modification d'une macro et sa
// suppression répondent par une redirection vers la page en GET. F5 ou le bouton Précédent ne renvoient plus l'entente
// aux bénévoles ni ne refont la suppression. Ce que SwitchBoard Chat a répondu s'affiche une seule fois. Sans jeton CSRF :
// refusé, et SwitchBoard Chat ne reçoit rien.
require_once '/banc/commun/banc.php';

repartirAZero();
connecterAuPortail('chat');

// Les commandes que le faux SwitchBoard Chat a reçues, par nom
$commandesChat = fn(string $nom) => array_values(array_filter(
	array_map(fn($appel) => json_decode($appel['corps'], true), journalFaux('sbchat.banc.test')),
	fn($commande) => ($commande['command'] ?? '') === $nom));

// --- Entente de confidentialité ---
$adresse = PORTAIL.'?page=sbchatagreement';
verifie(str_contains(requetePortail($adresse)['page'], 'sur 1 ont signé'), "entente : la page montre les signatures");
viderJournalFaux();
$sansJeton = requetePortail($adresse, ['send_agreement' => '1']);
verifie($sansJeton['code'] === 403 && $commandesChat('send_agreement') === [], "entente, sans jeton : refusé, rien n'est envoyé (code {$sansJeton['code']})");
$reponse = requetePortail($adresse, ['send_agreement' => '1', 'csrf_token' => jetonDuFormulaire($adresse)]);
verifie(redirigeVers($reponse, 'managementportal.php?page=sbchatagreement'), "entente : redirection vers la même page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
verifie(count($commandesChat('send_agreement')) === 1, "entente : SwitchBoard Chat reçoit l'envoi");
verifie(lectures($adresse, "Entente envoyée à 3 bénévole(s)") === [1, 0], "entente : le résultat est dit une fois, pas au rechargement");
verifie(count($commandesChat('send_agreement')) === 1, "entente : recharger la page n'envoie pas l'entente une deuxième fois");

// --- Macros : modifier ---
$adresse = PORTAIL.'?page=sbchatmacros';
$adresseMacro = $adresse.'&id_macro=1';
viderJournalFaux();
$champs = ['id_macro' => '1', 'macro_name' => 'accueil2', 'macro_text' => 'Bonjour à tous', 'macro_tags' => ''];
$sansJeton = requetePortail($adresseMacro, $champs);
verifie($sansJeton['code'] === 403 && $commandesChat('update_macro') === [], "macro modifiée, sans jeton : refusé, rien n'est envoyé (code {$sansJeton['code']})");
$reponse = requetePortail($adresseMacro, $champs + ['csrf_token' => jetonDuFormulaire($adresseMacro)]);
verifie(redirigeVers($reponse, 'managementportal.php?page=sbchatmacros'), "macro modifiée : redirection vers la liste des macros (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
$envoyees = $commandesChat('update_macro');
verifie(count($envoyees) === 1 && ($envoyees[0]['macro_info']['macro_name'] ?? '') === 'accueil2' && ($envoyees[0]['macro_info']['macro_text'] ?? '') === 'Bonjour à tous', "macro modifiée : SwitchBoard Chat reçoit le nom et le texte");
verifie(lectures($adresse, 'Macro enregistrée (banc)') === [1, 0], "macro modifiée : la réponse est dite une fois, pas au rechargement");
verifie(count($commandesChat('update_macro')) === 1, "macro modifiée : recharger la page n'envoie rien de plus");

// --- Macros : supprimer (un bouton POST, avec jeton ; jamais un lien) ---
viderJournalFaux();
$page = requetePortail($adresse)['page'];
$aLeBouton = preg_match("/<form class='form-action[^']*' method='post' action='(managementportal.php\?page=sbchatmacros&amp;iduser=\d+)'[^>]*>(?:(?!<\/form>).)*name='id_macro' value='1'(?:(?!<\/form>).)*name='delete' value='true'/s", $page, $bouton) === 1;
verifie($aLeBouton && !str_contains($page, 'delete=true'), "macro supprimée : la liste offre un bouton POST, et plus aucun lien qui supprime");
$actionDuBouton = html_entity_decode($bouton[1] ?? '');
$suppression = dirname(PORTAIL).'/'.$actionDuBouton;
$champs = ['id_macro' => '1', 'delete' => 'true'];
$sansJeton = requetePortail($suppression, $champs);
verifie($sansJeton['code'] === 403 && $commandesChat('update_macro') === [], "macro supprimée, sans jeton : refusé, rien n'est envoyé (code {$sansJeton['code']})");
requetePortail($suppression.'&id_macro=1&delete=true&csrftoken='.urlencode(jetonCsrfDeLaSession()));
verifie($commandesChat('update_macro') === [], "macro supprimée : un lien GET (même avec le jeton) ne supprime rien");
$reponse = requetePortail($suppression, $champs + ['csrf_token' => jetonDuFormulaire($adresse)]);
verifie(redirigeVers($reponse, $actionDuBouton), "macro supprimée : redirection vers la liste des macros (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
$envoyees = $commandesChat('update_macro');
verifie(count($envoyees) === 1 && ($envoyees[0]['macro_info']['macro_name'] ?? 'x') === '' && ($envoyees[0]['macro_info']['id_macro'] ?? '') === '1', "macro supprimée : SwitchBoard Chat reçoit la macro vidée");
verifie(lectures($adresse, 'Macro enregistrée (banc)') === [1, 0], "macro supprimée : la réponse est dite une fois");
verifie(count($commandesChat('update_macro')) === 1, "macro supprimée : recharger la page ne supprime pas de nouveau");

// --- Macros : liste vide, vraie panne, valeurs échappées ---
// Un centre d'appel sans macro n'est pas une erreur : la page le dit et garde « Nouveau macro »
file_put_contents(ETAT_BANC.'/macros-mode', 'vide');
$page = requetePortail($adresse)['page'];
verifie(str_contains($page, 'Aucune macro pour ce centre') && str_contains($page, 'Nouveau macro'), "macros, liste vide : la page dit « Aucune macro » et offre « Nouveau macro »");
verifie(!str_contains($page, 'Erreur de configuration ou de connexion') && !str_contains($page, 'No macros'), "macros, liste vide : ni « Erreur de configuration », ni « No macros »");
$nouvelle = requetePortail($adresse.'&id_macro=-1')['page'];
verifie(str_contains($nouvelle, 'name="macro_name"'), "macros, liste vide : le formulaire de nouvelle macro s'affiche");
// Une vraie panne (jeton refusé par SwitchBoard Chat) garde le message d'erreur
file_put_contents(ETAT_BANC.'/macros-mode', 'panne');
$page = requetePortail($adresse)['page'];
verifie(str_contains($page, 'Erreur de configuration ou de connexion') && !str_contains($page, 'Aucune macro pour ce centre'), "macros, panne : l'erreur est dite, pas « Aucune macro »");
verifie(!preg_match('/Warning:|Fatal error|Uncaught|Deprecated:/', $page), "macros, panne : aucune erreur PHP à l'écran");
// Le nom et le texte d'une macro ne sont jamais du HTML
file_put_contents(ETAT_BANC.'/macros-mode', 'pieges');
$page = requetePortail($adresse)['page'];
verifie(!str_contains($page, '<script>alert(1)</script>') && str_contains($page, '&lt;script&gt;alert(1)&lt;/script&gt;'), "macros : le texte d'une macro est échappé");
verifie(!str_contains($page, '<b>gras</b>') && str_contains($page, '&lt;b&gt;gras&lt;/b&gt;'), "macros : le nom d'une macro est échappé");
@unlink(ETAT_BANC.'/macros-mode');

finDuScenario();
