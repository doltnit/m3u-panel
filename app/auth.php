<?php

// Konfiguracja sesji – przed session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 86400); // 24h

session_start();

define('PANEL_USER', getenv('PANEL_USER') ?: 'admin');
define('PANEL_PASS', getenv('PANEL_PASS') ?: 'changeme');

// Sesja ważna 24h od ostatniej aktywności
define('SESSION_TTL', 86400);

function auth_check(): bool {
    if (empty($_SESSION['logged_in'])) return false;
    if (empty($_SESSION['last_active'])) return false;
    if (time() - $_SESSION['last_active'] > SESSION_TTL) {
        session_destroy();
        return false;
    }
    $_SESSION['last_active'] = time();
    return true;
}

function auth_login(string $user, string $pass): bool {
    if ($user === PANEL_USER && $pass === PANEL_PASS) {
        session_regenerate_id(true);
        $_SESSION['logged_in']   = true;
        $_SESSION['last_active'] = time();
        return true;
    }
    return false;
}

function auth_logout(): void {
    $_SESSION = [];
    session_destroy();
}
