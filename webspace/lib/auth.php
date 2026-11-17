<?php
declare(strict_types=1);

const REMEMBER_COOKIE = 'lk_remember';
const REMEMBER_DAYS = 365;
const LOGIN_MAX_FAILS_PER_IP = 5;
const LOGIN_MAX_FAILS_TOTAL = 30;
const LOGIN_WINDOW = 900; // 15 min

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = DATA_DIR . '/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    session_save_path($dir);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', (string)(86400 * 7));
    session_name('lk_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => cookie_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function cookie_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $dir . '/';
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function is_logged_in(): bool
{
    start_session();
    if (!empty($_SESSION['auth'])) {
        return true;
    }
    // Angemeldet bleiben: Token aus dem Cookie prüfen
    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($token) || strlen($token) !== 64) {
        return false;
    }
    $now = time();
    $st = db()->prepare('SELECT id FROM auth_tokens WHERE token_hash = ? AND expires_at > ?');
    $st->execute([hash('sha256', $token), $now]);
    $id = $st->fetchColumn();
    if ($id === false) {
        clear_remember_cookie();
        return false;
    }
    db()->prepare('UPDATE auth_tokens SET last_used_at = ? WHERE id = ?')->execute([$now, $id]);
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['token_id'] = (int)$id;
    return true;
}

function verify_password(string $password): bool
{
    $stored = (string)config('password_hash', '');
    if (str_starts_with($stored, 'pbkdf2_sha256$')) {
        [, $iter, $salt, $hash] = explode('$', $stored, 4) + [null, null, null, null];
        if (!$iter || !$salt || !$hash) {
            return false;
        }
        return hash_equals($hash, hash_pbkdf2('sha256', $password, $salt, (int)$iter));
    }
    return $stored !== '' && password_verify($password, $stored);
}

/** @return string|null Fehlermeldung oder null bei Erfolg */
function attempt_login(string $password): ?string
{
    $pdo = db();
    $now = time();
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$now - 86400]);

    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?');
    $st->execute([client_ip(), $now - LOGIN_WINDOW]);
    $ipFails = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at > ?');
    $st->execute([$now - LOGIN_WINDOW]);
    $allFails = (int)$st->fetchColumn();
    if ($ipFails >= LOGIN_MAX_FAILS_PER_IP || $allFails >= LOGIN_MAX_FAILS_TOTAL) {
        return 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
    }

    if (!verify_password($password)) {
        $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([client_ip(), $now]);
        usleep(random_int(400000, 900000));
        return 'Falsches Passwort.';
    }

    $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
    start_session();
    session_regenerate_id(true);
    $_SESSION['auth'] = true;

    $token = bin2hex(random_bytes(32));
    $expires = $now + REMEMBER_DAYS * 86400;
    $pdo->prepare('INSERT INTO auth_tokens (token_hash, created_at, last_used_at, expires_at, user_agent) VALUES (?,?,?,?,?)')
        ->execute([hash('sha256', $token), $now, $now, $expires, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)]);
    $_SESSION['token_id'] = (int)$pdo->lastInsertId();
    setcookie(REMEMBER_COOKIE, $token, [
        'expires'  => $expires,
        'path'     => cookie_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return null;
}

function logout(bool $everywhere = false): void
{
    start_session();
    if ($everywhere) {
        db()->exec('DELETE FROM auth_tokens');
    } elseif (!empty($_SESSION['token_id'])) {
        db()->prepare('DELETE FROM auth_tokens WHERE id = ?')->execute([$_SESSION['token_id']]);
    }
    clear_remember_cookie();
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', ['expires' => 1, 'path' => cookie_path()]);
}

function clear_remember_cookie(): void
{
    setcookie(REMEMBER_COOKIE, '', ['expires' => 1, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

function csrf_token(): string
{
    start_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_valid(?string $token): bool
{
    start_session();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Zugang über den Kurzbefehl-Token (nur für Uploads). */
function api_token_valid(): bool
{
    $given = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if ($given === '' && preg_match('/^Bearer\s+(\S+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
        $given = $m[1];
    }
    return is_string($given) && strlen($given) >= 32 && hash_equals(setting('api_token'), $given);
}
