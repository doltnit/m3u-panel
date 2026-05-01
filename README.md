# M3U Panel

Panel do zarządzania streamami YouTube Live jako kanały M3U. Zbudowany na PHP + SQLite + Apache, uruchamiany przez Docker.

## Instalacja

```bash
git clone https://github.com/doltnit/m3u-panel.git
cd m3u-panel
```

### Konfiguracja

Edytuj `docker-compose.yml`:

```yaml
environment:
  - PANEL_USER=admin       # login do panelu
  - PANEL_PASS=changeme    # hasło – zmień przed uruchomieniem!
  - TZ=Europe/Warsaw       # strefa czasowa

ports:
  - "8084:80"              # zmień 8084 na dowolny port hosta
```

### Uruchomienie

```bash
./init.sh
docker compose up -d --build
```

Panel dostępny pod: `http://TWOJ_IP:8084`

> **Dlaczego init.sh jest konieczny?**
> Docker przy pierwszym uruchomieniu tworzy katalogi dla volumes których
> nie ma na hoście. `init.sh` tworzy poprawną strukturę plików i uprawnień
> zanim kontener wystartuje.

## Zmiana hasła

Edytuj `docker-compose.yml`, następnie:

```bash
docker compose down
docker compose up -d
```

## Struktura projektu

```
m3u-panel/
├── Dockerfile
├── docker-compose.yml
├── docker-entrypoint.sh
├── init.sh                # uruchom przed pierwszym docker compose up
├── app/
│   ├── index.php          # przekierowanie na panel
│   ├── panel.php          # panel zarządzania (kanały + logi)
│   ├── login.php          # strona logowania
│   ├── auth.php           # logika sesji
│   ├── backup.php         # panel backupów
│   ├── backup_cron.php    # skrypt backupu dla crona
│   ├── m3u.php            # endpoint dla odtwarzaczy
│   ├── update.php         # skrypt crona – aktualizacja streamów
│   └── .htaccess
├── cron/
│   └── crontab
└── data/                  # dane persystentne (ignorowane przez git)
    ├── channels.db        # baza kanałów SQLite
    ├── cache/             # pliki .cache i .videoid per kanał
    └── backups/           # backupy bazy danych
```

## URLe

| URL | Opis |
|-----|------|
| `http://IP:PORT/` | Przekierowanie na panel |
| `http://IP:PORT/panel.php` | Panel – kanały i logi |
| `http://IP:PORT/backup.php` | Panel backupów |
| `http://IP:PORT/{nazwa-url}.m3u` | Link M3U dla odtwarzacza |

## Jak działa aktualizacja streamów

Skrypt `update.php` uruchamiany jest co 30 minut przez cron. Dla każdego aktywnego kanału:

1. **HEAD request** do zapisanego URL googlevideo – jeśli odpowiada i cache < 240 min → nic nie rób
2. **Odśwież URL** dla zapisanego video ID (jedno wywołanie yt-dlp)
3. **Szukaj nowego streamu** na liście kanału (yt-dlp --flat-playlist) – tylko gdy stream się skończył

## Backup

Backup bazy `channels.db` tworzony jest automatycznie codziennie o 2:00.
Przechowywanych jest maksymalnie 7 ostatnich backupów.
Każdy backup zapisywany jest w dwóch miejscach:
- wewnątrz kontenera: `/var/www/html/backups/`
- na hoście: `/var/backups/m3u-panel/` (przeżywa `docker compose down`)

Przez panel backupów można:
- tworzyć backup ręcznie
- pobierać backup przez przeglądarkę
- przywracać backup (z automatycznym zabezpieczeniem obecnej bazy)
- wgrać backup z innego serwera

## Harmonogram crona

| Zadanie | Częstotliwość |
|---------|--------------|
| Aktualizacja streamów | co 30 minut |
| Backup bazy | codziennie o 2:00 |
| Aktualizacja yt-dlp | codziennie o 4:00 |
| Rotacja logów | co poniedziałek o 3:00 |

## Przydatne komendy

```bash
# Logi crona / update.php
docker compose exec m3u-panel tail -f /var/log/m3u-update.log

# Ręczne uruchomienie update
docker compose exec m3u-panel php /var/www/html/update.php

# Ręczny backup
docker compose exec m3u-panel php /var/www/html/backup_cron.php

# Aktualizacja yt-dlp
docker compose exec m3u-panel yt-dlp -U

# Restart kontenera
docker compose restart

# Zatrzymanie
docker compose down

# Pełny reset (usuwa też dane)
docker compose down && rm -rf ./data/
```
