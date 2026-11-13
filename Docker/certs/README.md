# certs/

Ce dossier est monté en lecture seule dans le conteneur php, sous `/etc/ssl/sbchat/`.

Il sert uniquement au cas où votre serveur SwitchBoard Chat / MAS utilise un certificat
**auto-signé** : déposez ici le certificat à approuver, puis pointez `SB_SBCHAT_CA_BUNDLE`
(dans `.env`) vers son chemin **dans le conteneur**, pas sur l'hôte.

Exemple, depuis ce dossier (`Docker/`) :

    echo | openssl s_client -connect sbchat.mondomaine.ca:3443 2>/dev/null \
      | openssl x509 > certs/sbchat-cert.pem

puis dans `.env` :

    SB_SBCHAT_CA_BUNDLE=/etc/ssl/sbchat/sbchat-cert.pem

La vérification TLS reste ACTIVE : votre certificat est simplement reconnu comme valide.

Laissez ce dossier vide si votre serveur SBChat utilise un certificat normal (Let's
Encrypt, etc.) : aucune configuration n'est alors nécessaire.

---

This folder is mounted read-only into the php container at `/etc/ssl/sbchat/`.

It is only needed if your SwitchBoard Chat / MAS server uses a **self-signed**
certificate: drop the certificate to trust in here, then point `SB_SBCHAT_CA_BUNDLE`
(in `.env`) at its path **inside the container**, not on the host.
