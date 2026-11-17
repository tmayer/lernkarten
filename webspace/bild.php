<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit;
}
session_write_close();

$id = (int)($_GET['id'] ?? 0);
$card = $id > 0 ? get_card_row($id) : null;
if (!$card) {
    http_response_code(404);
    exit;
}

$rel = (!empty($_GET['thumb']) && $card['thumb']) ? $card['thumb'] : $card['filename'];
$path = realpath(DATA_DIR . '/' . $rel);
$base = realpath(DATA_DIR);
if ($path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

$etag = '"' . md5($rel . filemtime($path)) . '"';
header('Cache-Control: private, max-age=31536000, immutable');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

$types = array_flip(IMAGE_TYPES);
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
readfile($path);
