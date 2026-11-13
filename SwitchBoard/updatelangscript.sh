#!/bin/bash

# Générer le fichier POT Recursivement
find . -iname "*.php" | xargs xgettext --from-code=UTF-8 -k_e -k_x -k__ -o languages/default.pot
# Use for first init // Premiere fois seulement'
#msginit --locale fr_CA --input languages/default.pot --output languages/locale/fr_CA/LC_MESSAGES/switchboard.po
#msginit --locale en_US --input languages/default.pot --output languages/locale/en_US/LC_MESSAGES/switchboard.po
# Update po files
msgmerge --update languages/locale/fr_CA/LC_MESSAGES/switchboard.po languages/default.pot
msgmerge --update languages/locale/en_US/LC_MESSAGES/switchboard.po languages/default.pot

# po to mo (after translation of the po file with poedit)
msgfmt languages/locale/fr_CA/LC_MESSAGES/switchboard.po --output-file=languages/locale/fr_CA/LC_MESSAGES/switchboard.mo
msgfmt languages/locale/en_US/LC_MESSAGES/switchboard.po --output-file=languages/locale/en_US/LC_MESSAGES/switchboard.mo
## Containers need a restart !!

#test
#xgettext -d switchboard --from-code=UTF-8 -o /ringgroupupdater/locale/switchboard.pot *.php

# Mettre à jour ou créer les fichiers PO pour chaque langue
#for lang in fr_FR en_US # Ajoutez d'autres codes de langue selon vos besoins
#do
#    if [ -f locale/$lang.po ]; then
#        msgmerge -U ringgroupupdater/locale/$lang.po ringgroupupdater/locale/switchboard.pot
#    else
#        msginit -i ringgroupupdater/locale/switchboard.pot -o ringgroupupdater/locale/$lang.po -l $lang
#    fi
#done