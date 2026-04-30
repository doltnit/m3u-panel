#!/bin/bash
set -e

# Przekaż zmienne środowiskowe do crona
printenv | grep -E '^(PANEL_USER|PANEL_PASS|TZ)' > /etc/environment

# ── Upewnij się że struktura danych jest poprawna ──────────────────────────

# channels.db musi być PLIKIEM
if [ -d /var/www/html/channels.db ]; then
    echo "WARN: channels.db jest katalogiem – usuwam i tworzę plik"
    rm -rf /var/www/html/channels.db
fi
if [ ! -f /var/www/html/channels.db ]; then
    touch /var/www/html/channels.db
fi

# cache musi być KATALOGIEM
if [ -f /var/www/html/cache ]; then
    echo "WARN: cache jest plikiem – usuwam i tworzę katalog"
    rm -f /var/www/html/cache
fi
mkdir -p /var/www/html/cache

# Uprawnienia dla www-data
chown -R www-data:www-data /var/www/html/channels.db /var/www/html/cache

# Plik logu wewnątrz kontenera
touch /var/log/m3u-update.log
chown www-data:www-data /var/log/m3u-update.log

# ── yt-dlp update ──────────────────────────────────────────────────────────
echo "[$(date)] Updating yt-dlp..." >> /var/log/m3u-update.log
/usr/local/bin/yt-dlp -U >> /var/log/m3u-update.log 2>&1 || true

# ── Start serwisów ─────────────────────────────────────────────────────────
service cron start
exec apache2-foreground
