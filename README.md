# M3U Panel – Docker

Panel do zarządzania streamami YouTube Live jako kanały M3U.

## Instalacja (WAŻNA KOLEJNOŚĆ)

```bash
# 1. Rozpakuj projekt
tar -xzf m3u-panel.tar.gz
cd m3u-panel

# 2. Opcjonalnie – zmień port i hasło w docker-compose.yml

# 3. Uruchom skrypt inicjalizacyjny – OBOWIĄZKOWO przed pierwszym startem
./init.sh

# 4. Uruchom kontener
docker compose up -d --build
```

Panel dostępny pod: `http://TWOJ_IP:8084/panel.php`

> **Dlaczego init.sh jest konieczny?**
> Docker przy pierwszym uruchomieniu tworzy katalogi dla volumes których
> nie ma na hoście. Plik `channels.db` zostałby stworzony jako katalog
> zamiast pliku, co powoduje błąd PDO. `init.sh` tworzy poprawną
> strukturę przed uruchomieniem kontenera.

## Konfiguracja

Edytuj `docker-compose.yml`:

```yaml
environment:
  - PANEL_USER=admin       # login
  - PANEL_PASS=changeme    # hasło – zmień przed uruchomieniem!
  - TZ=Europe/Warsaw       # strefa czasowa

ports:
  - "8084:80"              # lewy = port hosta, prawy = zawsze 80
```

## Struktura danych

Dane persystentne trzymane są w `./data/` na hoście:

```
data/
├── channels.db    # baza kanałów SQLite (musi być plikiem!)
└── cache/         # pliki .cache i .videoid per kanał (musi być katalogiem!)
```

## URLe

| URL | Opis |
|-----|------|
| `/panel.php` | Panel zarządzania (wymaga logowania) |
| `/login.php` | Strona logowania |
| `/{slug}.m3u` | Link M3U dla odtwarzacza |

## Komendy

```bash
# Logi Apache
docker compose logs -f

# Logi update.php (cron)
docker compose exec m3u-panel tail -f /var/log/m3u-update.log

# Ręczne uruchomienie update
docker compose exec m3u-panel php /var/www/html/update.php

# Aktualizacja yt-dlp
docker compose exec m3u-panel yt-dlp -U

# Restart
docker compose restart

# Rebuild po zmianie kodu
docker compose up -d --build

# Zatrzymanie
docker compose down
```
