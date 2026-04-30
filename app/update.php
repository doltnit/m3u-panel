<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$debug   = true;
$logFile = '/var/log/m3u-update.log';

// Maksymalny wiek cache w minutach – po tym czasie wymuszamy odświeżenie URL
// nawet jeśli stary URL nadal odpowiada (bo może wygasnąć w trakcie oglądania)
define('CACHE_MAX_AGE_MIN', 240);

function log_msg(string $msg, string $logFile, bool $echo = true): void {
    $line = "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    if ($echo) echo $line;
}

if (!is_dir(__DIR__ . '/cache')) mkdir(__DIR__ . '/cache', 0755, true);

/**
 * Sprawdza czy URL streamu (googlevideo) nadal odpowiada.
 * Tani HEAD request – nie angażuje yt-dlp.
 */
function isUrlAlive(string $url): bool {
    if (empty($url)) return false;
    $ctx = stream_context_create(['http' => [
        'method'          => 'HEAD',
        'timeout'         => 6,
        'ignore_errors'   => true,
        'follow_location' => false,
    ]]);
    $headers = @get_headers($url, false, $ctx);
    if (!$headers) return false;
    $code = (int) substr($headers[0], 9, 3);
    return $code === 200 || $code === 206;
}

/**
 * Dla danego video ID sprawdza czy stream nadal jest live
 * i pobiera aktualny URL googlevideo.
 */
function getStreamUrl(string $videoId, string $logFile, bool $debug): string {
    $cmd = "yt-dlp -j --skip-download --no-warnings "
         . escapeshellarg("https://www.youtube.com/watch?v={$videoId}")
         . " 2>&1";
    if ($debug) log_msg("  CMD (stream): {$cmd}", $logFile);

    $raw = shell_exec($cmd);

    if (empty($raw)) {
        log_msg("  ERROR getStreamUrl: puste wyjście yt-dlp", $logFile);
        return '';
    }

    if ($raw[0] !== '{') {
        log_msg("  ERROR getStreamUrl: odpowiedź nie jest JSON: " . substr($raw, 0, 200), $logFile);
        return '';
    }

    $data = json_decode($raw, true);
    if (!$data) {
        log_msg("  ERROR getStreamUrl: json_decode failed", $logFile);
        return '';
    }

    $liveStatus = $data['live_status'] ?? '';
    log_msg("  live_status dla {$videoId}: {$liveStatus}", $logFile);

    if ($liveStatus !== 'is_live') {
        log_msg("  Stream {$videoId} nie jest już live ({$liveStatus})", $logFile);
        return '';
    }

    $url = $data['requested_formats'][0]['url'] ?? ($data['url'] ?? '');

    if (empty($url)) {
        log_msg("  ERROR getStreamUrl: brak pola url w JSON", $logFile);
        return '';
    }

    if (strpos($url, 'googlevideo.com') === false) {
        log_msg("  WARN getStreamUrl: URL wygląda podejrzanie: " . substr($url, 0, 100), $logFile);
    }

    return $url;
}

/**
 * Ostateczność – przeszukuje listę streamów kanału żeby znaleźć nowy stream
 * pasujący do keyword. Wywoływana tylko gdy znany video ID przestał być live.
 */
function findNewVideoId(string $ytChannel, string $keyword, string $logFile, bool $debug): string {
    log_msg("  Szukam nowego video ID na kanale {$ytChannel} (keyword: \"{$keyword}\")", $logFile);

    $name = rawurlencode(str_replace('@', '', $ytChannel));
    $urls = [
        "https://www.youtube.com/@{$name}/streams",
        "https://www.youtube.com/c/{$name}/streams",
    ];

    foreach ($urls as $url) {
        $cmd = "yt-dlp --flat-playlist --no-warnings -J " . escapeshellarg($url) . " 2>&1";
        if ($debug) log_msg("  CMD (lista): {$cmd}", $logFile);

        $raw = shell_exec($cmd);

        if (empty($raw)) {
            log_msg("  WARN: yt-dlp nie zwrócił nic dla {$url}", $logFile);
            continue;
        }

        if (strpos($raw, 'ERROR') !== false) {
            preg_match('/ERROR[^\n]+/', $raw, $m);
            log_msg("  WARN yt-dlp: " . ($m[0] ?? '?'), $logFile);
        }

        $data = json_decode($raw, true);
        if (!$data || empty($data['entries'])) {
            log_msg("  WARN: brak entries w JSON dla {$url}", $logFile);
            continue;
        }

        foreach ($data['entries'] as $entry) {
            $videoId = $entry['id'] ?? '';
            if (empty($videoId)) {
                preg_match('/v=([A-Za-z0-9_-]+)/', $entry['url'] ?? '', $m);
                $videoId = $m[1] ?? '';
            }
            $title  = $entry['title']       ?? '';
            $status = $entry['live_status'] ?? 'unknown';

            log_msg("  [" . strtoupper($status) . "] {$videoId} | {$title}", $logFile);

            if (!preg_match('/' . preg_quote($keyword, '/') . '/iU', $title)) continue;

            if ($status === 'is_live') {
                log_msg("  Znaleziono (is_live): {$videoId}", $logFile);
                return $videoId;
            }

            if ($status === 'unknown') {
                log_msg("  Weryfikuję kandydata (unknown): {$videoId}", $logFile);
                $verifyRaw = shell_exec("yt-dlp -j --skip-download --no-warnings "
                    . escapeshellarg("https://www.youtube.com/watch?v={$videoId}") . " 2>&1");
                if (!empty($verifyRaw) && $verifyRaw[0] === '{') {
                    $vdata   = json_decode($verifyRaw, true);
                    $vStatus = $vdata['live_status'] ?? '';
                    log_msg("  Weryfikacja {$videoId}: live_status = {$vStatus}", $logFile);
                    if ($vStatus === 'is_live') return $videoId;
                }
            }
        }

        log_msg("  Brak pasującego live streamu na liście kanału", $logFile);
        return '';
    }

    return '';
}

// ===================== MAIN =====================

log_msg("=== START UPDATE ===", $logFile);

try {
    $db       = new PDO('sqlite:' . __DIR__ . '/channels.db');
    $channels = $db->query("SELECT * FROM channels WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($channels as $row) {
        $slug      = $row['slug'];
        $keyword   = $row['keyword'];
        $cache     = __DIR__ . "/cache/{$slug}.cache";
        $videoFile = __DIR__ . "/cache/{$slug}.videoid";

        log_msg("\n--- {$slug} | kanał: {$row['yt_channel']} | keyword: \"{$keyword}\" ---", $logFile);

        $oldUrl     = trim(@file_get_contents($cache)     ?: '');
        $oldVideoId = trim(@file_get_contents($videoFile) ?: '');
        $cacheAge   = file_exists($cache) ? (int)round((time() - filemtime($cache)) / 60) : PHP_INT_MAX;
        $cacheOld   = $cacheAge >= CACHE_MAX_AGE_MIN;

        log_msg("  Cache age: {$cacheAge} min (limit: " . CACHE_MAX_AGE_MIN . " min) | videoId: " . ($oldVideoId ?: '(brak)'), $logFile);

        // ── Krok 1: czy możemy pominąć aktualizację? ──────────────────────
        // Pomijamy tylko gdy URL żyje I cache nie jest za stary
        if (!empty($oldUrl) && !$cacheOld && isUrlAlive($oldUrl)) {
            log_msg("  URL googlevideo żyje i cache świeży ({$cacheAge} min) – pomijam", $logFile);
            continue;
        }

        if ($cacheOld && !empty($oldUrl)) {
            log_msg("  Cache ma {$cacheAge} min – wymuszam odświeżenie URL (limit: " . CACHE_MAX_AGE_MIN . " min)", $logFile);
        } elseif (empty($oldUrl)) {
            log_msg("  Brak cache – szukam streamu", $logFile);
        } else {
            log_msg("  URL googlevideo nie odpowiada – odświeżam", $logFile);
        }

        // ── Krok 2: odśwież URL dla tego samego video ID ──────────────────
        $newUrl = '';
        if (!empty($oldVideoId)) {
            log_msg("  Odświeżam URL dla video ID: {$oldVideoId}", $logFile);
            $newUrl = getStreamUrl($oldVideoId, $logFile, $debug);
        }

        // ── Krok 3: video ID martwe – szukaj nowego streamu na liście ─────
        if (empty($newUrl)) {
            if (!empty($oldVideoId)) {
                log_msg("  Stream {$oldVideoId} zakończony lub niedostępny – szukam nowego", $logFile);
            }

            $newVideoId = findNewVideoId($row['yt_channel'], $keyword, $logFile, $debug);

            if (empty($newVideoId)) {
                log_msg("  BRAK: nie znaleziono live streamu pasującego do \"{$keyword}\"", $logFile);
                file_put_contents($cache, '');
                continue;
            }

            file_put_contents($videoFile, $newVideoId);
            log_msg("  Nowy video ID zapisany: {$newVideoId}", $logFile);

            $newUrl = getStreamUrl($newVideoId, $logFile, $debug);

            if (empty($newUrl)) {
                log_msg("  ERROR: znaleziono video ID ale nie udało się pobrać URL streamu", $logFile);
                file_put_contents($cache, '');
                continue;
            }
        }

        file_put_contents($cache, trim($newUrl));
        log_msg("  OK: cache zapisany -> " . substr($newUrl, 0, 80) . "...", $logFile);
    }

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage(), $logFile);
}

log_msg("=== KONIEC UPDATE ===\n", $logFile);
