<?php
/**
 * Wywoływany przez cron – tworzy automatyczny backup bazy.
 * Nie wymaga sesji HTTP.
 */
date_default_timezone_set('Europe/Warsaw');

define('DB_PATH',         __DIR__ . '/channels.db');
define('BACKUP_DIR',      __DIR__ . '/backups');
define('HOST_BACKUP_DIR', '/var/backups/m3u-panel');
define('MAX_BACKUPS',     7);

foreach ([BACKUP_DIR, HOST_BACKUP_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$ts       = date('Ymd_His');
$filename = "channels_backup_{$ts}.db";

// Kopia 1: lokalny backup dir
$localPath = BACKUP_DIR . '/' . $filename;
if (!copy(DB_PATH, $localPath)) {
    echo "[" . date('Y-m-d H:i:s') . "] BACKUP ERROR: nie udało się skopiować bazy\n";
    exit(1);
}

// Kopia 2: katalog na hoście
$hostPath = HOST_BACKUP_DIR . '/' . $filename;
$hostOk = @copy(DB_PATH, $hostPath);

// Rotacja: zachowaj tylko MAX_BACKUPS najnowszych
$files = glob(BACKUP_DIR . '/channels_backup_*.db') ?: [];
rsort($files);
$deleted = 0;
foreach (array_slice($files, MAX_BACKUPS) as $old) {
    @unlink($old);
    @unlink(HOST_BACKUP_DIR . '/' . basename($old));
    $deleted++;
}

$size = round(filesize($localPath) / 1024, 1);
$msg  = "[" . date('Y-m-d H:i:s') . "] BACKUP OK: {$filename} ({$size} KB)";
if ($hostOk) $msg .= " + host";
if ($deleted > 0) $msg .= " | usunieto: {$deleted}";
echo $msg . "\n";
