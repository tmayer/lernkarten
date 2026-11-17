<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(mixed $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400, string $error = 'error'): never
{
    respond(['error' => $error, 'message' => $message], $code);
}

function body(): array
{
    static $b = null;
    if ($b === null) {
        $b = json_decode(file_get_contents('php://input') ?: '[]', true);
        $b = is_array($b) ? $b : [];
    }
    return $b;
}

function int_param(mixed $v): ?int
{
    return is_numeric($v) && (int)$v > 0 ? (int)$v : null;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    // Upload per iOS-Kurzbefehl: nur mit API-Token, nur diese eine Aktion
    $viaToken = $action === 'upload' && $method === 'POST' && api_token_valid();

    if (!$viaToken) {
        if (!is_logged_in()) {
            fail('Nicht angemeldet.', 401, 'auth');
        }
        if ($method !== 'GET' && !csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            fail('Sitzung abgelaufen, bitte erneut versuchen.', 403, 'csrf');
        }
    }

    $now = time();

    switch ("$method $action") {

        case 'GET csrf':
            respond(['csrf' => csrf_token()]);

        case 'GET overview':
            $tags = db()->query('SELECT t.id, t.name, COUNT(ct.card_id) AS cards
                FROM tags t JOIN card_tags ct ON ct.tag_id = t.id GROUP BY t.id ORDER BY t.name COLLATE NOCASE')->fetchAll();
            foreach ($tags as &$t) {
                $t['id'] = (int)$t['id'];
                $t['cards'] = (int)$t['cards'];
                $t['counts'] = queue_counts($t['id'], $now);
            }
            $st = db()->prepare('SELECT COUNT(*) FROM reviews WHERE reviewed_at >= ?');
            $st->execute([learning_day_start(learning_day($now))]);
            respond([
                'counts'        => queue_counts(null, $now),
                'tags'          => $tags,
                'total_cards'   => (int)db()->query('SELECT COUNT(*) FROM cards')->fetchColumn(),
                'reviews_today' => (int)$st->fetchColumn(),
            ]);

        case 'GET tags':
            respond(db()->query('SELECT id, name FROM tags ORDER BY name COLLATE NOCASE')->fetchAll());

        case 'GET next':
            $tagId = int_param($_GET['tag'] ?? null);
            $card = next_card($tagId, $now);
            $out = ['counts' => queue_counts($tagId, $now), 'card' => null, 'next_learning_due' => null];
            if ($card) {
                $opts = schedule_options($card, $now);
                $out['card'] = card_public($card) + [
                    'options' => array_map(fn($o) => ['due_at' => $o['due_at'], 'seconds' => $o['due_at'] - $now], $opts),
                ];
            } else {
                $out['next_learning_due'] = next_learning_due($tagId);
            }
            $out['can_undo'] = (bool)db()->query('SELECT 1 FROM reviews LIMIT 1')->fetchColumn();
            respond($out);

        case 'POST review':
            $cardId = int_param(body()['card_id'] ?? null);
            $rating = (int)(body()['rating'] ?? 0);
            if (!$cardId || $rating < 1 || $rating > 4) {
                fail('Ungültige Bewertung.');
            }
            $dur = isset(body()['duration_ms']) ? (int)body()['duration_ms'] : null;
            $n = apply_review($cardId, $rating, $dur, $now);
            respond(['ok' => true, 'due_at' => $n['due_at'], 'state' => $n['state']]);

        case 'POST undo':
            $id = undo_last_review();
            respond(['ok' => $id !== null, 'card_id' => $id]);

        case 'POST upload':
            $files = normalize_files($_FILES['files'] ?? $_FILES['file'] ?? null);
            if (!$files) {
                fail('Keine Datei empfangen.');
            }
            $tagNames = parse_tag_input($_POST['tags'] ?? '');
            $title = trim((string)($_POST['title'] ?? ''));
            $ids = [];
            $errors = [];
            foreach ($files as $f) {
                try {
                    $img = store_uploaded_image($f);
                    db()->prepare('INSERT INTO cards (filename, thumb, width, height, title, uploaded_at, due_at)
                                   VALUES (?,?,?,?,?,?,?)')
                        ->execute([$img['filename'], $img['thumb'], $img['width'], $img['height'], mb_substr($title, 0, 200), $now, $now]);
                    $id = (int)db()->lastInsertId();
                    set_card_tags($id, $tagNames);
                    $ids[] = $id;
                } catch (InvalidArgumentException $e) {
                    $errors[] = ($f['name'] ?? 'Datei') . ': ' . $e->getMessage();
                }
            }
            respond(['ok' => (bool)$ids, 'ids' => $ids, 'errors' => $errors], $ids ? 200 : 400);

        case 'GET cards':
            $where = ['1=1'];
            $p = [];
            if ($tagId = int_param($_GET['tag'] ?? null)) {
                $where[] = 'c.id IN (SELECT card_id FROM card_tags WHERE tag_id = ?)';
                $p[] = $tagId;
            }
            if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
                $where[] = '(c.title LIKE ? OR c.note LIKE ?)';
                $p[] = "%$q%";
                $p[] = "%$q%";
            }
            $order = match ($_GET['sort'] ?? 'uploaded') {
                'due'        => "c.suspended, CASE c.state WHEN 'new' THEN 1 ELSE 0 END, c.due_at",
                'lapses'     => 'c.lapses DESC, c.difficulty DESC',
                'difficulty' => 'c.difficulty IS NULL, c.difficulty DESC',
                default      => 'c.uploaded_at DESC, c.id DESC',
            };
            $st = db()->prepare('SELECT c.*, (SELECT GROUP_CONCAT(t.name, \'|\') FROM card_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.card_id = c.id) AS tag_names
                FROM cards c WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT 2000");
            $st->execute($p);
            respond(array_map('card_public', $st->fetchAll()));

        case 'GET card':
            $card = get_card_row(int_param($_GET['id'] ?? null) ?? 0) ?? fail('Karte nicht gefunden.', 404);
            $st = db()->prepare('SELECT id, reviewed_at, rating, state_before, state_after, interval_days, next_due_at, duration_ms, stability, difficulty, retrievability
                FROM reviews WHERE card_id = ? ORDER BY reviewed_at DESC, id DESC');
            $st->execute([$card['id']]);
            $r = null;
            if ($card['stability'] !== null && $card['last_reviewed_at'] !== null) {
                $r = Fsrs::retrievability(max(0, $now - (int)$card['last_reviewed_at']) / 86400, (float)$card['stability']);
            }
            respond(card_public($card) + [
                'tags'           => card_tags((int)$card['id']),
                'reviews'        => $st->fetchAll(),
                'retrievability' => $r,
            ]);

        case 'POST card_update':
            $id = int_param(body()['id'] ?? null);
            $card = get_card_row($id ?? 0) ?? fail('Karte nicht gefunden.', 404);
            $b = body();
            db()->prepare('UPDATE cards SET title = ?, note = ?, suspended = ? WHERE id = ?')->execute([
                mb_substr(trim((string)($b['title'] ?? $card['title'])), 0, 200),
                mb_substr((string)($b['note'] ?? $card['note']), 0, 5000),
                isset($b['suspended']) ? (int)(bool)$b['suspended'] : (int)$card['suspended'],
                $id,
            ]);
            if (isset($b['tags']) && is_array($b['tags'])) {
                set_card_tags($id, parse_tag_input($b['tags']));
            }
            respond(['ok' => true]);

        case 'POST card_reset':
            $id = int_param(body()['id'] ?? null) ?? fail('Ungültige Karte.');
            db()->prepare("UPDATE cards SET state='new', step=0, stability=NULL, difficulty=NULL, due_at=?, interval_days=0,
                           reps=0, lapses=0, last_reviewed_at=NULL WHERE id = ?")->execute([$now, $id]);
            db()->prepare('DELETE FROM reviews WHERE card_id = ?')->execute([$id]);
            respond(['ok' => true]);

        case 'POST card_delete':
            $card = get_card_row(int_param(body()['id'] ?? null) ?? 0) ?? fail('Karte nicht gefunden.', 404);
            db()->prepare('DELETE FROM cards WHERE id = ?')->execute([$card['id']]);
            delete_card_files($card);
            cleanup_tags();
            respond(['ok' => true]);

        case 'POST tag_rename':
            $id = int_param(body()['id'] ?? null) ?? fail('Ungültiger Tag.');
            $name = clean_tag_name((string)(body()['name'] ?? ''));
            if ($name === '') {
                fail('Name darf nicht leer sein.');
            }
            $st = db()->prepare('SELECT id FROM tags WHERE name = ? AND id <> ?');
            $st->execute([$name, $id]);
            if ($other = $st->fetchColumn()) {
                // Zusammenführen mit bestehendem Tag
                db()->prepare('INSERT OR IGNORE INTO card_tags (card_id, tag_id) SELECT card_id, ? FROM card_tags WHERE tag_id = ?')->execute([$other, $id]);
                db()->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
            } else {
                db()->prepare('UPDATE tags SET name = ? WHERE id = ?')->execute([$name, $id]);
            }
            respond(['ok' => true]);

        case 'POST tag_delete':
            $id = int_param(body()['id'] ?? null) ?? fail('Ungültiger Tag.');
            db()->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
            respond(['ok' => true]);

        case 'GET settings':
            respond([
                'desired_retention' => (float)setting('desired_retention'),
                'new_per_day'       => (int)setting('new_per_day'),
                'api_token'         => setting('api_token'),
                'upload_url'        => (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . strtok($_SERVER['REQUEST_URI'], '?') . '?action=upload',
                'sessions'          => (int)db()->query('SELECT COUNT(*) FROM auth_tokens WHERE expires_at > ' . $now)->fetchColumn(),
            ]);

        case 'POST settings':
            $b = body();
            if (isset($b['desired_retention'])) {
                set_setting('desired_retention', (string)min(0.97, max(0.75, (float)$b['desired_retention'])));
            }
            if (isset($b['new_per_day'])) {
                set_setting('new_per_day', (string)min(500, max(0, (int)$b['new_per_day'])));
            }
            if (!empty($b['regenerate_token'])) {
                set_setting('api_token', bin2hex(random_bytes(24)));
            }
            respond(['ok' => true]);

        case 'POST logout':
            logout(!empty(body()['everywhere']));
            respond(['ok' => true]);

        default:
            fail('Unbekannte Aktion.', 404);
    }
} catch (Throwable $e) {
    error_log('Lernkarten: ' . $e);
    fail('Serverfehler: ' . $e->getMessage(), 500);
}

// ---------- Hilfsfunktionen ----------

function card_public(array $c): array
{
    $out = [
        'id'               => (int)$c['id'],
        'title'            => $c['title'],
        'note'             => $c['note'],
        'width'            => $c['width'] !== null ? (int)$c['width'] : null,
        'height'           => $c['height'] !== null ? (int)$c['height'] : null,
        'uploaded_at'      => (int)$c['uploaded_at'],
        'state'            => $c['state'],
        'due_at'           => (int)$c['due_at'],
        'interval_days'    => (int)$c['interval_days'],
        'stability'        => $c['stability'] !== null ? round((float)$c['stability'], 2) : null,
        'difficulty'       => $c['difficulty'] !== null ? round((float)$c['difficulty'], 2) : null,
        'reps'             => (int)$c['reps'],
        'lapses'           => (int)$c['lapses'],
        'last_reviewed_at' => $c['last_reviewed_at'] !== null ? (int)$c['last_reviewed_at'] : null,
        'suspended'        => (bool)$c['suspended'],
        'image'            => 'bild.php?id=' . $c['id'],
        'thumb'            => 'bild.php?id=' . $c['id'] . '&thumb=1',
    ];
    if (array_key_exists('tag_names', $c)) {
        $out['tags'] = $c['tag_names'] ? explode('|', $c['tag_names']) : [];
    }
    return $out;
}

function card_tags(int $id): array
{
    $st = db()->prepare('SELECT t.id, t.name FROM tags t JOIN card_tags ct ON ct.tag_id = t.id WHERE ct.card_id = ? ORDER BY t.name COLLATE NOCASE');
    $st->execute([$id]);
    return $st->fetchAll();
}

function clean_tag_name(string $n): string
{
    return mb_substr(trim(preg_replace('/[\s|,]+/u', ' ', $n)), 0, 50);
}

function parse_tag_input(mixed $in): array
{
    if (is_string($in)) {
        $decoded = json_decode($in, true);
        $in = is_array($decoded) ? $decoded : explode(',', $in);
    }
    $names = [];
    foreach ((array)$in as $n) {
        $n = clean_tag_name((string)$n);
        if ($n !== '') {
            $names[mb_strtolower($n)] = $n;
        }
    }
    return array_values($names);
}

function set_card_tags(int $cardId, array $names): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM card_tags WHERE card_id = ?')->execute([$cardId]);
    foreach ($names as $name) {
        $pdo->prepare('INSERT OR IGNORE INTO tags (name) VALUES (?)')->execute([$name]);
        $st = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $st->execute([$name]);
        $pdo->prepare('INSERT OR IGNORE INTO card_tags (card_id, tag_id) VALUES (?, ?)')->execute([$cardId, $st->fetchColumn()]);
    }
    cleanup_tags();
}

function cleanup_tags(): void
{
    db()->exec('DELETE FROM tags WHERE id NOT IN (SELECT DISTINCT tag_id FROM card_tags)');
}

/** $_FILES['x'] (einzeln oder Liste) → Liste einzelner Dateien */
function normalize_files(?array $f): array
{
    if (!$f) {
        return [];
    }
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach (array_keys($f['name']) as $i) {
        $out[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}
