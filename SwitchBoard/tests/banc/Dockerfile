# Image du banc d'essai. Elle part de l'image réelle du serveur principal (Docker/DockerfilePHP,
# construite par lancer.sh sous le nom sb-banc-base) et y ajoute seulement ce que le serveur
# principal fait tourner ailleurs ou que le banc exige : nginx dans le même conteneur, une autorité
# de certificat de test et le code à tester.
# Contexte de construction : la racine du dépôt (voir lancer.sh).
FROM sb-banc-base

RUN apt-get update && apt-get install -y --no-install-recommends nginx && rm -rf /var/lib/apt/lists/*

# Le vrai nginx.conf.sample : seule change l'adresse de php-fpm, qui est ici dans le même conteneur.
COPY Docker/nginx/nginx.conf.sample /etc/nginx/conf.d/default.conf
RUN sed -i 's/sb_php-fpm:9000/127.0.0.1:9000/' /etc/nginx/conf.d/default.conf \
	&& sed -i '/server_tokens/d' /etc/nginx/nginx.conf \
	&& rm -f /etc/nginx/sites-enabled/default
COPY SwitchBoard/tests/banc/image/faux-services.conf /etc/nginx/conf.d/faux-services.conf

# Autorité de test, reconnue par le système : le code garde sa vérification TLS, sans modification.
# Sa clé n'a de valeur nulle part ailleurs que dans ce conteneur sans accès à Internet.
COPY SwitchBoard/tests/banc/image/hotes-faux.txt /etc/banc/hotes-faux.txt
COPY SwitchBoard/tests/banc/image/creer-certificats.sh /etc/banc/creer-certificats.sh
RUN bash /etc/banc/creer-certificats.sh && update-ca-certificates

# Les crons ne doivent jamais partir tout seuls pendant un scénario : un passage à la dixième minute
# rendrait les résultats aléatoires. Les scénarios lisent les vraies lignes dans /etc/banc/cronjobs.
RUN crontab -r && mv /etc/cron.d/cronjobs /etc/banc/cronjobs

# Le code à tester. Le zip est celui que reçoit la mise à jour automatique.
COPY --chown=www-data SwitchBoard /var/www/html/SwitchBoard/SwitchBoard
RUN cd /var/www/html/SwitchBoard/SwitchBoard \
	&& unzip -q src/precompileddependencies*.zip \
	&& mkdir -p download \
	&& chown -R www-data:www-data .

# Les courriels finissent dans un fichier, et les faux services gardent leur état ici.
COPY SwitchBoard/tests/banc/image/faux-msmtp.sh /usr/bin/msmtp
RUN chmod 755 /usr/bin/msmtp && mkdir -p /var/banc-etat && chown www-data:www-data /var/banc-etat

# PHPStan seulement dans cette image : il n'arrive jamais sur les serveurs (voir scenarios/50_phpstan.sh).
RUN mkdir -p /opt/phpstan && composer require --quiet --no-interaction --working-dir=/opt/phpstan phpstan/phpstan:2.1.33

# Les attentes de second essai sont raccourcies pour tout le banc (voir ce fichier)
COPY SwitchBoard/tests/banc/image/attentes-courtes.php /etc/banc/attentes-courtes.php
RUN echo 'auto_prepend_file=/etc/banc/attentes-courtes.php' > /usr/local/etc/php/conf.d/zz-banc.ini

COPY SwitchBoard/tests/banc/image/entree.sh /etc/banc/entree.sh

ENTRYPOINT ["/bin/bash", "/etc/banc/entree.sh"]
CMD ["php-fpm"]
