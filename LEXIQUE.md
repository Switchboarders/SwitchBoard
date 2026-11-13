# Lexique de SwitchBoard

Les mots à employer pour parler de SwitchBoard, dans l'interface, le code, les issues et la documentation. Le but :
que tout le monde désigne la même chose par le même mot.

## Les personnes et les organisations

**Comité provincial de l'appel à l'aide**
: Nom officiel du comité dont les techs administrent SwitchBoard. Ce sont les seuls utilisateurs du portail : les
  comités locaux ne voient que les courriels et les SMS qu'ils reçoivent.

**Comité local**
: Un comité qui répond aux appels d'une ligne. Il a son numéro (DID), son agenda de garde et son groupe de sonnerie.
  Un comité local est **soit autonome, soit membre**, jamais les deux (voir ci-dessous).

**Comité local autonome**
: Comité local seul sur son propre compte voip.ms, qu'il paie lui-même. Il reçoit tous les coûts de son compte et
  le solde.

**Comité local membre**
: Comité local dont le compte voip.ms est partagé avec au moins une autre entrée (un compte principal ou un autre
  comité local). Il reçoit **ses propres coûts** (son DID, sa part des frais partagés, son total), mais **jamais le
  solde** du compte ni les coûts des autres.

**Compte principal**
: L'entrée qui paie le compte voip.ms d'un regroupement de comités locaux. Il n'a ni agenda ni DID. Il reçoit le
  rapport de coûts complet : tous les DID du compte et le solde. Il sert aussi bien pour une région que pour une
  organisation.

**Organisation**
: Entité qui n'est pas une région AA, mais qui regroupe plusieurs comités locaux ou plusieurs DID sous un même compte
  voip.ms.

**User (entrée)**
: Toute ligne de la base de données qui représente un compte du portail : un comité local, un compte principal ou un
  compte admin.

## La règle membre / autonome

Un comité local est **membre** dès qu'une autre entrée (compte principal ou comité local) utilise le même compte
voip.ms (même nom d'utilisateur voip.ms). Sinon, il est **autonome**.

Exemple : une région crée un compte principal « Region00 » pour payer son compte voip.ms. Le comité local
« Region00 local », qui utilise ce même compte, devient membre. Tout nouveau DID de cette région s'ajoute comme un
nouveau comité local membre.

Cette règle ne voit que les entrées du serveur où elle s'exécute.

## La téléphonie

**DID**
: Numéro de téléphone public d'un comité local, loué chez voip.ms.

**Groupe de sonnerie**
: Les numéros des répondants de garde (au plus 4) qui sonnent en même temps quand quelqu'un appelle le DID.
  SwitchBoard le met à jour selon l'agenda de garde.

**Répondant**
: Personne de garde inscrite à l'agenda, dont le numéro est placé dans le groupe de sonnerie.

**Frais partagés**
: Frais du compte voip.ms qui ne relèvent d'aucun DID en particulier et que le rapport de coûts répartit entre les DID.
  Le calcul est dans `modules/rapports/RapportCoutsDid.php` ; il n'est pas encore décrit ici.

## La surveillance

**Passage**
: Une exécution d'un cron, qui traite chaque comité abonné une fois. Le cron de sonnerie fait un passage aux 10 minutes.

**Comité en échec**
: Comité dont le groupe de sonnerie n'a pas pu être mis à jour à ce passage : voip.ms a refusé le groupe ou n'a pas
  répondu, ou le traitement a levé une exception. Personne de garde, aucun changement et agenda illisible ne sont pas des
  échecs.

**Signal de vie**
: Le ping qu'un cron envoie à un service externe à la fin d'un passage (dead-man's-switch). Voir `DEADMANSWITCH-SETUP.md`.

## Mots à éviter

| À éviter | Pourquoi | À dire plutôt |
|---|---|---|
| maître / esclave | connotation inutile | compte principal / comité local membre |
| régional, compte régional | un compte principal peut servir une organisation qui n'est pas une région | compte principal |
| payeur | trop vague | compte principal |
| rattaché | flou | membre |

## Correspondance dans le code

| Terme | Dans le code |
|---|---|
| Compte principal | case « Compte principal » (page admin des services), colonne `userservice.mainaccount`, constante `DatabaseManager::COMPTE_PRINCIPAL` |
| Comité local membre | `DatabaseManager::COMITE_MEMBRE` |
| Comité local autonome | `DatabaseManager::COMITE_AUTONOME` |
| La règle membre / autonome | `DatabaseManager::typeDeCompte()` (testée dans `tests/rapportcomite_test.php`), appelée par `getTypeCompte()` |
