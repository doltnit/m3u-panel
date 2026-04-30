#!/bin/bash
# Uruchom ten skrypt PRZED pierwszym docker compose up
# ./init.sh

set -e

echo "=== M3U Panel – inicjalizacja ==="

# Utwórz strukturę data/ z poprawnymi typami
mkdir -p ./data/cache

# channels.db musi być PLIKIEM (nie katalogiem)
if [ ! -f ./data/channels.db ]; then
    touch ./data/channels.db
    echo "✔ Utworzono data/channels.db"
else
    echo "✔ data/channels.db już istnieje"
fi

# Ustaw uprawnienia dla www-data (uid 33 w kontenerze)
chown -R 33:33 ./data/
chmod 755 ./data/cache
chmod 644 ./data/channels.db

echo "✔ Uprawnienia ustawione"
echo ""
echo "Struktura data/:"
ls -la ./data/
echo ""
echo "=== Gotowe. Uruchom: docker compose up -d ==="
