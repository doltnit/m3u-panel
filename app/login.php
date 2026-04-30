<?php
require_once __DIR__ . '/auth.php';

// Jeśli już zalogowany – przekieruj do panelu
if (auth_check()) {
    header('Location: panel.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    if (auth_login($user, $pass)) {
        header('Location: panel.php');
        exit;
    }

    // Krótkie opóźnienie żeby utrudnić brute-force
    sleep(1);
    $error = 'Nieprawidłowy login lub hasło.';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>M3U Panel – Logowanie</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:      #f0f2f6;
  --surface: #ffffff;
  --border:  rgba(0,0,0,.08);
  --border2: rgba(0,0,0,.13);
  --ind:     #3730a3;
  --ind-mid: #4f46e5;
  --ind-l:   #eef2ff;
  --ind-b:   #c7d2fe;
  --ink:     #0d1117;
  --ink2:    #3d4a5c;
  --ink3:    #8693a4;
  --red-l:   #fef2f2;
  --red-b:   #fca5a5;
  --sans:    'DM Sans', -apple-system, sans-serif;
  --sh:      0 4px 24px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.04);
  --r:       14px;
  --r-sm:    9px;
}

html, body {
  font-family: var(--sans);
  background: var(--bg);
  color: var(--ink);
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  -webkit-font-smoothing: antialiased;
  -webkit-text-size-adjust: none;
  text-size-adjust: none;
}

.login-wrap {
  width: 100%;
  max-width: 400px;
  padding: 20px;
}

/* Logo / brand */
.brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  margin-bottom: 32px;
}
.brand-icon {
  width: 56px; height: 56px;
  background: linear-gradient(135deg, var(--ind-mid), var(--ind));
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 16px rgba(79,70,229,.35);
}
.brand-icon svg { width: 26px; height: 26px; fill: #fff; }
.brand-name {
  font-size: 22px;
  font-weight: 800;
  color: var(--ink);
  letter-spacing: -.4px;
}
.brand-sub {
  font-size: 13px;
  color: var(--ink3);
  margin-top: -8px;
}

/* Card */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 28px 24px;
  box-shadow: var(--sh);
}

/* Error */
.error-msg {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--red-l);
  border: 1px solid var(--red-b);
  color: #991b1b;
  border-radius: var(--r-sm);
  padding: 11px 14px;
  font-size: 14px;
  margin-bottom: 20px;
}
.error-msg svg { flex-shrink: 0; width: 16px; height: 16px;
  stroke: currentColor; fill: none; stroke-width: 2;
  stroke-linecap: round; stroke-linejoin: round; }

/* Form */
.field { margin-bottom: 16px; }
.field label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--ink3);
  margin-bottom: 6px;
}
.field input {
  display: block;
  width: 100%;
  font-family: var(--sans);
  font-size: 16px;
  padding: 12px 14px;
  border: 1.5px solid var(--border2);
  border-radius: var(--r-sm);
  background: var(--bg);
  color: var(--ink);
  outline: none;
  -webkit-appearance: none;
  transition: border-color .15s, box-shadow .15s;
}
.field input:focus {
  border-color: var(--ind-mid);
  box-shadow: 0 0 0 3px rgba(79,70,229,.12);
  background: #fff;
}

.btn-login {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  font-family: var(--sans);
  font-size: 16px;
  font-weight: 700;
  padding: 13px;
  margin-top: 8px;
  background: linear-gradient(135deg, var(--ind-mid), var(--ind));
  color: #fff;
  border: none;
  border-radius: var(--r-sm);
  cursor: pointer;
  box-shadow: 0 2px 12px rgba(79,70,229,.3);
  transition: box-shadow .15s, transform .1s;
}
.btn-login:active { transform: scale(.98); }
.btn-login svg { width: 16px; height: 16px;
  stroke: currentColor; fill: none; stroke-width: 2.5;
  stroke-linecap: round; stroke-linejoin: round; }
</style>
</head>
<body>

<div class="login-wrap">
  <div class="brand">
    <div class="brand-icon">
      <svg viewBox="0 0 24 24"><path d="M21 3H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5v2H6v2h12v-2h-2v-2h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 14H3V5h18v12z"/></svg>
    </div>
    <div>
      <div class="brand-name">M3U Panel</div>
      <div class="brand-sub">Zaloguj się, aby kontynuować</div>
    </div>
  </div>

  <div class="card">
    <?php if ($error): ?>
    <div class="error-msg">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <div class="field">
        <label>Login</label>
        <input type="text" name="username" placeholder="admin"
               autocomplete="username" required autofocus>
      </div>
      <div class="field">
        <label>Hasło</label>
        <input type="password" name="password" placeholder="••••••••"
               autocomplete="current-password" required>
      </div>
      <button class="btn-login" type="submit">
        <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Zaloguj się
      </button>
    </form>
  </div>
</div>

</body>
</html>
