<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0770, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/lernkarten.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 1) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cards (
            id               INTEGER PRIMARY KEY,
            filename         TEXT    NOT NULL,
            thumb            TEXT,
            width            INTEGER,
            height           INTEGER,
            title            TEXT    NOT NULL DEFAULT '',
            note             TEXT    NOT NULL DEFAULT '',
            uploaded_at      INTEGER NOT NULL,
            state            TEXT    NOT NULL DEFAULT 'new',  -- new | learning | review | relearning
            step             INTEGER NOT NULL DEFAULT 0,
            stability        REAL,
            difficulty       REAL,
            due_at           INTEGER NOT NULL,
            interval_days    INTEGER NOT NULL DEFAULT 0,
            reps             INTEGER NOT NULL DEFAULT 0,
            lapses           INTEGER NOT NULL DEFAULT 0,
            last_reviewed_at INTEGER,
            suspended        INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_cards_due ON cards (suspended, state, due_at);

        CREATE TABLE IF NOT EXISTS tags (
            id   INTEGER PRIMARY KEY,
            name TEXT NOT NULL UNIQUE COLLATE NOCASE
        );
        CREATE TABLE IF NOT EXISTS card_tags (
            card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
            tag_id  INTEGER NOT NULL REFERENCES tags(id)  ON DELETE CASCADE,
            PRIMARY KEY (card_id, tag_id)
        );
        CREATE INDEX IF NOT EXISTS idx_card_tags_tag ON card_tags (tag_id);

        CREATE TABLE IF NOT EXISTS reviews (
            id             INTEGER PRIMARY KEY,
            card_id        INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
            reviewed_at    INTEGER NOT NULL,
            rating         INTEGER NOT NULL,          -- 1 Nochmal, 2 Schwer, 3 Gut, 4 Einfach
            state_before   TEXT    NOT NULL,
            state_after    TEXT    NOT NULL,
            elapsed_days   INTEGER NOT NULL,
            stability      REAL,
            difficulty     REAL,
            retrievability REAL,
            next_due_at    INTEGER NOT NULL,
            interval_days  INTEGER NOT NULL,
            duration_ms    INTEGER,
            prev_card      TEXT    NOT NULL           -- Kartenzustand vorher (für Rückgängig)
        );
        CREATE INDEX IF NOT EXISTS idx_reviews_card ON reviews (card_id, reviewed_at);
        CREATE INDEX IF NOT EXISTS idx_reviews_time ON reviews (reviewed_at);

        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS auth_tokens (
            id           INTEGER PRIMARY KEY,
            token_hash   TEXT    NOT NULL UNIQUE,
            created_at   INTEGER NOT NULL,
            last_used_at INTEGER NOT NULL,
            expires_at   INTEGER NOT NULL,
            user_agent   TEXT
        );
        CREATE TABLE IF NOT EXISTS login_attempts (
            ip           TEXT    NOT NULL,
            attempted_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts (attempted_at);
        PRAGMA user_version = 1;
    SQL);
}

const SETTING_DEFAULTS = [
    'desired_retention' => '0.9',
    'new_per_day'       => '20',
];

function setting(string $key): string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    if ($v !== false) {
        return $v;
    }
    if ($key === 'api_token') {
        $v = bin2hex(random_bytes(24));
        set_setting('api_token', $v);
        return $v;
    }
    return SETTING_DEFAULTS[$key] ?? '';
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}
