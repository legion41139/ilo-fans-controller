FROM php:apache

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions ssh2

COPY favicon.ico /var/www/html/
COPY ilo-core.inc.php /var/www/html/ilo-core.inc.php
COPY auto-control.php /var/www/html/auto-control.php
COPY ilo-fans-controller.php /var/www/html/index.php
COPY docker-entrypoint.sh /usr/local/bin/ilo-fans-entrypoint.sh

COPY config.inc.php.env /var/www/html/config.inc.php

RUN chmod +x /usr/local/bin/ilo-fans-entrypoint.sh

# On by default: background loop applies temperature-based fan speeds
ENV AUTO_CONTROL_ENABLED=1 \
    AUTO_POLL_INTERVAL=30

ENTRYPOINT ["/usr/local/bin/ilo-fans-entrypoint.sh"]
