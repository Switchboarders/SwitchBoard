# Politique de sécurité / Security Policy

## Signaler une vulnérabilité / Reporting a vulnerability

**Français :** Si vous découvrez une vulnérabilité de sécurité dans SwitchBoard, merci de
**ne pas** ouvrir une issue publique GitHub. Signalez-la de façon privée, par l'onglet
*Security* du dépôt → *Report a vulnerability* (signalement privé de GitHub), avec :
- une description de la vulnérabilité et de son impact potentiel ;
- les étapes pour la reproduire ;
- la version/commit de SwitchBoard concernée.

Nous visons à accuser réception dans un délai raisonnable et à corriger les problèmes
critiques rapidement. Merci de nous laisser le temps de publier un correctif avant toute
divulgation publique.

**English:** If you discover a security vulnerability in SwitchBoard, please **do not**
open a public GitHub issue. Report it privately through the repository's *Security* tab →
*Report a vulnerability* (GitHub private vulnerability reporting), with:
- a description of the vulnerability and its potential impact;
- steps to reproduce it;
- the affected SwitchBoard version/commit.

We aim to acknowledge reports within a reasonable timeframe and to fix critical issues
promptly. Please give us time to ship a fix before any public disclosure.

## Portée / Scope

SwitchBoard est une application auto-hébergée qui stocke des identifiants d'API
tiers (voip.ms, SwitchBoard Chat, MAS). Les catégories de rapports les plus utiles :
- exécution de code à distance (RCE), notamment via le mécanisme de mise à jour
  automatique ;
- contournement d'authentification ou d'autorisation ;
- fuite des secrets stockés (mots de passe API, jetons) ;
- injection SQL, XSS, CSRF.

SwitchBoard is a self-hosted application that stores third-party API credentials
(voip.ms, SwitchBoard Chat, MAS). The most useful report categories:
- remote code execution (RCE), especially via the automatic update mechanism;
- authentication or authorization bypass;
- leakage of stored secrets (API passwords, tokens);
- SQL injection, XSS, CSRF.
