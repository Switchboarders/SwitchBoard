#!/usr/bin/env python3
"""Compare le faux voip.ms du banc au vrai service, sur un vrai compte. À lancer seulement sur demande.

Ce script remplace le mode « lancer.sh --reel » du plan. Faire tourner l'application sur un compte partagé
n'est pas sûr : elle efface les renvois dont la description est le nom d'un groupe, et ne protège que ceux
des comités de sa propre BD. Ce script crée donc ses propres objets (préfixe BANCX), sans toucher au routage
d'aucun DID, les efface par leur identifiant, et compare le compte à une photo prise avant.

Prérequis : l'IP de la machine autorisée dans le compte, et un fichier d'identifiants hors du dépôt, en mode
600, avec l'adresse du compte sur la première ligne et le mot de passe API sur la deuxième :
    essai_voipms.py ~/.voipms-essai 5145550000
Le numéro donné ne reçoit aucun appel : il sert seulement de destination au renvoi de test.
"""
import json
import sys
import time
import urllib.parse
import urllib.request

PREFIXE = "BANCX"
# 25 caractères sont refusés (name_toolong) et 15 sont acceptés pour un groupe
NOM = PREFIXE + time.strftime("%d%H%M%S")

echecs = 0


def verifie(obtenu, attendu, description):
    global echecs
    if obtenu == attendu:
        print(f"OK: {description}")
    else:
        print(f"ECHEC: {description} (attendu : {attendu}, obtenu : {obtenu})")
        echecs += 1


def api(methode, **parametres):
    # Toujours un GET : voip.ms n'accepte pas de POST sur rest.php. Une erreur ne doit jamais afficher l'URL,
    # qui contient le mot de passe.
    time.sleep(1)
    requete = urllib.parse.urlencode({"api_username": COMPTE, "api_password": MOT_DE_PASSE, "method": methode, **parametres})
    entree = urllib.request.Request("https://voip.ms/api/v1/rest.php?" + requete, headers={"User-Agent": "SwitchBoard-essai"})
    try:
        with urllib.request.urlopen(entree, timeout=45) as reponse:
            return json.load(reponse)
    except Exception as erreur:
        return {"status": "ERREUR " + type(erreur).__name__ + " " + str(getattr(erreur, "code", ""))}


def lire_tout():
    renvois, groupes, dids = api("getForwardings"), api("getRingGroups"), api("getDIDsInfo")
    if not all(r.get("status") == "success" for r in (renvois, groupes, dids)):
        return None
    return renvois["forwardings"], groupes["ring_groups"], dids["dids"]


def photo():
    """Tout le compte, sans les objets de test. None si une lecture a échoué : ne rien écrire dans ce cas."""
    lu = lire_tout()
    if lu is None:
        return None
    renvois, groupes, dids = lu
    return {
        "renvois": {str(r["forwarding"]): json.dumps(r, sort_keys=True) for r in renvois if not str(r["description"]).startswith(PREFIXE)},
        "groupes": {str(g["ring_group"]): json.dumps(g, sort_keys=True) for g in groupes if not str(g["name"]).startswith(PREFIXE)},
        "dids": {str(d["did"]): json.dumps({c: d.get(c) for c in ("routing", "failover_busy", "failover_unreachable", "failover_noanswer", "voicemail")}, sort_keys=True) for d in dids},
    }


def membres_du_groupe(identifiant, renvoi):
    lu = api("getRingGroups").get("ring_groups", [])
    groupe = next((g for g in lu if str(g["ring_group"]) == identifiant), None)
    return str(groupe["members"]).replace(renvoi, "ID") if groupe else None


def nettoyer():
    """Balaie plusieurs fois : une écriture expirée de notre côté peut s'appliquer plus tard chez voip.ms.
    Seuls les objets au nom de cet essai sont effacés."""
    for passe in range(3):
        for groupe in api("getRingGroups").get("ring_groups", []):
            if str(groupe["name"]).startswith(NOM):
                # ringgroup, pas ring_group : avec ce dernier, delRingGroup répond invalid_ringgroup
                print("nettoyage du groupe", groupe["ring_group"], api("delRingGroup", ringgroup=groupe["ring_group"]).get("status"))
        for renvoi in api("getForwardings").get("forwardings", []):
            if str(renvoi["description"]).startswith(NOM):
                print("nettoyage du renvoi", renvoi["forwarding"], api("delForwarding", forwarding=renvoi["forwarding"]).get("status"))
        if passe < 2:
            time.sleep(20)


def essais(numero):
    def creer_renvoi():
        return api("setForwarding", phone_number=numero, callerid_override=numero, description=NOM)

    def envoyer_groupe(membres, **autres):
        return api("setRingGroup", name=NOM, language="fr", voicemail="0", members=membres, **autres)

    reponse = creer_renvoi()
    verifie(reponse.get("status"), "success", "setForwarding crée un renvoi")
    renvoi = str(reponse.get("forwarding") or "")
    if not renvoi:
        return
    verifie(creer_renvoi().get("status"), "used_number", "setForwarding refuse un numéro déjà présent")
    reponse = api("setForwarding", phone_number=numero, callerid_override=numero, description=NOM, forwarding=renvoi)
    verifie(str(reponse.get("forwarding")), renvoi, "setForwarding renvoie l'identifiant quand il modifie")

    verifie(envoyer_groupe("").get("status"), "missing_members", "setRingGroup refuse un nouveau groupe vide")
    verifie(envoyer_groupe("fwd:999999999,25,1;").get("status"), "invalid_forwarding", "setRingGroup refuse un renvoi inexistant")
    verifie(api("setRingGroup", name=NOM + "-tres-long-pour-voipms", language="fr", voicemail="0", members=f"fwd:{renvoi},25,1;").get("status"), "name_toolong", "setRingGroup refuse un nom trop long")

    reponse = envoyer_groupe(f"fwd:{renvoi},25,1;")
    verifie(reponse.get("status"), "success", "setRingGroup crée un groupe")
    groupe = str(reponse.get("ring_group") or "")
    if not groupe:
        return
    verifie(membres_du_groupe(groupe, renvoi), "fwd:ID,25,1", "les membres se relisent sous la même forme, sans point-virgule final")

    verifie(envoyer_groupe("", ring_group=groupe).get("status"), "missing_members", "setRingGroup refuse de vider un groupe")
    verifie(membres_du_groupe(groupe, renvoi), "fwd:ID,25,1", "le groupe garde ses membres après ce refus")
    verifie(str(envoyer_groupe(f"fwd:{renvoi},30,0;", ring_group=groupe).get("ring_group")), groupe, "setRingGroup renvoie l'identifiant quand il modifie")

    # Le mécanisme du bogue de l'agenda : voip.ms laisse effacer un renvoi qu'un groupe utilise encore
    verifie(api("delForwarding", forwarding=renvoi).get("status"), "success", "delForwarding efface un renvoi utilisé par un groupe")
    verifie(membres_du_groupe(groupe, renvoi), "fwd:ID,30,0", "le groupe pointe encore vers le renvoi effacé")
    verifie(envoyer_groupe(f"fwd:{renvoi},30,0;", ring_group=groupe).get("status"), "invalid_forwarding", "setRingGroup refuse de renvoyer un renvoi effacé")
    verifie(api("delForwarding", forwarding=renvoi).get("status"), "invalid_forwarding", "delForwarding refuse un renvoi déjà effacé")


if len(sys.argv) != 3:
    sys.exit(__doc__)
with open(sys.argv[1], encoding="utf8") as fichier:
    identifiants = fichier.read().split()
if len(identifiants) != 2:
    sys.exit("Le fichier d'identifiants doit contenir l'adresse du compte, puis le mot de passe API.")
COMPTE, MOT_DE_PASSE = identifiants

verifie(api("getLanguages").get("status"), "success", "voip.ms accepte les identifiants")
if echecs:
    sys.exit(1)
avant = photo()
if avant is None:
    sys.exit("ARRET : voip.ms ne répond pas normalement, aucune écriture n'est faite.")
print(f"Photo avant : {len(avant['renvois'])} renvois, {len(avant['groupes'])} groupes, {len(avant['dids'])} DID")

try:
    essais(sys.argv[2])
finally:
    nettoyer()
    apres = photo()
    if apres is None:
        print("ECHEC: photo finale illisible : relancer plus tard et vérifier qu'aucun objet", PREFIXE, "ne reste.")
        echecs += 1
    else:
        # Un groupe réel peut changer pendant l'essai : ses comités ont leurs propres crons. Seul un objet
        # disparu, ou un routage de DID modifié, serait anormal.
        for sorte in ("renvois", "groupes", "dids"):
            disparus = sorted(set(avant[sorte]) - set(apres[sorte]))
            modifies = [c for c in avant[sorte] if c in apres[sorte] and avant[sorte][c] != apres[sorte][c]]
            print(f"{sorte} : disparus={disparus} nouveaux={sorted(set(apres[sorte]) - set(avant[sorte]))} modifiés={modifies}")
        verifie([c for c in avant["dids"] if c in apres["dids"] and avant["dids"][c] != apres["dids"][c]], [], "aucun routage de DID n'a changé")
    lu = lire_tout()
    if lu is not None:
        verifie([r["forwarding"] for r in lu[0] if str(r["description"]).startswith(NOM)] + [g["ring_group"] for g in lu[1] if str(g["name"]).startswith(NOM)], [], "aucun objet de test ne reste")

sys.exit(1 if echecs else 0)
