<?php
declare(strict_types=1);

const LIB_DIR = __DIR__;
// Für die lokale Testumgebung lassen sich Konfiguration und Datenordner per Umgebungsvariable umlenken
define('DATA_DIR', getenv('LK_DATA_DIR') ?: __DIR__ . '/../data');
define('CONFIG_FILE', getenv('LK_CONFIG') ?: LIB_DIR . '/config.php');

if (!is_file(CONFIG_FILE)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Lernkarten ist noch nicht eingerichtet.\nBitte lokal `python3 werkzeuge/passwort_setzen.py` ausführen und lib/config.php hochladen.";
    exit;
}

function config(string $key, mixed $default = null): mixed
{
    static $cfg = null;
    $cfg ??= require CONFIG_FILE;
    return $cfg[$key] ?? $default;
}

date_default_timezone_set(config('timezone', 'Europe/Berlin'));

require LIB_DIR . '/db.php';
require LIB_DIR . '/fsrs.php';
require LIB_DIR . '/scheduler.php';
require LIB_DIR . '/auth.php';
require LIB_DIR . '/images.php';

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' blob: data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

send_security_headers();
