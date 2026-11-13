# Contribuer à SwitchBoard / Contributing to SwitchBoard

*English below.*

## Français

SwitchBoard sert à de petites équipes de bénévoles : il met à jour la ligne téléphonique de garde
d'un comité à partir de son agenda. **Cette ligne ne doit jamais tomber.** Chaque contribution est
relue dans cet esprit.

### Avant de commencer

- Un bogue ou une idée : ouvrez une issue. N'y mettez **jamais** de numéro de téléphone réel, de nom
  de répondant, d'adresse d'agenda, de journal non masqué ni de mot de passe.
- Une faille de sécurité : pas d'issue publique, voir [SECURITY.md](SECURITY.md).
- Les règles d'écriture du code sont dans [CONVENTIONS.md](CONVENTIONS.md), la carte du code dans
  [ARCHITECTURE.md](ARCHITECTURE.md), le vocabulaire dans [LEXIQUE.md](LEXIQUE.md).

### Proposer une modification

1. Une branche partie de `main`, un sujet par branche.
2. Le banc d'essai doit être vert : `SwitchBoard/tests/banc/lancer.sh` (Docker requis, environ 7
   minutes). Il ne touche aucun vrai service. Ajoutez `--mariadb 10.11` si la modification contient
   du SQL.
3. Un bogue corrigé reçoit un test qui l'aurait attrapé.
4. Ne changez pas le numéro de version : il est donné par un mainteneur à la fusion.
5. Ouvrez une demande de fusion (pull request) vers `main` en disant ce qui change pour les comités.

### Licence des contributions

SwitchBoard est distribué sous la [PolyForm Noncommercial License 1.0.0](LICENSE). En proposant une
contribution, vous confirmez que vous avez le droit de la donner, et vous acceptez :

- qu'elle soit distribuée sous la licence du projet ;
- que les mainteneurs du projet (Switchboarders) puissent aussi la distribuer sous d'autres
  conditions, notamment pour accorder une permission d'usage commercial, comme le prévoit la licence.

Si vous n'acceptez pas ces conditions, ne proposez pas la contribution.

## English

SwitchBoard serves small teams of volunteers: it updates a committee's on-call phone line from its
calendar. **That line must never go down.** Every contribution is reviewed with that in mind.

### Before you start

- A bug or an idea: open an issue. **Never** include a real phone number, a responder's name, a
  calendar address, an unmasked log or a password.
- A security issue: no public issue, see [SECURITY.md](SECURITY.md).
- Code rules are in [CONVENTIONS.md](CONVENTIONS.md), the code map in
  [ARCHITECTURE.md](ARCHITECTURE.md), the vocabulary in [LEXIQUE.md](LEXIQUE.md) (in French).

### Proposing a change

1. A branch from `main`, one topic per branch.
2. The test bench must pass: `SwitchBoard/tests/banc/lancer.sh` (needs Docker, about 7 minutes). It
   never touches a real service. Add `--mariadb 10.11` if the change contains SQL.
3. A fixed bug gets a test that would have caught it.
4. Don't change the version number: a maintainer sets it when merging.
5. Open a pull request against `main`, saying what changes for the committees.

### License of contributions

SwitchBoard is distributed under the [PolyForm Noncommercial License 1.0.0](LICENSE). By proposing a
contribution, you confirm that you have the right to give it, and you agree:

- that it is distributed under the project's license;
- that the project maintainers (Switchboarders) may also distribute it under other terms, in
  particular to grant permission for commercial use, as the license provides.

If you don't agree with these terms, please don't propose the contribution.
