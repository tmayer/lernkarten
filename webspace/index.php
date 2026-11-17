<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

header('Cache-Control: no-store');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Formular abgelaufen, bitte erneut versuchen.';
    } else {
        $error = attempt_login((string)($_POST['password'] ?? ''));
        if ($error === null) {
            header('Location: ' . cookie_path(), true, 303);
            exit;
        }
    }
}

$loggedIn = $error === null && is_logged_in();
$csrf = csrf_token(); // Sitzung vor jeder Ausgabe starten
$v = (string)max(filemtime(__DIR__ . '/assets/app.js'), filemtime(__DIR__ . '/assets/app.css'));
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Lernkarten">
<meta name="theme-color" content="#f6f5f2" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#16171a" media="(prefers-color-scheme: dark)">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="icon" type="image/png" href="assets/icon-192.png">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
<title>Lernkarten</title>
<?php if ($loggedIn): ?>
<meta name="csrf-token" content="<?= h($csrf) ?>">
<script src="assets/app.js?v=<?= $v ?>" defer></script>
<?php endif; ?>
</head>
<body>
<?php if ($loggedIn): ?>
<div id="app" class="app"><div class="loading">Lade …</div></div>
<div id="toast" class="toast" hidden></div>
<?php else: ?>
<main class="login">
  <form method="post" class="login-box" autocomplete="on">
    <img src="assets/icon-192.png" alt="" width="72" height="72" class="login-icon">
    <h1>Lernkarten</h1>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="text" name="username" value="lernkarten" autocomplete="username" hidden>
    <label for="pw">Passwort</label>
    <input id="pw" type="password" name="password" autocomplete="current-password" required autofocus>
    <?php if ($error): ?><p class="login-error"><?= h($error) ?></p><?php endif; ?>
    <button type="submit" class="btn btn-primary">Anmelden</button>
    <p class="login-hint">Du bleibst auf diesem Gerät ein Jahr angemeldet.</p>
  </form>
</main>
<?php endif; ?>
</body>
</html>
