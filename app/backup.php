<?php
require_once __DIR__ . '/auth.php';

if (!auth_check()) {
    header('Location: login.php');
    exit;
}

date_default_timezone_set('Europe/Warsaw');

define('DB_PATH',          __DIR__ . '/channels.db');
define('BACKUP_DIR',       __DIR__ . '/backups');
define('HOST_BACKUP_DIR',  '/var/backups/m3u-panel');
define('MAX_BACKUPS',      7);

// Utwórz katalogi jeśli nie istnieją
foreach ([BACKUP_DIR, HOST_BACKUP_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

function getBackups(): array {
    $files = glob(BACKUP_DIR . '/channels_backup_*.db') ?: [];
    rsort($files); // najnowsze pierwsze
    return $files;
}

function createBackup(): array {
    $ts       = date('Ymd_His');
    $filename = "channels_backup_{$ts}.db";

    // Kopia 1: lokalny backup dir (w volumenie)
    $localPath = BACKUP_DIR . '/' . $filename;
    if (!copy(DB_PATH, $localPath)) {
        return ['ok' => false, 'msg' => 'Nie udało się skopiować bazy do katalogu backupów.'];
    }

    // Kopia 2: katalog na hoście (poza kontenerem)
    $hostPath = HOST_BACKUP_DIR . '/' . $filename;
    @copy(DB_PATH, $hostPath); // nieblokujące – host dir może nie być dostępny

    // Rotacja: zachowaj tylko MAX_BACKUPS najnowszych
    $files = sorted_backups();
    $deleted = 0;
    foreach (array_slice($files, MAX_BACKUPS) as $old) {
        @unlink($old);
        @unlink(HOST_BACKUP_DIR . '/' . basename($old));
        $deleted++;
    }

    $size = round(filesize($localPath) / 1024, 1);
    $hostOk = file_exists($hostPath);
    $msg = "Backup utworzony: {$filename} ({$size} KB)";
    if ($hostOk) $msg .= " · skopiowany na hosta";
    if ($deleted > 0) $msg .= " · usunięto {$deleted} starych";

    return ['ok' => true, 'msg' => $msg, 'filename' => $filename];
}

function sorted_backups(): array {
    $files = glob(BACKUP_DIR . '/channels_backup_*.db') ?: [];
    rsort($files);
    return $files;
}

function validateBackup(string $path): array {
    if (!file_exists($path)) return ['ok' => false, 'msg' => 'Plik backupu nie istnieje.'];
    if (filesize($path) === 0) return ['ok' => false, 'msg' => 'Plik backupu jest pusty.'];

    try {
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('channels', $tables)) {
            return ['ok' => false, 'msg' => 'Backup nie zawiera tabeli channels – nieprawidłowy plik.'];
        }
        $count = $db->query("SELECT COUNT(*) FROM channels")->fetchColumn();
        return ['ok' => true, 'msg' => "Backup prawidłowy ({$count} kanałów).", 'count' => $count];
    } catch (Exception $e) {
        return ['ok' => false, 'msg' => 'Błąd walidacji: ' . $e->getMessage()];
    }
}

function restoreBackup(string $filename): array {
    $backupPath = BACKUP_DIR . '/' . basename($filename);

    // Walidacja przed przywróceniem
    $valid = validateBackup($backupPath);
    if (!$valid['ok']) return $valid;

    // Zabezpieczenie: backup aktualnej bazy przed przywróceniem
    $safeTs   = date('Ymd_His');
    $safeName = "channels_backup_{$safeTs}_pre_restore.db";
    $safePath = BACKUP_DIR . '/' . $safeName;
    if (!copy(DB_PATH, $safePath)) {
        return ['ok' => false, 'msg' => 'Nie udało się zabezpieczyć aktualnej bazy przed przywróceniem.'];
    }
    @copy(DB_PATH, HOST_BACKUP_DIR . '/' . $safeName);

    // Przywróć
    if (!copy($backupPath, DB_PATH)) {
        // Próba cofnięcia
        copy($safePath, DB_PATH);
        return ['ok' => false, 'msg' => 'Nie udało się przywrócić backupu. Baza nie została zmieniona.'];
    }

    return [
        'ok'  => true,
        'msg' => "Przywrócono backup: {$filename}. Poprzednia baza zachowana jako: {$safeName}",
    ];
}

// ── Obsługa akcji ──────────────────────────────────────────────────────────

$flash = ['ok' => null, 'msg' => ''];

// Pobierz backup
if (isset($_GET['download'])) {
    $file = BACKUP_DIR . '/' . basename($_GET['download']);
    if (file_exists($file) && str_starts_with(basename($file), 'channels_backup_')) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    $flash = ['ok' => false, 'msg' => 'Plik nie istnieje.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            case 'create':
                $flash = createBackup();
                break;

            case 'restore':
                $filename = basename($_POST['filename'] ?? '');
                if (empty($filename)) {
                    $flash = ['ok' => false, 'msg' => 'Nie podano pliku do przywrócenia.'];
                } else {
                    $flash = restoreBackup($filename);
                }
                break;

            case 'delete':
                $filename = basename($_POST['filename'] ?? '');
                $path = BACKUP_DIR . '/' . $filename;
                if (file_exists($path) && str_starts_with($filename, 'channels_backup_')) {
                    unlink($path);
                    @unlink(HOST_BACKUP_DIR . '/' . $filename);
                    $flash = ['ok' => true, 'msg' => "Usunięto backup: {$filename}"];
                } else {
                    $flash = ['ok' => false, 'msg' => 'Plik nie istnieje lub niedozwolona nazwa.'];
                }
                break;

            case 'upload_restore':
                if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                    $tmp  = $_FILES['backup_file']['tmp_name'];
                    $name = basename($_FILES['backup_file']['name']);

                    // Waliduj przesłany plik
                    $valid = validateBackup($tmp);
                    if (!$valid['ok']) {
                        $flash = $valid;
                        break;
                    }

                    // Zapisz jako backup z timestampem
                    $ts       = date('Ymd_His');
                    $filename = "channels_backup_{$ts}_uploaded.db";
                    $destPath = BACKUP_DIR . '/' . $filename;
                    move_uploaded_file($tmp, $destPath);
                    @copy($destPath, HOST_BACKUP_DIR . '/' . $filename);

                    // Przywróć
                    $flash = restoreBackup($filename);
                } else {
                    $flash = ['ok' => false, 'msg' => 'Błąd przesyłania pliku.'];
                }
                break;
        }
    }

    header('Location: backup.php?msg=' . urlencode($flash['msg']) . '&ok=' . ($flash['ok'] ? '1' : '0'));
    exit;
}

if (isset($_GET['msg'])) {
    $flash = ['ok' => $_GET['ok'] === '1', 'msg' => $_GET['msg']];
}

$backups = getBackups();
$self    = htmlspecialchars($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>M3U Panel – Backup</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --ink: #0d1117; --ink2: #3d4a5c; --ink3: #8693a4; --ink4: #b8c3cf;
  --bg: #f0f2f6; --surface: #fff; --surface2: #f7f9fc;
  --border: rgba(0,0,0,.07); --border2: rgba(0,0,0,.12);
  --ind: #3730a3; --ind-mid: #4f46e5; --ind-l: #eef2ff; --ind-b: #c7d2fe;
  --em: #059669; --em-l: #ecfdf5; --em-b: #a7f3d0;
  --danger: #dc2626; --danger-l: #fef2f2; --danger-b: #fecaca;
  --warn: #d97706; --warn-l: #fffbeb; --warn-b: #fde68a;
  --gray-l: #f1f5f9; --gray-b: #cbd5e1;
  --sh: 0 4px 16px rgba(0,0,0,.06), 0 1px 4px rgba(0,0,0,.04);
  --r: 14px; --r-sm: 9px;
  --sans: 'DM Sans', -apple-system, sans-serif;
  --mono: 'DM Mono', monospace;
}
html, body { font-family: var(--sans); background: var(--bg); color: var(--ink);
             font-size: 18px; line-height: 1.5; -webkit-text-size-adjust: none;
             text-size-adjust: none; -webkit-font-smoothing: antialiased; }

.topbar {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  box-shadow: var(--sh);
  padding: 0 20px;
  height: 58px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 100;
}
.topbar-brand { display: flex; align-items: center; gap: 10px; }
.topbar-logo {
  width: 32px; height: 32px;
  background: linear-gradient(135deg, var(--ind-mid), var(--ind));
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 8px rgba(79,70,229,.3);
  flex-shrink: 0;
}
.topbar-logo svg { width: 16px; height: 16px; fill: #fff; }
.topbar-name { font-size: 17px; font-weight: 700; color: var(--ink); letter-spacing: -.3px; }
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
.topbar-time {
  font-family: var(--mono); font-size: 12px; color: var(--ink3);
  background: var(--surface2); border: 1px solid var(--border2);
  padding: 5px 11px; border-radius: 20px; letter-spacing: .02em;
}
.topbar-logout {
  display: flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: 8px; color: var(--ink3);
  text-decoration: none; transition: background .15s, color .15s; flex-shrink: 0;
}
.topbar-logout:hover { background: var(--danger-l); color: var(--danger); }

/* Page */
.page { max-width: 860px; margin: 0 auto; padding: 24px 16px 80px; }

/* Flash */
.flash { display: flex; gap: 10px; align-items: flex-start; padding: 13px 16px;
         border-radius: var(--r-sm); margin-bottom: 20px; font-size: 14px;
         word-break: break-word; border: 1px solid; }
.flash svg { flex-shrink: 0; width: 16px; height: 16px; stroke: currentColor;
             fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.flash-ok  { background: var(--em-l);     border-color: var(--em-b);     color: #065f46; }
.flash-err { background: var(--danger-l); border-color: var(--danger-b); color: #991b1b; }

/* Cards */
.card { background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--r); box-shadow: var(--sh); margin-bottom: 16px; }
.card-head { padding: 16px 20px; border-bottom: 1px solid var(--border);
             display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.card-title { font-size: 15px; font-weight: 700; flex: 1; }
.card-body { padding: 16px 20px; }

/* Buttons */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px;
       padding: 9px 16px; border: 1.5px solid transparent; border-radius: var(--r-sm);
       cursor: pointer; font-family: var(--sans); font-size: 14px; font-weight: 600;
       text-decoration: none; transition: all .14s; white-space: nowrap;
       -webkit-appearance: none; line-height: 1; }
.btn:active { opacity: .8; }
.btn svg { width: 14px; height: 14px; stroke: currentColor; fill: none;
           stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
.btn-primary { background: linear-gradient(135deg, var(--ind-mid), var(--ind));
               color: #fff; box-shadow: 0 2px 8px rgba(79,70,229,.25); }
.btn-ok      { background: var(--em-l);     color: #065f46; border-color: var(--em-b); }
.btn-danger  { background: var(--danger-l); color: #991b1b; border-color: var(--danger-b); }
.btn-gray    { background: var(--gray-l);   color: var(--ink2); border-color: var(--gray-b); }
.btn-warn    { background: var(--warn-l);   color: #92400e; border-color: var(--warn-b); }

/* Backup list */
.backup-list { display: flex; flex-direction: column; gap: 8px; }
.backup-item { display: flex; align-items: center; gap: 10px; padding: 12px 14px;
               background: var(--surface2); border: 1px solid var(--border);
               border-radius: var(--r-sm); flex-wrap: wrap; }
.backup-icon { width: 32px; height: 32px; background: var(--ind-l); border-radius: 8px;
               display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.backup-icon svg { width: 16px; height: 16px; stroke: var(--ind-mid); fill: none;
                   stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.backup-info { flex: 1; min-width: 0; }
.backup-name { font-family: var(--mono); font-size: 13px; font-weight: 500;
               color: var(--ink); word-break: break-all; }
.backup-meta { font-size: 12px; color: var(--ink3); margin-top: 2px; }
.backup-actions { display: flex; gap: 6px; flex-shrink: 0; }

/* Upload form */
.upload-area { border: 2px dashed var(--border2); border-radius: var(--r-sm);
               padding: 24px 20px; text-align: center; cursor: pointer;
               transition: border-color .2s, background .2s;
               display: block; width: 100%; overflow: hidden; }
.upload-area:hover { border-color: var(--ind-mid); background: var(--ind-l); }
.upload-area input { display: none; }
.upload-area-label { font-size: 14px; color: var(--ink3); cursor: pointer;
                     display: block; pointer-events: none; }
.upload-area-label strong { color: var(--ind-mid); }

.slabel { font-size: 11px; font-weight: 700; text-transform: uppercase;
          letter-spacing: .1em; color: var(--ink3); margin-bottom: 10px; display: block; }

@media (min-width: 600px) {
  .page { padding: 28px 24px 80px; }
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
    <a href="panel.php" class="nav-tab">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Kanały
    </a>
    <a href="panel.php?view=logs" class="nav-tab">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      Logi
    </a>
    <a href="backup.php" class="nav-tab active">
      <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Backup
    </a>
  </nav>
  <div style="display:flex;align-items:center;gap:10px">
    <span class="topbar-time"><?= date('d.m.Y  H:i') ?></span>
    <a href="panel.php?logout=1" class="topbar-logout" title="Wyloguj się">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </a>
  </div>
</div>

<div class="page">

<?php if ($flash['ok'] !== null): ?>
<div class="flash <?= $flash['ok'] ? 'flash-ok' : 'flash-err' ?>">
  <?php if ($flash['ok']): ?>
  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  <?php else: ?>
  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <?php endif; ?>
  <span><?= htmlspecialchars($flash['msg']) ?></span>
</div>
<?php endif; ?>

<!-- UTWÓRZ BACKUP -->
<div class="card">
  <div class="card-head">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--ind-mid)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
    <span class="card-title">Utwórz backup</span>
    <form method="post" style="display:inline">
      <input type="hidden" name="action" value="create">
      <button class="btn btn-primary" type="submit">
        <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Utwórz teraz
      </button>
    </form>
  </div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--ink3)">
      Backup tworzony jest automatycznie przez cron codziennie o 2:00.<br>
      Przechowywanych jest maksymalnie <strong><?= MAX_BACKUPS ?></strong> ostatnich backupów.<br>
      Każdy backup zapisywany jest w dwóch miejscach: w kontenerze i na hoście (<code><?= HOST_BACKUP_DIR ?></code>).
    </p>
  </div>
</div>

<!-- LISTA BACKUPÓW -->
<div class="card">
  <div class="card-head">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--ind-mid)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <span class="card-title">Dostępne backupy <span style="font-weight:400;color:var(--ink3)">(<?= count($backups) ?>)</span></span>
  </div>
  <div class="card-body">
    <?php if (empty($backups)): ?>
    <p style="font-size:14px;color:var(--ink3);text-align:center;padding:20px 0">
      Brak backupów. Utwórz pierwszy backup powyżej.
    </p>
    <?php else: ?>
    <div class="backup-list">
      <?php foreach ($backups as $file):
        $fname   = basename($file);
        $size    = round(filesize($file) / 1024, 1);
        $mtime   = date('d.m.Y H:i', filemtime($file));
        $isPreRestore = str_contains($fname, 'pre_restore');
      ?>
      <div class="backup-item">
        <div class="backup-icon">
          <svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
        </div>
        <div class="backup-info">
          <div class="backup-name">
            <?= htmlspecialchars($fname) ?>
            <?php if ($isPreRestore): ?>
            <span style="font-size:11px;background:var(--warn-l);color:#92400e;border:1px solid var(--warn-b);padding:1px 6px;border-radius:10px;margin-left:4px">przed przywróceniem</span>
            <?php endif; ?>
          </div>
          <div class="backup-meta"><?= $mtime ?> · <?= $size ?> KB</div>
        </div>
        <div class="backup-actions">
          <a href="<?= $self ?>?download=<?= urlencode($fname) ?>" class="btn btn-gray" title="Pobierz">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          </a>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Przywrócić backup <?= htmlspecialchars(addslashes($fname)) ?>?\n\nAktualna baza zostanie zastąpiona. Zostanie automatycznie utworzony backup obecnej bazy.')">
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="filename" value="<?= htmlspecialchars($fname) ?>">
            <button class="btn btn-ok" type="submit" title="Przywróć">
              <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
            </button>
          </form>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Usunąć backup <?= htmlspecialchars(addslashes($fname)) ?>?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="filename" value="<?= htmlspecialchars($fname) ?>">
            <button class="btn btn-danger" type="submit" title="Usuń">
              <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            </button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- WGRAJ I PRZYWRÓĆ -->
<div class="card">
  <div class="card-head">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--ind-mid)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
    <span class="card-title">Wgraj i przywróć backup</span>
  </div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--ink3);margin-bottom:14px">
      Wgraj plik <code>.db</code> z innego serwera lub archiwum. Plik zostanie zwalidowany przed przywróceniem.
    </p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_restore">
      <label class="upload-area" id="upload-area">
        <input type="file" name="backup_file" accept=".db" id="upload-input"
               onchange="document.getElementById('upload-label').textContent = this.files[0]?.name || 'Wybierz plik .db'">
        <div class="upload-area-label">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 8px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <span id="upload-label">Kliknij lub przeciągnij plik <strong>.db</strong></span>
        </div>
      </label>
      <button class="btn btn-warn" type="submit" style="margin-top:12px;width:100%">
        <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
        Wgraj i przywróć
      </button>
    </form>
  </div>
</div>

</div>
</body>
</html>
