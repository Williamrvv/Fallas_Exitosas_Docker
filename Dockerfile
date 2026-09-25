# =============================================================================
# Fallas Exitosas · Imagen del front-end (PHP 8.3 + Apache + SQL Server)
# -----------------------------------------------------------------------------
# Propósito : Servir la plataforma por HTTPS y conectarse a SQL Server y al
#             espejo de TSD mediante los controladores oficiales de Microsoft.
# Autor     : William Valverde V.
# Fecha     : 2026-09-24
# Bitácora  : 2026-09-24 Versión inicial (base tomada del prototipo del 20-sep,
#             más controladores ODBC/sqlsrv y OpenID Connect contra Entra ID).
# =============================================================================
FROM php:8.3-apache-bookworm@sha256:d9be5f5c49e07c71b1e906cf01a0bb7216dce466d1880d170467b9ca107071dd

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
ENV ACCEPT_EULA=Y

# --- Controladores de Microsoft SQL Server -----------------------------------
# msodbcsql18 es el controlador ODBC oficial; sqlsrv y pdo_sqlsrv son las
# extensiones PHP que se apoyan en él.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg apt-transport-https unixodbc-dev; \
    curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg; \
    printf '%s\n' \
        "deb [arch=amd64,arm64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
        > /etc/apt/sources.list.d/mssql-release.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends msodbcsql18; \
    pecl install sqlsrv-5.12.0 pdo_sqlsrv-5.12.0; \
    docker-php-ext-enable sqlsrv pdo_sqlsrv; \
    apt-get purge -y --auto-remove gnupg; \
    rm -rf /var/lib/apt/lists/*

# --- Apache: documento raíz, TLS y cabeceras de seguridad --------------------
RUN set -eux; \
    sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf; \
    a2enmod headers rewrite ssl; \
    a2dissite 000-default

COPY docker/apache-security.conf /etc/apache2/conf-available/zz-fallas-exitosas-security.conf
COPY docker/apache-vhosts.conf   /etc/apache2/sites-available/fallas-exitosas.conf
COPY docker/php.ini              /usr/local/etc/php/conf.d/fallas-exitosas.ini
COPY docker/tls-entrypoint.sh    /usr/local/bin/fallas-exitosas-entrypoint
COPY docker/healthcheck.php      /usr/local/bin/fallas-exitosas-healthcheck.php

RUN set -eux; \
    chmod 0755 /usr/local/bin/fallas-exitosas-entrypoint; \
    a2enconf zz-fallas-exitosas-security; \
    a2ensite fallas-exitosas

COPY --chown=www-data:www-data src/ /var/www/html/

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["php", "/usr/local/bin/fallas-exitosas-healthcheck.php"]

EXPOSE 80 443

ENTRYPOINT ["/usr/local/bin/fallas-exitosas-entrypoint"]
CMD ["apache2-foreground"]
