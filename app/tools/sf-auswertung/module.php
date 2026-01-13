<?php
// /var/www/sfguilds/app/tools/sf-auswertung/module.php

declare(strict_types=1);

require_once __DIR__ . '/parser.php';

function sf_auswertung_root(): string
{
    // __DIR__ = /var/www/sfguilds/app/tools/sf-auswertung
    return dirname(__DIR__, 3);
}

function sf_auswertung_db_path(): string
{
    return sf_auswertung_root() . '/storage/sf-auswertung.sqlite';
}

function sf_auswertung_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = sf_auswertung_db_path();

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // SQLite Pragmas
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');

    sf_auswertung_init_schema($pdo);

    return $pdo;
}

function sf_auswertung_init_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS sf_battles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            battle_type TEXT NOT NULL,                  -- 'attack' | 'defense'
            opponent_guild TEXT NOT NULL,
            battle_date TEXT NOT NULL,                  -- YYYY-MM-DD
            battle_time TEXT NOT NULL,                  -- HH:MM
            battle_hash TEXT NOT NULL UNIQUE,           -- sha1(rawText)
            raw_text TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS sf_players (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            last_seen_level INTEGER,
            last_seen_server TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS sf_battle_players (
            battle_id INTEGER NOT NULL,
            player_id INTEGER NOT NULL,
            participated INTEGER NOT NULL,              -- 0/1
            level INTEGER,
            server_tag TEXT,
            PRIMARY KEY (battle_id, player_id),
            FOREIGN KEY (battle_id) REFERENCES sf_battles(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES sf_players(id) ON DELETE CASCADE
        );
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_sfbp_player ON sf_battle_players(player_id);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sfbattle_date ON sf_battles(battle_date);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sfbattle_type ON sf_battles(battle_type);");
}

function sf_auswertung_import_battle(string $date, string $time, string $rawText): array
{
    $date = trim($date);
    $time = trim($time);
    $rawText = trim($rawText);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'error' => 'Datum muss im Format YYYY-MM-DD sein.'];
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        return ['ok' => false, 'error' => 'Uhrzeit muss im Format HH:MM sein.'];
    }
    if ($rawText === '') {
        return ['ok' => false, 'error' => 'Textfeld ist leer.'];
    }

    $parsed = sf_auswertung_parse_report($rawText);
    if (!$parsed['ok']) {
        return ['ok' => false, 'error' => $parsed['error']];
    }

    $battleType = $parsed['battle_type'];       // attack|defense
    $opponent   = $parsed['opponent_guild'];
    $players    = $parsed['players'];           // list

    $hash = sha1($rawText);
    $now  = date('c');

    $db = sf_auswertung_db();

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO sf_battles (battle_type, opponent_guild, battle_date, battle_time, battle_hash, raw_text, created_at)
            VALUES (:t, :g, :d, :tm, :h, :rt, :ca)
        ");
        $stmt->execute([
            ':t'  => $battleType,
            ':g'  => $opponent,
            ':d'  => $date,
            ':tm' => $time,
            ':h'  => $hash,
            ':rt' => $rawText,
            ':ca' => $now,
        ]);

        $battleId = (int)$db->lastInsertId();

        $upsertPlayer = $db->prepare("
            INSERT INTO sf_players (name, last_seen_level, last_seen_server, created_at, updated_at)
            VALUES (:name, :lvl, :srv, :ca, :ua)
            ON CONFLICT(name) DO UPDATE SET
                last_seen_level = excluded.last_seen_level,
                last_seen_server = excluded.last_seen_server,
                updated_at = excluded.updated_at
        ");

        $getPlayerId = $db->prepare("SELECT id FROM sf_players WHERE name = :name");

        $insBP = $db->prepare("
            INSERT INTO sf_battle_players (battle_id, player_id, participated, level, server_tag)
            VALUES (:bid, :pid, :part, :lvl, :srv)
        ");

        foreach ($players as $p) {
            $upsertPlayer->execute([
                ':name' => $p['name'],
                ':lvl'  => $p['level'],
                ':srv'  => $p['server_tag'],
                ':ca'   => $now,
                ':ua'   => $now,
            ]);

            $getPlayerId->execute([':name' => $p['name']]);
            $pid = (int)$getPlayerId->fetchColumn();

            $insBP->execute([
                ':bid'  => $battleId,
                ':pid'  => $pid,
                ':part' => $p['participated'] ? 1 : 0,
                ':lvl'  => $p['level'],
                ':srv'  => $p['server_tag'],
            ]);
        }

        $db->commit();

        return [
            'ok' => true,
            'battle' => [
                'type' => $battleType,
                'opponent' => $opponent,
                'date' => $date,
                'time' => $time,
                'players_total' => count($players),
                'players_participated' => count(array_filter($players, fn($x) => $x['participated'])),
            ]
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Duplicate import (same text)
        if (str_contains((string)$e->getMessage(), 'UNIQUE') || str_contains((string)$e->getMessage(), 'battle_hash')) {
            return ['ok' => false, 'error' => 'Dieser Kampfbericht wurde vermutlich schon importiert (Duplikat erkannt).'];
        }

        return ['ok' => false, 'error' => 'Import fehlgeschlagen: ' . $e->getMessage()];
    }
}

function sf_auswertung_report(array $filter = []): array
{
    $type = $filter['type'] ?? 'both'; // attack|defense|both
    $from = $filter['from'] ?? '';
    $to   = $filter['to'] ?? '';

    $where = [];
    $params = [];

    if ($type === 'attack' || $type === 'defense') {
        $where[] = 'b.battle_type = :type';
        $params[':type'] = $type;
    }

    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'b.battle_date >= :from';
        $params[':from'] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'b.battle_date <= :to';
        $params[':to'] = $to;
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $db = sf_auswertung_db();

    $sql = "
        SELECT
            p.name,

            SUM(CASE WHEN b.battle_type='attack' THEN 1 ELSE 0 END) AS attack_total,
            SUM(CASE WHEN b.battle_type='attack' AND bp.participated=1 THEN 1 ELSE 0 END) AS attack_yes,

            SUM(CASE WHEN b.battle_type='defense' THEN 1 ELSE 0 END) AS defense_total,
            SUM(CASE WHEN b.battle_type='defense' AND bp.participated=1 THEN 1 ELSE 0 END) AS defense_yes

        FROM sf_players p
        JOIN sf_battle_players bp ON bp.player_id = p.id
        JOIN sf_battles b ON b.id = bp.battle_id
        $sqlWhere
        GROUP BY p.id
        ORDER BY
            (attack_total + defense_total) DESC,
            p.name COLLATE NOCASE ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Totals (Anzahl Kämpfe)
    $totStmt = $db->prepare("SELECT battle_type, COUNT(*) AS cnt FROM sf_battles b $sqlWhere GROUP BY battle_type");
    $totStmt->execute($params);
    $totals = ['attack' => 0, 'defense' => 0];
    foreach ($totStmt->fetchAll() as $r) {
        $totals[$r['battle_type']] = (int)$r['cnt'];
    }

    return ['rows' => $rows, 'totals' => $totals, 'filter' => ['type' => $type, 'from' => $from, 'to' => $to]];
}
