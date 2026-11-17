<?php
declare(strict_types=1);

const LEARNING_STEPS   = [60, 600]; // Sekunden: 1 min, 10 min
const RELEARNING_STEPS = [600];     // 10 min
const DAY_ROLLOVER_HOUR = 4;        // ein Lerntag beginnt um 4 Uhr
const LEARN_AHEAD_SECONDS = 1200;   // Lernkarten bis 20 min früher zeigen, wenn sonst nichts ansteht
const MAX_INTERVAL_DAYS = 36500;

/** Fortlaufende Nummer des Lerntags (Tageswechsel um 4 Uhr Ortszeit). */
function learning_day(int $ts): int
{
    $local = (new DateTimeImmutable('@' . $ts))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->modify('-' . DAY_ROLLOVER_HOUR . ' hours');
    return intdiv((new DateTimeImmutable($local->format('Y-m-d'), new DateTimeZone('UTC')))->getTimestamp(), 86400);
}

/** Zeitstempel, an dem Lerntag $day beginnt. */
function learning_day_start(int $day): int
{
    $date = gmdate('Y-m-d', $day * 86400);
    return (new DateTimeImmutable($date . ' ' . DAY_ROLLOVER_HOUR . ':00:00'))->getTimestamp();
}

function desired_retention(): float
{
    return min(0.99, max(0.7, (float)setting('desired_retention')));
}

/**
 * Berechnet für alle vier Bewertungen den neuen Kartenzustand.
 * @return array<int, array> rating => neue Kartenfelder (+ 'retrievability', 'elapsed_days')
 */
function schedule_options(array $card, int $now): array
{
    $ret = desired_retention();
    $today = learning_day($now);
    $elapsed = $card['last_reviewed_at'] !== null ? max(0, $today - learning_day((int)$card['last_reviewed_at'])) : 0;

    $out = [];
    foreach ([1, 2, 3, 4] as $g) {
        $out[$g] = schedule_one($card, $g, $now, $elapsed, $ret);
    }

    // Abstände in der Reihenfolge Schwer ≤ Gut < Einfach halten
    $isReview = fn(int $g) => $out[$g]['state'] === 'review';
    if ($isReview(2) && $isReview(3)) {
        $out[2]['interval_days'] = min($out[2]['interval_days'], $out[3]['interval_days']);
        $out[3]['interval_days'] = max($out[3]['interval_days'], $out[2]['interval_days'] + 1);
    }
    if ($isReview(3) && $isReview(4)) {
        $out[4]['interval_days'] = max($out[4]['interval_days'], $out[3]['interval_days'] + 1);
    }
    foreach ($out as $g => $c) {
        if ($c['state'] === 'review') {
            $out[$g]['due_at'] = learning_day_start($today + $c['interval_days']);
        }
    }
    return $out;
}

function schedule_one(array $card, int $g, int $now, int $elapsed, float $ret): array
{
    $state = $card['state'];
    $s = $card['stability'] !== null ? (float)$card['stability'] : null;
    $d = $card['difficulty'] !== null ? (float)$card['difficulty'] : null;
    $r = null;

    if ($state === 'new' || $s === null || $d === null) {
        $s = Fsrs::initStability($g);
        $d = Fsrs::initDifficulty($g);
    } else {
        if ($elapsed === 0) {
            $s = Fsrs::shortTermStability($s, $g);
        } else {
            $r = Fsrs::retrievability($elapsed, $s);
            $s = $g === 1 ? Fsrs::forgetStability($d, $s, $r) : Fsrs::recallStability($d, $s, $r, $g);
        }
        $d = Fsrs::nextDifficulty($d, $g);
    }

    $n = [
        'state'            => $state,
        'step'             => (int)$card['step'],
        'stability'        => $s,
        'difficulty'       => $d,
        'due_at'           => $now,
        'interval_days'    => 0,
        'reps'             => (int)$card['reps'] + 1,
        'lapses'           => (int)$card['lapses'],
        'last_reviewed_at' => $now,
        'retrievability'   => $r,
        'elapsed_days'     => $elapsed,
    ];

    $graduate = function () use (&$n, $s, $ret): array {
        $n['state'] = 'review';
        $n['step'] = 0;
        $n['interval_days'] = (int)min(MAX_INTERVAL_DAYS, max(1, round(Fsrs::interval($s, $ret))));
        return $n;
    };
    $learn = function (string $newState, int $step, int $delay) use (&$n, $now): array {
        $n['state'] = $newState;
        $n['step'] = $step;
        $n['due_at'] = $now + $delay;
        $n['interval_days'] = 0;
        return $n;
    };

    if ($state === 'review') {
        if ($g === 1) {
            $n['lapses']++;
            return RELEARNING_STEPS ? $learn('relearning', 0, RELEARNING_STEPS[0]) : $graduate();
        }
        return $graduate();
    }

    // new, learning, relearning
    $steps = $state === 'relearning' ? RELEARNING_STEPS : LEARNING_STEPS;
    $phase = $state === 'relearning' ? 'relearning' : 'learning';
    $step = $state === 'new' ? 0 : min((int)$card['step'], count($steps) - 1);

    return match ($g) {
        1 => $learn($phase, 0, $steps[0]),
        2 => $learn($phase, $step, ($step === 0 && count($steps) > 1)
            ? intdiv($steps[0] + $steps[1], 2)
            : (int)round($steps[$step] * 1.5)),
        3 => $step + 1 < count($steps) ? $learn($phase, $step + 1, $steps[$step + 1]) : $graduate(),
        4 => $graduate(),
    };
}

/** Speichert eine Bewertung und gibt den neuen Kartenzustand zurück. */
function apply_review(int $cardId, int $rating, ?int $durationMs, int $now): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $card = get_card_row($cardId);
        if (!$card) {
            throw new RuntimeException('Karte nicht gefunden');
        }
        $n = schedule_options($card, $now)[$rating];
        $prev = array_intersect_key($card, array_flip(
            ['state', 'step', 'stability', 'difficulty', 'due_at', 'interval_days', 'reps', 'lapses', 'last_reviewed_at']
        ));
        $pdo->prepare('UPDATE cards SET state=?, step=?, stability=?, difficulty=?, due_at=?, interval_days=?,
                       reps=?, lapses=?, last_reviewed_at=? WHERE id=?')
            ->execute([$n['state'], $n['step'], $n['stability'], $n['difficulty'], $n['due_at'], $n['interval_days'],
                $n['reps'], $n['lapses'], $n['last_reviewed_at'], $cardId]);
        $pdo->prepare('INSERT INTO reviews (card_id, reviewed_at, rating, state_before, state_after, elapsed_days,
                       stability, difficulty, retrievability, next_due_at, interval_days, duration_ms, prev_card)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cardId, $now, $rating, $card['state'], $n['state'], $n['elapsed_days'], $n['stability'],
                $n['difficulty'], $n['retrievability'], $n['due_at'], $n['interval_days'],
                $durationMs !== null ? max(0, min($durationMs, 3600000)) : null, json_encode($prev)]);
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Macht die zuletzt gespeicherte Bewertung rückgängig. Gibt die Karten-ID zurück. */
function undo_last_review(): ?int
{
    $pdo = db();
    $pdo->beginTransaction();
    $rev = $pdo->query('SELECT * FROM reviews ORDER BY id DESC LIMIT 1')->fetch();
    if (!$rev) {
        $pdo->rollBack();
        return null;
    }
    $p = json_decode($rev['prev_card'], true);
    $pdo->prepare('UPDATE cards SET state=?, step=?, stability=?, difficulty=?, due_at=?, interval_days=?,
                   reps=?, lapses=?, last_reviewed_at=? WHERE id=?')
        ->execute([$p['state'], $p['step'], $p['stability'], $p['difficulty'], $p['due_at'], $p['interval_days'],
            $p['reps'], $p['lapses'], $p['last_reviewed_at'], $rev['card_id']]);
    $pdo->prepare('DELETE FROM reviews WHERE id = ?')->execute([$rev['id']]);
    $pdo->commit();
    return (int)$rev['card_id'];
}

function get_card_row(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM cards WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// ---------- Warteschlange ----------

function tag_clause(?int $tagId, array &$params): string
{
    if (!$tagId) {
        return '';
    }
    $params[] = $tagId;
    return ' AND id IN (SELECT card_id FROM card_tags WHERE tag_id = ?)';
}

function new_cards_left_today(int $now): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM reviews WHERE state_before = 'new' AND reviewed_at >= ?");
    $st->execute([learning_day_start(learning_day($now))]);
    return max(0, (int)setting('new_per_day') - (int)$st->fetchColumn());
}

/** Anzahl fälliger Karten (optional nur ein Tag). */
function queue_counts(?int $tagId, int $now): array
{
    $p = [$now, $now];
    $tag = tag_clause($tagId, $p);
    $st = db()->prepare("SELECT
            SUM(state IN ('learning','relearning') AND due_at <= ?) AS learning,
            SUM(state = 'review' AND due_at <= ?) AS review,
            SUM(state = 'new') AS new
        FROM cards WHERE suspended = 0$tag");
    $st->execute($p);
    $r = $st->fetch();
    return [
        'learning' => (int)$r['learning'],
        'review'   => (int)$r['review'],
        'new'      => min((int)$r['new'], new_cards_left_today($now)),
    ];
}

function next_card(?int $tagId, int $now): ?array
{
    $queries = [
        ["state IN ('learning','relearning') AND due_at <= ?", [$now], 'due_at, id'],
        ["state = 'review' AND due_at <= ?", [$now], 'due_at, id'],
    ];
    if (new_cards_left_today($now) > 0) {
        $queries[] = ["state = 'new'", [], 'uploaded_at, id'];
    }
    $queries[] = ["state IN ('learning','relearning') AND due_at <= ?", [$now + LEARN_AHEAD_SECONDS], 'due_at, id'];

    foreach ($queries as [$where, $params, $order]) {
        $tag = tag_clause($tagId, $params);
        $st = db()->prepare("SELECT * FROM cards WHERE suspended = 0 AND $where$tag ORDER BY $order LIMIT 1");
        $st->execute($params);
        if ($card = $st->fetch()) {
            return $card;
        }
    }
    return null;
}

/** Wann die nächste Lernkarte heute wieder fällig wird (für die „Fertig“-Anzeige). */
function next_learning_due(?int $tagId): ?int
{
    $p = [];
    $tag = tag_clause($tagId, $p);
    $st = db()->prepare("SELECT MIN(due_at) FROM cards WHERE suspended = 0 AND state IN ('learning','relearning')$tag");
    $st->execute($p);
    $v = $st->fetchColumn();
    return $v !== null && $v !== false ? (int)$v : null;
}
