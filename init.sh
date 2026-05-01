#!/bin/bash
# Uruchom ten skrypt PRZED pierwszym docker compose up
set -e

echo "=== M3U Panel – inicjalizacja ==="

# Utwórz katalog data jeśli nie istnieje
mkdir -p ./data
mkdir -p ./data/cache
mkdir -p ./data/backups

# channels.db musi być PLIKIEM (nie katalogiem)
if [ -d ./data/channels.db ]; then
    echo "WARN: channels.db jest katalogiem – usuwam"
    rm -rf ./data/channels.db
fi
touch ./data/channels.db
echo "✔ data/channels.db gotowy"
echo "✔ data/cache gotowy"
echo "✔ data/backups gotowy"

# Uprawnienia dla www-data (uid 33)
chown -R 33:33 ./data/
chmod 755 ./data/cache ./data/backups
chmod 644 ./data/channels.db

echo ""
echo "Struktura data/:"
ls -la ./data/
echo ""
echo "=== Gotowe. Uruchom: docker compose up -d --build ==="
