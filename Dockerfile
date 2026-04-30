FROM php:8.2-apache

# System dependencies + libsqlite3-dev wymagane do kompilacji pdo_sqlite
RUN apt-get update && apt-get install -y \
    python3 \
    curl \
    cron \
    sqlite3 \
    libsqlite3-dev \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

# yt-dlp
RUN curl -sSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp \
    && chmod +x /usr/local/bin/yt-dlp

# PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Apache: mod_rewrite + AllowOverride
RUN a2enmod rewrite
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# PHP session dir permissions
RUN mkdir -p /var/lib/php/sessions && chmod 777 /var/lib/php/sessions

# Cron job
COPY cron/crontab /etc/cron.d/m3u-cron
RUN chmod 0644 /etc/cron.d/m3u-cron \
    && crontab /etc/cron.d/m3u-cron

# App files
COPY app/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Entrypoint
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

CMD ["/usr/local/bin/docker-entrypoint.sh"]
