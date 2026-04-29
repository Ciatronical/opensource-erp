# ============================================
# Stage 1: Vue.js Frontend bauen
# ============================================
FROM node:25-alpine AS builder

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm install --no-audit --no-fund

COPY src/ ./src/
COPY public/ ./public/
COPY index.html vite.config.js ./
RUN npm run build

# ============================================
# Stage 2: PHP 8.3 FPM + Apache Runtime
# ============================================
FROM php:8.3-fpm-bookworm

# System-Pakete (inkl. curl CLI fuer Healthcheck)
# postgresql-client-16 aus dem offiziellen PGDG-Repo, damit pg_dump zur DB-Version passt
RUN apt-get update && apt-get install -y --no-install-recommends gnupg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
       | gpg --dearmor -o /usr/share/keyrings/pgdg.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
       > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends \
    apache2 \
    certbot \
    cron \
    curl \
    libpq-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    libicu-dev \
    libzip-dev \
    unzip \
    postgresql-client-16 \
    ssl-cert \
    && rm -rf /var/lib/apt/lists/*

# PHP-Extensions
RUN docker-php-ext-install \
    pgsql \
    pdo_pgsql \
    mbstring \
    xml \
    curl \
    intl \
    zip

# Node.js fuer SSE-Server
RUN curl -fsSL https://deb.nodesource.com/setup_25.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Apache-Module aktivieren (einmalig beim Build)
RUN a2enmod rewrite proxy_fcgi proxy proxy_http ssl headers env

# Apache-Sites konfigurieren
RUN a2dissite 000-default
COPY docker/web/apache-http.conf /etc/apache2/sites-available/oserp-http.conf
COPY docker/web/apache-ssl.conf /etc/apache2/sites-available/oserp-ssl.conf
RUN a2ensite oserp-http
# oserp-ssl wird vom Entrypoint aktiviert wenn Zertifikat vorhanden

# PHP-Konfiguration
COPY docker/web/php.ini /usr/local/etc/php/conf.d/oserp.ini

WORKDIR /var/www/html

# Composer aus offiziellem Image
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Backend kopieren (settings.ini ist im .dockerignore ausgeschlossen)
COPY backend/ ./backend/

# PHP-Abhaengigkeiten installieren (vendor/autoload.php erzeugen)
RUN cd backend && composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# Frontend aus Build-Stage kopieren
COPY --from=builder /build/dist/ ./dist/

# SSE-Server Dependencies
RUN cd backend/sse && npm ci --omit=dev

# Verzeichnisse erstellen
RUN mkdir -p backend/log backend/data backend/backups /var/www/certbot /etc/ssl/oserp \
    && chown -R www-data:www-data backend/log backend/data backend/backups

# Entrypoint
COPY docker/web/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80 443

ENTRYPOINT ["/entrypoint.sh"]
