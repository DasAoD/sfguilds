<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $varDir = realpath(__DIR__ . '/../var');
    if ($varDir === false) {
        throw new RuntimeException("Ordner /var fehlt. Bitte ../var anlegen.");
    }

    if (!is_writable($varDir)) {
        throw new RuntimeException("Ordner ../var ist nicht beschreibbar. Bitte Besitzer/Rechte prüfen (www-data).");
    }

    $dbPath = $varDir . '/data.sqlite';

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS battles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            occurred_at TEXT NOT NULL,              -- ISO: YYYY-MM-DD HH:MM
            category TEXT NOT NULL,                 -- attack|defense
            opponent_guild TEXT NOT NULL,
            raw_text TEXT NOT NULL,
            content_hash TEXT NOT NULL UNIQUE
        );

        CREATE TABLE IF NOT EXISTS players (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            world TEXT DEFAULT '',
            last_level INTEGER DEFAULT NULL,
            UNIQUE(name, world)
        );

        CREATE TABLE IF NOT EXISTS attendance (
            battle_id INTEGER NOT NULL,
            player_id INTEGER NOT NULL,
            status TEXT NOT NULL,                   -- in|out
            level INTEGER DEFAULT NULL,
            PRIMARY KEY (battle_id, player_id),
            FOREIGN KEY (battle_id) REFERENCES battles(id),
            FOREIGN KEY (player_id) REFERENCES players(id)
        );

        CREATE INDEX IF NOT EXISTS idx_battles_occurred_at ON battles(occurred_at);
        CREATE INDEX IF NOT EXISTS idx_battles_category ON battles(category);
        CREATE INDEX IF NOT EXISTS idx_battles_opponent ON battles(opponent_guild);
    ");

    return $pdo;
}
