Verifier version du code et de la BD et maj ceux-ci si pas à jour

(code):
Acceder au Github du projet et obtenir la version Github du code (<github>/SwitchBoard/SwitchBoard/json/codeVersion.json)
Obtenir la version serveur du code (<dossier d'installation SB>/json/codeVersion.json)
Comparer la version du code obtenue du Github avec la version serveur du code 
Si la version-code-github > la version-code-serveur
Afficher message "mise à jour disponible" 
 (dans fichier managementportal.php fonction displaymanagementportal)

Télécharger tout le code de Github dans le dossier <dossier d'installation SB>/
(BD):
Obtenir le fichier de versions de BD de Github (<dossier d'installation SB>/json/dbVersion.json puisque
    ce fichier vient juste d'être téléchargé)
Obtenir la version serveur de la BD (élément paramvalue de la table parameters 
    dont le paramname = 'db version')


Exécuter tous les scripts à partir de la version-BD-serveur-local + 1 jusqu'à la dernière 
version-dbVersion-Github incluant celle-ci