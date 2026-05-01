<?php
require_once __DIR__ . '/auth.php';

// Obsługa wylogowania
if (isset($_GET['logout'])) {
    auth_logout();
    header('Location: login.php');
    exit;
}

// Sprawdź czy zalogowany
if (!auth_check()) {
    header('Location: login.php');
    exit;
}

date_default_timezone_set('Europe/Warsaw');
$db = new PDO('sqlite:' . __DIR__ . '/channels.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    slug TEXT UNIQUE,
    yt_channel TEXT,
    keyword TEXT,
    active INTEGER DEFAULT 1,
    position INTEGER DEFAULT 0
)");

$cols = $db->query("PRAGMA table_info(channels)")->fetchAll(PDO::FETCH_ASSOC);
$hasId = false;
foreach ($cols as $c) { if ($c['name'] === 'id') $hasId = true; }

if (!$hasId) {
    $db->exec("CREATE TABLE channels_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, slug TEXT UNIQUE, yt_channel TEXT,
        keyword TEXT, active INTEGER DEFAULT 1, position INTEGER DEFAULT 0
    )");
    $rows = $db->query("SELECT * FROM channels")->fetchAll(PDO::FETCH_ASSOC);
    $i = 1;
    foreach ($rows as $r) {
        $db->prepare("INSERT INTO channels_new (name,slug,yt_channel,keyword,active,position) VALUES (?,?,?,?,?,?)")
           ->execute([$r['name']??'', $r['slug']??'', $r['yt_channel']??'', $r['keyword']??'', $r['active']??1, $r['position']??$i]);
        $i++;
    }
    $db->exec("DROP TABLE channels");
    $db->exec("ALTER TABLE channels_new RENAME TO channels");
}

function reorder($db) {
    $rows = $db->query("SELECT id FROM channels ORDER BY position ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $i = 1;
    foreach ($rows as $r) {
        $db->prepare("UPDATE channels SET position=? WHERE id=?")->execute([$i++, $r['id']]);
    }
}

function isUrlAlive(string $url): bool {
    if (empty($url)) return false;
    $ctx = stream_context_create(['http' => [
        'method' => 'HEAD', 'timeout' => 6,
        'ignore_errors' => true, 'follow_location' => false,
    ]]);
    $headers = @get_headers($url, false, $ctx);
    if (!$headers) return false;
    $code = (int) substr($headers[0], 9, 3);
    return $code === 200 || $code === 206;
}

function getStreamUrl(string $videoId): array {
    $cmd = "yt-dlp -j --skip-download --no-warnings "
         . escapeshellarg("https://www.youtube.com/watch?v={$videoId}") . " 2>&1";
    $raw = shell_exec($cmd);
    if (empty($raw) || $raw[0] !== '{') return ['url' => '', 'live' => false, 'error' => substr($raw ?? '', 0, 200)];
    $data = json_decode($raw, true);
    if (!$data) return ['url' => '', 'live' => false, 'error' => 'json_decode failed'];
    $liveStatus = $data['live_status'] ?? '';
    if ($liveStatus !== 'is_live') return ['url' => '', 'live' => false, 'error' => "live_status: {$liveStatus}"];
    $url = $data['requested_formats'][0]['url'] ?? ($data['url'] ?? '');
    return ['url' => $url, 'live' => true, 'error' => ''];
}

function findNewVideoId(string $ytChannel, string $keyword): array {
    $name = rawurlencode(str_replace('@', '', $ytChannel));
    $urls = [
        "https://www.youtube.com/@{$name}/streams",
        "https://www.youtube.com/c/{$name}/streams",
    ];
    foreach ($urls as $url) {
        $cmd = "yt-dlp --flat-playlist --no-warnings -J " . escapeshellarg($url) . " 2>&1";
        $raw = shell_exec($cmd);
        if (empty($raw)) continue;
        $data = json_decode($raw, true);
        if (!$data || empty($data['entries'])) continue;
        foreach ($data['entries'] as $entry) {
            $videoId = $entry['id'] ?? '';
            if (empty($videoId)) {
                preg_match('/v=([A-Za-z0-9_-]+)/', $entry['url'] ?? '', $m);
                $videoId = $m[1] ?? '';
            }
            $title  = $entry['title'] ?? '';
            $status = $entry['live_status'] ?? 'unknown';
            if (!preg_match('/' . preg_quote($keyword, '/') . '/iU', $title)) continue;
            if ($status === 'is_live') return ['videoId' => $videoId, 'title' => $title];
            if ($status === 'unknown') {
                $vraw = shell_exec("yt-dlp -j --skip-download --no-warnings "
                    . escapeshellarg("https://www.youtube.com/watch?v={$videoId}") . " 2>&1");
                if (!empty($vraw) && $vraw[0] === '{') {
                    $vdata = json_decode($vraw, true);
                    if (($vdata['live_status'] ?? '') === 'is_live') return ['videoId' => $videoId, 'title' => $title];
                }
            }
        }
    }
    return [];
}

function refreshCache(string $slug, string $yt_channel, string $keyword): array {
    if (!is_dir(__DIR__ . '/cache')) mkdir(__DIR__ . '/cache', 0755, true);
    $cache     = __DIR__ . "/cache/{$slug}.cache";
    $videoFile = __DIR__ . "/cache/{$slug}.videoid";
    $oldUrl     = trim(@file_get_contents($cache)     ?: '');
    $oldVideoId = trim(@file_get_contents($videoFile) ?: '');

    if (!empty($oldUrl) && isUrlAlive($oldUrl))
        return ['ok' => true, 'msg' => 'URL googlevideo nadal aktywny – cache bez zmian', 'videoid' => $oldVideoId];

    if (!empty($oldVideoId)) {
        $result = getStreamUrl($oldVideoId);
        if (!empty($result['url'])) {
            file_put_contents($cache, trim($result['url']));
            return ['ok' => true, 'msg' => "URL odswiezony dla video ID: {$oldVideoId}", 'videoid' => $oldVideoId];
        }
    }

    $found = findNewVideoId($yt_channel, $keyword);
    if (empty($found)) {
        file_put_contents($cache, '');
        return ['ok' => false, 'msg' => "Brak live streamu pasujacego do \"{$keyword}\"", 'videoid' => ''];
    }
    $newVideoId = $found['videoId'];
    file_put_contents($videoFile, $newVideoId);
    $result = getStreamUrl($newVideoId);
    if (empty($result['url'])) {
        file_put_contents($cache, '');
        return ['ok' => false, 'msg' => "Znaleziono {$newVideoId} ale blad URL: " . $result['error'], 'videoid' => $newVideoId];
    }
    file_put_contents($cache, trim($result['url']));
    return ['ok' => true, 'msg' => "Nowy stream: {$newVideoId} ({$found['title']})", 'videoid' => $newVideoId];
}

function getCacheInfo(string $slug): array {
    $f = __DIR__ . "/cache/{$slug}.cache";
    $v = __DIR__ . "/cache/{$slug}.videoid";
    $videoId = trim(@file_get_contents($v) ?: '');
    if (!file_exists($f)) return ['status' => 'none', 'time' => '', 'videoid' => $videoId];
    $content = trim(file_get_contents($f));
    $time = date('d.m H:i', filemtime($f));
    if ($content === '') return ['status' => 'empty', 'time' => $time, 'videoid' => $videoId];
    return ['status' => 'ok', 'time' => $time, 'videoid' => $videoId];
}

function ytChannelUrl(string $ch): string {
    $ch = trim($ch);
    if (strpos($ch, '@') === 0) return 'https://www.youtube.com/' . $ch;
    return 'https://www.youtube.com/c/' . ltrim($ch, '/');
}

$flashMsg = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add'])) {
        $max = $db->query("SELECT COALESCE(MAX(position),0) FROM channels")->fetchColumn();
        $db->prepare("INSERT INTO channels (name,slug,yt_channel,keyword,active,position) VALUES (?,?,?,?,1,?)")
           ->execute([$_POST['name'], $_POST['slug'], $_POST['yt_channel'], $_POST['keyword'], $max + 1]);
    }

    if (isset($_POST['edit_save'])) {
        $db->prepare("UPDATE channels SET name=?,slug=?,yt_channel=?,keyword=? WHERE id=?")
           ->execute([$_POST['name'], $_POST['slug'], $_POST['yt_channel'], $_POST['keyword'], (int)$_POST['id']]);
    }


    if (isset($_POST['delete'])) {
        $db->prepare("DELETE FROM channels WHERE id=?")->execute([$_POST['id']]);
        $slug = $_POST['slug'] ?? '';
        if ($slug) { @unlink(__DIR__ . "/cache/{$slug}.cache"); @unlink(__DIR__ . "/cache/{$slug}.videoid"); }
    }

    if (isset($_POST['toggle'])) {
        $db->prepare("UPDATE channels SET active = NOT active WHERE id=?")->execute([$_POST['id']]);
    }

    if (isset($_POST['refresh_cache'])) {
        $row = $db->prepare("SELECT * FROM channels WHERE id=?");
        $row->execute([(int)$_POST['id']]); $row = $row->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $result = refreshCache($row['slug'], $row['yt_channel'], $row['keyword']);
            $flashMsg  = $result['msg'];
            $flashType = $result['ok'] ? 'success' : 'error';
        }
        reorder($db);
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($flashMsg) . "&type=" . $flashType);
        exit;
    }

    reorder($db);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_GET['msg'])) { $flashMsg = $_GET['msg']; $flashType = $_GET['type'] ?? 'success'; }

$editId   = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$channels = $db->query("SELECT * FROM channels ORDER BY position ASC")->fetchAll(PDO::FETCH_ASSOC);
$self     = htmlspecialchars($_SERVER['PHP_SELF']);
$view     = $_GET['view'] ?? 'channels';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>M3U Panel</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --ink:       #0d1117;
  --ink2:      #3d4a5c;
  --ink3:      #8693a4;
  --ink4:      #b8c3cf;
  --bg:        #f0f2f6;
  --surface:   #ffffff;
  --surface2:  #f7f9fc;
  --border:    rgba(0,0,0,.07);
  --border2:   rgba(0,0,0,.12);

  --ind:       #3730a3;
  --ind-mid:   #4f46e5;
  --ind-l:     #eef2ff;
  --ind-b:     #c7d2fe;

  --em:        #059669;
  --em-l:      #ecfdf5;
  --em-b:      #a7f3d0;

  --warn:      #d97706;
  --warn-l:    #fffbeb;
  --warn-b:    #fde68a;

  --danger:    #dc2626;
  --danger-l:  #fef2f2;
  --danger-b:  #fecaca;

  --gray-l:    #f1f5f9;
  --gray-b:    #cbd5e1;

  --sh-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
  --sh:    0 4px 16px rgba(0,0,0,.06), 0 1px 4px rgba(0,0,0,.04);
  --sh-lg: 0 8px 32px rgba(0,0,0,.08), 0 2px 8px rgba(0,0,0,.04);

  --r:   14px;
  --r-sm: 9px;
  --r-xs: 6px;
  --sans: 'DM Sans', -apple-system, sans-serif;
  --mono: 'DM Mono', 'Courier New', monospace;
}

html, body {
  font-family: var(--sans);
  background: var(--bg);
  color: var(--ink);
  line-height: 1.5;
  font-size: 18px;
  -webkit-text-size-adjust: none;
  text-size-adjust: none;
  -webkit-font-smoothing: antialiased;
}

/* ── TOPBAR ── */
.topbar {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  box-shadow: var(--sh-sm);
  padding: 0 20px;
  height: 58px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 100;
}
.topbar-brand {
  display: flex;
  align-items: center;
  gap: 10px;
}
.topbar-logo {
  width: 32px; height: 32px;
  background: linear-gradient(135deg, var(--ind-mid), var(--ind));
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 8px rgba(79,70,229,.3);
  flex-shrink: 0;
}
.topbar-logo svg { width: 16px; height: 16px; fill: #fff; }
.topbar-name {
  font-size: 17px;
  font-weight: 700;
  color: var(--ink);
  letter-spacing: -.3px;
}
.topbar-logout {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px; height: 32px;
  border-radius: 8px;
  color: var(--ink3);
  text-decoration: none;
  transition: background .15s, color .15s;
  flex-shrink: 0;
}
.topbar-logout:hover { background: var(--danger-l); color: var(--danger); }
.topbar-time {
  font-family: var(--mono);
  font-size: 12px;
  color: var(--ink3);
  background: var(--surface2);
  border: 1px solid var(--border2);
  padding: 5px 11px;
  border-radius: 20px;
  letter-spacing: .02em;
}

/* ── PAGE ── */
.layout {
  max-width: 1400px;
  margin: 0 auto;
  padding: 24px 16px 80px;
}

@media (min-width: 900px) {
  .layout {
    display: grid;
    grid-template-columns: 300px 1fr;
    grid-template-rows: auto 1fr;
    gap: 0 28px;
    align-items: start;
  }
  .sidebar   { grid-column: 1; grid-row: 1 / 3; position: sticky; top: 78px; }
  .chanhead  { grid-column: 2; grid-row: 1; }
  .cards     { grid-column: 2; grid-row: 2; }
}

/* ── SECTION LABEL ── */
.slabel {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--ink3);
  margin-bottom: 12px;
}

/* ── FLASH ── */
.flash {
  padding: 14px 16px;
  border-radius: var(--r-sm);
  font-size: 14px;
  margin-bottom: 20px;
  word-break: break-word;
  border: 1px solid;
  display: flex;
  gap: 10px;
  align-items: flex-start;
}
.flash-ok  { background: var(--em-l);     border-color: var(--em-b);     color: #065f46; }
.flash-err { background: var(--danger-l); border-color: var(--danger-b); color: #991b1b; }

/* ── ADD FORM ── */
.addbox {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 20px;
  margin-bottom: 24px;
  box-shadow: var(--sh);
}
@media (min-width: 900px) { .addbox { margin-bottom: 0; } }

.field-label {
  display: block;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--ink3);
  margin-bottom: 6px;
}
.field-input {
  display: block;
  width: 100%;
  font-family: var(--sans);
  font-size: 15px;
  padding: 11px 13px;
  border: 1.5px solid var(--border2);
  border-radius: var(--r-sm);
  background: var(--surface2);
  color: var(--ink);
  margin-bottom: 14px;
  -webkit-appearance: none;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.field-input:focus {
  border-color: var(--ind-mid);
  box-shadow: 0 0 0 3px rgba(79,70,229,.1);
  background: var(--surface);
}
.btn-add {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: center;
  gap: 7px;
  font-family: var(--sans);
  font-size: 15px;
  font-weight: 600;
  padding: 12px;
  background: linear-gradient(135deg, var(--ind-mid), var(--ind));
  color: #fff;
  border: none;
  border-radius: var(--r-sm);
  cursor: pointer;
  box-shadow: 0 2px 12px rgba(79,70,229,.28);
  transition: box-shadow .15s, transform .1s;
}
.btn-add:active { transform: scale(.98); }
.btn-add svg { flex-shrink: 0; }

/* ── CARDS ── */
.cards { display: flex; flex-direction: column; gap: 12px; }

@media (min-width: 900px) {
  .cards { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: start; }
}

.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r);
  overflow: hidden;
  box-shadow: var(--sh);
  transition: box-shadow .2s;
}
.card:hover { box-shadow: var(--sh-lg); }
.card.off { opacity: .5; }

/* Accent strip */
.cbody { border-left: 4px solid var(--em); padding: 16px 16px 16px 16px; }
.card.off .cbody { border-left-color: var(--gray-b); }

/* Nazwa */
.cname {
  font-size: 17px;
  font-weight: 700;
  color: var(--ink);
  word-break: break-word;
  margin-bottom: 10px;
  line-height: 1.25;
  letter-spacing: -.2px;
}
.cnum {
  font-size: 12px;
  font-weight: 500;
  color: var(--ink4);
  margin-right: 5px;
}

/* Status badges */
.badges { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 20px;
  border: 1px solid;
  letter-spacing: .01em;
}
.badge svg { width: 8px; height: 8px; flex-shrink: 0; }
.b-ok     { background: var(--em-l);     color: #065f46; border-color: var(--em-b); }
.b-empty  { background: var(--warn-l);   color: #92400e; border-color: var(--warn-b); }
.b-none   { background: var(--gray-l);   color: var(--ink3); border-color: var(--gray-b); }
.b-on     { background: var(--em-l);     color: #065f46; border-color: var(--em-b); }
.b-off    { background: var(--gray-l);   color: var(--ink3); border-color: var(--gray-b); }

/* Meta block */
.cmeta {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  padding: 10px 13px;
  margin-bottom: 12px;
}
.mrow {
  display: flex;
  gap: 10px;
  padding: 3px 0;
  font-size: 13px;
  color: var(--ink2);
  font-family: var(--mono);
}
.mkey {
  font-size: 10px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--ink4);
  min-width: 32px;
  flex-shrink: 0;
  padding-top: 1px;
}
.mval { word-break: break-all; min-width: 0; }

/* YT Links */
.ytlinks { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 14px; }
.ytlink {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  padding: 7px 13px;
  border-radius: 20px;
  text-decoration: none;
  background: var(--danger-l);
  color: var(--danger);
  border: 1px solid var(--danger-b);
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  transition: background .15s;
}
.ytlink:active { background: #fee2e2; }
.ytlink svg { width: 13px; height: 13px; fill: currentColor; flex-shrink: 0; }
.ytlink-dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--danger);
  flex-shrink: 0;
  animation: livepulse 1.8s ease-in-out infinite;
}
@keyframes livepulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.35;transform:scale(.65)} }

/* Actions */
.actions { border-top: 1px solid var(--border); padding-top: 12px; }
.arow { display: flex; gap: 7px; margin-bottom: 7px; }
.arow:last-child { margin-bottom: 0; }
.arow > form { display: contents; }
.arow > form > .abtn,
.arow > a.abtn { flex: 1 1 0; min-width: 0; width: 0; height: 38px; }
@media (max-width: 899px) {
  .arow > form > .abtn,
  .arow > a.abtn { height: 44px; }
}

/* Jeden height dla wszystkich – button i a identyczne */
.abtn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 38px;
  padding: 0 10px;
  font-family: var(--sans);
  font-size: 13px;
  font-weight: 600;
  border-radius: var(--r-sm);
  border: 1.5px solid var(--border2);
  background: var(--surface2);
  color: var(--ink2);
  cursor: pointer;
  text-decoration: none;
  -webkit-tap-highlight-color: transparent;
  -webkit-appearance: none;
  appearance: none;
  letter-spacing: .01em;
  transition: background .12s, border-color .12s;
  white-space: nowrap;
  box-sizing: border-box;
  line-height: 1;
  margin: 0;
}
@media (max-width: 899px) { .abtn { font-size: 14px; } }
.abtn:active { opacity: .75; }
.abtn svg { flex-shrink: 0; stroke: currentColor; }
.abtn-warn   { background: var(--warn-l);   color: #92400e;  border-color: var(--warn-b); }
.abtn-danger { background: var(--danger-l); color: #991b1b;  border-color: var(--danger-b); }
.abtn-ok     { background: var(--em-l);     color: #065f46;  border-color: var(--em-b); }
.abtn-ghost  { background: transparent;     color: var(--ink3); border-color: var(--border2); }
.abtn-ind    {
  background: linear-gradient(135deg, var(--ind-mid), var(--ind));
  color: #fff; border-color: transparent;
  box-shadow: 0 2px 8px rgba(79,70,229,.2);
}

/* Edit box */
.editbox {
  background: var(--ind-l);
  border-top: 2px solid var(--ind-mid);
  padding: 18px 16px;
}

/* Divider mobile */
.divider { height: 1px; background: var(--border); margin: 22px 0 18px; }
@media (min-width: 900px) { .divider { display: none; } }


/* M3U link block */
.m3u-block {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--ind-l);
  border: 1px solid var(--ind-b);
  border-radius: var(--r-sm);
  padding: 8px 10px 8px 12px;
  margin-bottom: 12px;
}
.m3u-label {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--ind-mid);
  flex-shrink: 0;
}
.m3u-url {
  font-family: var(--mono);
  font-size: 12px;
  color: var(--ink2);
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.m3u-copy {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 600;
  color: var(--ind-mid);
  background: var(--surface);
  border: 1px solid var(--ind-b);
  border-radius: 6px;
  padding: 5px 9px;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
  white-space: nowrap;
  font-family: var(--sans);
  transition: background .12s;
}
.m3u-copy:hover { background: #e0e7ff; }
.m3u-copy svg { width: 12px; height: 12px; stroke: currentColor; fill: none;
                stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.m3u-copied { color: var(--em) !important; border-color: var(--em-b) !important; }

/* Nav tabs */
.topbar-nav { display: flex; gap: 2px; margin-left: 16px; }
.nav-tab {
  display: flex; align-items: center; gap: 5px;
  padding: 6px 12px; border-radius: var(--r-sm);
  font-size: 13px; font-weight: 600;
  text-decoration: none; color: var(--ink3);
  transition: background .15s, color .15s;
}
.nav-tab:hover { background: var(--surface2); color: var(--ink2); }
.nav-tab.active { background: var(--ind-l); color: var(--ind-mid); }
.nav-tab svg { width: 14px; height: 14px; stroke: currentColor; fill: none;
               stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

/* Log viewer */
.log-wrap { max-width: 1400px; margin: 0 auto; padding: 24px 16px 80px; }
.log-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r); box-shadow: var(--sh); overflow: hidden;
}
.log-toolbar {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-bottom: 1px solid var(--border);
  flex-wrap: wrap;
}
.log-toolbar-title { font-size: 14px; font-weight: 700; color: var(--ink); flex: 1; }
.log-lines {
  font-family: var(--mono);
  font-size: 12px;
  line-height: 1.6;
  padding: 14px 16px;
  background: #0d1117;
  color: #c9d1d9;
  height: 600px;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-all;
}
.log-line-ok     { color: #3fb950; }
.log-line-err    { color: #f85149; }
.log-line-warn   { color: #d29922; }
.log-line-info   { color: #79c0ff; }
.log-line-sep    { color: #484f58; }
.log-empty { color: #484f58; font-style: italic; }

/* Desktop font scaling */
@media (min-width: 900px) {
  html, body { font-size: 15px; }
  .cname { font-size: 15px; }
  .abtn  { font-size: 12px; height: 34px; padding: 0 8px; }
}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-brand">
    <div class="topbar-logo">
      <svg viewBox="0 0 24 24"><path d="M21 3H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5v2H6v2h12v-2h-2v-2h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 14H3V5h18v12z"/></svg>
    </div>
    <span class="topbar-name">M3U Panel</span>
  </div>
  <nav class="topbar-nav">
    <a href="panel.php" class="nav-tab <?= ($view !== 'logs') ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Kanały
    </a>
    <a href="panel.php?view=logs" class="nav-tab <?= ($view === 'logs') ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      Logi
    </a>
    <a href="backup.php" class="nav-tab">
      <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Backup
    </a>
  </nav>
  <div style="display:flex;align-items:center;gap:10px">
    <span class="topbar-time"><?= date('d.m.Y  H:i') ?></span>
    <a href="?logout=1" class="topbar-logout" title="Wyloguj się">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </a>
  </div>
</div>

<div class="layout">

<?php if ($view === 'logs'): ?>
<?php
  $logFile = '/var/log/m3u-update.log';
  $lines = [];
  if (file_exists($logFile)) {
      $all = file($logFile, FILE_IGNORE_NEW_LINES);
      $lines = array_slice($all, -500); // ostatnie 500 linii
      $lines = array_reverse($lines);   // najnowsze na górze
  }
  // Znajdź archiwalne pliki logów
  $archives = glob('/var/log/m3u-update.log.*');
  rsort($archives);
?>
</div><!-- /layout zamknij bo log ma własny wrapper -->
<div class="log-wrap">
  <div class="log-card">
    <div class="log-toolbar">
      <span class="log-toolbar-title">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:5px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        update.log
        <span style="font-weight:400;color:var(--ink3);font-size:12px;margin-left:6px">(ostatnie 500 linii)</span>
      </span>
      <?php if (!empty($archives)): ?>
      <select onchange="if(this.value) window.location='panel.php?view=logs&arch='+this.value"
              style="font-size:12px;padding:5px 8px;border:1px solid var(--border2);border-radius:6px;background:var(--surface2);color:var(--ink2);cursor:pointer">
        <option value="">Bieżący log</option>
        <?php foreach ($archives as $arch): ?>
        <option value="<?= htmlspecialchars(basename($arch)) ?>"
                <?= (($_GET['arch'] ?? '') === basename($arch)) ? 'selected' : '' ?>>
          <?= htmlspecialchars(basename($arch)) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <a href="panel.php?view=logs" class="abtn abtn-ghost" style="font-size:12px;padding:5px 10px;min-height:auto;text-decoration:none">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        Odśwież
      </a>
    </div>
    <div class="log-lines" id="logbox">
<?php
  // Jeśli wybrano archiwum
  $archFile = '';
  if (!empty($_GET['arch'])) {
      $archFile = '/var/log/' . basename($_GET['arch']);
      if (file_exists($archFile)) {
          $all = file($archFile, FILE_IGNORE_NEW_LINES);
          $lines = array_reverse($all);
      }
  }

  if (empty($lines)): ?>
      <span class="log-empty">Brak wpisów w logu.</span>
<?php else:
    foreach ($lines as $line):
        $line = htmlspecialchars($line);
        $cls = 'log-line-info';
        if (str_contains($line, 'OK:') || str_contains($line, 'żyje') || str_contains($line, 'KONIEC'))
            $cls = 'log-line-ok';
        elseif (str_contains($line, 'ERROR') || str_contains($line, 'EXCEPTION') || str_contains($line, 'BŁĄD'))
            $cls = 'log-line-err';
        elseif (str_contains($line, 'WARN') || str_contains($line, 'BRAK') || str_contains($line, 'nie jest już'))
            $cls = 'log-line-warn';
        elseif (str_contains($line, '==='))
            $cls = 'log-line-sep';
        echo '<span class="' . $cls . '">' . $line . "</span>\n";
    endforeach;
endif; ?>
    </div>
  </div>
</div>

<?php else: /* widok kanałów */ ?>

<?php if ($flashMsg): ?>
<div class="flash <?= $flashType === 'success' ? 'flash-ok' : 'flash-err' ?>" style="grid-column:1/-1">
  <?php if ($flashType === 'success'): ?>
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
  <?php else: ?>
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  <?php endif; ?>
  <span><?= htmlspecialchars($flashMsg) ?></span>
</div>
<?php endif; ?>

<!-- SIDEBAR -->
<div class="sidebar">
  <p class="slabel">Dodaj kanał</p>
  <div class="addbox">
    <form method="post">
      <label class="field-label">Nazwa</label>
      <input class="field-input" type="text" name="name" placeholder="TVN24" required>
      <label class="field-label">Nazwa URL</label>
      <input class="field-input" type="text" name="slug" placeholder="tvn24" required>
      <label class="field-label">Kanał YouTube</label>
      <input class="field-input" type="text" name="yt_channel" placeholder="@tvn24" required>
      <label class="field-label">Keyword</label>
      <input class="field-input" type="text" name="keyword" placeholder="kamera" required>
      <button class="btn-add" name="add">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Dodaj kanał
      </button>
    </form>
  </div>
</div>

<div class="divider"></div>

<div class="chanhead">
  <p class="slabel" style="margin-bottom:16px">Kanały &nbsp;·&nbsp; <?= count($channels) ?></p>
</div>

<!-- CARDS -->
<div class="cards">
<?php foreach ($channels as $idx => $ch):
  $cache     = getCacheInfo($ch['slug']);
  $isEditing = ($editId === (int)$ch['id']);
  $isActive  = (bool)$ch['active'];
  $videoId   = $cache['videoid'];
  $ytUrl     = ytChannelUrl($ch['yt_channel']);
  $safeName  = htmlspecialchars($ch['name'] ?: $ch['slug']);
  $confirmMsg = 'Usunac ' . ($ch['name'] ?: $ch['slug']) . '?';
?>
<div class="card <?= $isActive ? '' : 'off' ?>">
  <div class="cbody">

    <div class="cname">
      <span class="cnum"><?= (int)$ch['position'] ?>.</span><?= $safeName ?>
    </div>

    <div class="badges">
      <?php if ($cache['status'] === 'ok'): ?>
        <span class="badge b-ok">
          <svg viewBox="0 0 8 8" fill="currentColor"><circle cx="4" cy="4" r="4"/></svg>
          OK &nbsp;<?= $cache['time'] ?>
        </span>
      <?php elseif ($cache['status'] === 'empty'): ?>
        <span class="badge b-empty">
          <svg viewBox="0 0 8 8" fill="currentColor"><path d="M4 1l3 6H1z"/></svg>
          Pusty
        </span>
      <?php else: ?>
        <span class="badge b-none">— Brak</span>
      <?php endif; ?>
      <span class="badge <?= $isActive ? 'b-on' : 'b-off' ?>">
        <svg viewBox="0 0 8 8" fill="currentColor"><circle cx="4" cy="4" r="4"/></svg>
        <?= $isActive ? 'ON' : 'OFF' ?>
      </span>
    </div>

    <div class="cmeta">
      <div class="mrow"><span class="mkey">URL</span><span class="mval"><?= htmlspecialchars($ch['slug']) ?></span></div>
      <div class="mrow"><span class="mkey">kw</span><span class="mval"><?= htmlspecialchars($ch['keyword']) ?></span></div>
      <div class="mrow"><span class="mkey">yt</span><span class="mval"><?= htmlspecialchars($ch['yt_channel']) ?></span></div>
    </div>

    <div class="ytlinks">
      <a class="ytlink" href="<?= htmlspecialchars($ytUrl) ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8zM9.7 15.5V8.5l6.3 3.5-6.3 3.5z"/></svg>
        Kanał YT
      </a>
      <?php if (!empty($videoId)): ?>
      <a class="ytlink" href="https://www.youtube.com/watch?v=<?= htmlspecialchars($videoId) ?>" target="_blank" rel="noopener">
        <span class="ytlink-dot"></span>
        Live · <?= htmlspecialchars($videoId) ?>
      </a>
      <?php endif; ?>
    </div>

    <?php
      $m3uUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST']
              . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/' . rawurlencode($ch['slug']) . '.m3u';
    ?>
    <div class="m3u-block">
      <span class="m3u-label">M3U</span>
      <span class="m3u-url" title="<?= htmlspecialchars($m3uUrl) ?>"><?= htmlspecialchars($m3uUrl) ?></span>
      <button class="m3u-copy" onclick="copyM3u(this, '<?= htmlspecialchars($m3uUrl, ENT_QUOTES) ?>')">
        <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        Kopiuj
      </button>
    </div>

    <div class="actions">

      <div class="arow">
        <?php if ($isEditing): ?>
          <a href="<?= $self ?>" class="abtn abtn-ghost"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Anuluj</a>
        <?php else: ?>
          <a href="<?= $self ?>?edit=<?= $ch['id'] ?>" class="abtn"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edytuj</a>
        <?php endif; ?>
        <form method="post"><input type="hidden" name="id" value="<?= $ch['id'] ?>"><button class="abtn" name="toggle"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64A9 9 0 1 1 5.64 6.64"/><line x1="12" y1="2" x2="12" y2="12"/></svg> <?= $isActive ? 'Wyłącz' : 'Włącz' ?></button></form>
      </div>
      <div class="arow">
        <form method="post"><input type="hidden" name="id" value="<?= $ch['id'] ?>"><button class="abtn abtn-warn" name="refresh_cache"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Odśwież</button></form>
        <form method="post" onsubmit="return confirm(this.dataset.msg)" data-msg="<?= htmlspecialchars($confirmMsg) ?>">
          <input type="hidden" name="id" value="<?= $ch['id'] ?>">
          <input type="hidden" name="slug" value="<?= htmlspecialchars($ch['slug']) ?>">
          <button class="abtn abtn-danger" name="delete"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg> Usuń</button>
        </form>
      </div>
    </div>

  </div>

  <?php if ($isEditing): ?>
  <div class="editbox">
    <form method="post">
      <input type="hidden" name="id" value="<?= $ch['id'] ?>">
      <label class="field-label">Nazwa</label>
      <input class="field-input" type="text" name="name" value="<?= htmlspecialchars($ch['name']) ?>" required>
      <label class="field-label">Nazwa URL</label>
      <input class="field-input" type="text" name="slug" value="<?= htmlspecialchars($ch['slug']) ?>" required>
      <label class="field-label">Kanał YouTube</label>
      <input class="field-input" type="text" name="yt_channel" value="<?= htmlspecialchars($ch['yt_channel']) ?>" required>
      <label class="field-label">Keyword</label>
      <input class="field-input" type="text" name="keyword" value="<?= htmlspecialchars($ch['keyword']) ?>" required>
      <div class="arow" style="margin-top:4px">
        <form method="post" style="flex:1;display:block"><button class="abtn abtn-ind" name="edit_save"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Zapisz</button></form>
        <a href="<?= $self ?>" class="abtn abtn-ghost"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Anuluj</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

</div>
<?php endforeach; ?>
</div>

</div>

<script>
function markCopied(btn) {
  const orig = btn.innerHTML;
  btn.classList.add('m3u-copied');
  btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><polyline points="20 6 9 17 4 12"/></svg> Skopiowano';
  setTimeout(() => {
    btn.classList.remove('m3u-copied');
    btn.innerHTML = orig;
  }, 2000);
}

function copyM3u(btn, url) {
  // Metoda 1: nowoczesna clipboard API (HTTPS lub localhost)
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url)
      .then(() => markCopied(btn))
      .catch(() => fallbackCopy(btn, url));
    return;
  }
  // Metoda 2: execCommand (HTTP, starsze przeglądarki)
  fallbackCopy(btn, url);
}

function fallbackCopy(btn, url) {
  try {
    const ta = document.createElement('textarea');
    ta.value = url;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
    document.body.appendChild(ta);
    ta.focus();
    ta.setSelectionRange(0, ta.value.length);
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    if (ok) {
      markCopied(btn);
    } else {
      // Metoda 3: prompt – ostateczność, działa zawsze
      window.prompt('Skopiuj link (Ctrl+C / Cmd+C):', url);
    }
  } catch(e) {
    window.prompt('Skopiuj link (Ctrl+C / Cmd+C):', url);
  }
}
</script>
<?php endif; /* widok kanałów */ ?>
</body>
</html>
