<?php
declare(strict_types=1);

/**
 * SF-Auswertung (Angriff/Verteidigung) – pro Gilde (guilds.id)
 * Speichert Kämpfe + Teilnehmer (teilgenommen/ nicht teilgenommen) in sfguilds.sqlite
 */

function sf_eval_db(): PDO {
    // nutzt die bestehende DB-Verbindung aus dem Projekt
    return db();
}

function sf_eval_ensure_schema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS sf_eval_battles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            guild_id INTEGER NOT NULL,
            battle_type TEXT NOT NULL CHECK(battle_type IN ('attack','defense')),
            opponent_guild TEXT NOT NULL,
            battle_date TEXT NOT NULL,   -- YYYY-MM-DD
            battle_time TEXT NOT NULL,   -- HH:MM
            raw_text TEXT NOT NULL,
            raw_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(guild_id, raw_hash),
            FOREIGN KEY(guild_id) REFERENCES guilds(id) ON DELETE CASCADE
        );
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS sf_eval_participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            battle_id INTEGER NOT NULL,
            player_name TEXT NOT NULL,
            player_name_norm TEXT NOT NULL,
            player_level INTEGER NULL,
            player_server_tag TEXT NULL,
            participated INTEGER NOT NULL CHECK(participated IN (0,1)),
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(battle_id, player_name_norm),
            FOREIGN KEY(battle_id) REFERENCES sf_eval_battles(id) ON DELETE CASCADE
        );
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_sf_eval_battles_guild_type ON sf_eval_battles(guild_id, battle_type);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sf_eval_participants_battle ON sf_eval_participants(battle_id);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sf_eval_participants_name ON sf_eval_participants(player_name_norm);");
}

function sf_eval_normalize_player_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    return mb_strtolower($name);
}

/**
 * Entfernt Ingame-Markup wie <color>, <voffset>, <sprite ...> usw.
 * und macht es zeilenbasiert parsebar.
 */
function sf_eval_strip_ingame_markup(string $raw): string {
    // vereinheitlichen
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    // Sprite-Tags komplett raus
    $raw = preg_replace('/<sprite\b[^>]*>/i', '', $raw) ?? $raw;

    // voffset Open/Close als Zeilentrenner behandeln (hilft gegen "aneinanderklebende" Einträge)
    $raw = preg_replace('/<voffset\b[^>]*>/i', "\n", $raw) ?? $raw;
    $raw = preg_replace('/<\/voffset>/i', "\n", $raw) ?? $raw;

    // color tags
    $raw = preg_replace('/<color=[^>]*>/i', '', $raw) ?? $raw;
    $raw = preg_replace('/<\/color>/i', '', $raw) ?? $raw;

    // sonstige tags (falls noch was übrig)
    $raw = preg_replace('/<[^>]+>/', '', $raw) ?? $raw;

    // leere Zeilen normalisieren
    $raw = preg_replace("/\n{3,}/", "\n\n", $raw) ?? $raw;

    return trim($raw);
}

/**
 * Parsed einen Post-Text.
 * Rückgabe:
 * [
 *   'type' => 'attack'|'defense',
 *   'opponent' => 'Gildenname',
 *   'players' => [ ['name'=>..., 'level'=>int|null, 'server_tag'=>string|null, 'participated'=>0|1], ... ],
 * ]
 */
function sf_eval_parse_mail(string $rawText): array {
    $text = sf_eval_strip_ingame_markup($rawText);

    $type = null;
    $opponent = null;

    // Header erkennen
    if (preg_match('/^Angriff auf\s+(.+?)\s*$/mi', $text, $m)) {
        $type = 'attack';
        $opponent = trim($m[1]);
    } elseif (preg_match('/^Verteidigung gegen Angreifer:\s+(.+?)\s*$/mi', $text, $m)) {
        $type = 'defense';
        $opponent = trim($m[1]);
    }

    if (!$type || !$opponent) {
        throw new RuntimeException("Konnte weder Angriff noch Verteidigung erkennen (Header fehlt/anders).");
    }

    // Blöcke extrahieren
    $notBlock = sf_eval_extract_block($text, 'Mitglieder, die nicht teilgenommen haben:', 'Mitglieder, die teilgenommen haben:');
    $yesBlock = sf_eval_extract_block($text, 'Mitglieder, die teilgenommen haben:', null);

    $players = [];
    foreach (sf_eval_extract_players_from_block($notBlock, 0) as $p) $players[] = $p;
    foreach (sf_eval_extract_players_from_block($yesBlock, 1) as $p) $players[] = $p;

    if (!$players) {
        throw new RuntimeException("Keine Spielernamen gefunden. (Sind die Listen im Text vorhanden?)");
    }

    return [
        'type' => $type,
        'opponent' => $opponent,
        'players' => $players,
    ];
}

function sf_eval_extract_block(string $text, string $start, ?string $end): string {
    $startPos = mb_stripos($text, $start);
    if ($startPos === false) return '';

    $startPos += mb_strlen($start);
    $chunk = mb_substr($text, $startPos);

    if ($end !== null) {
        $endPos = mb_stripos($chunk, $end);
        if ($endPos !== false) {
            $chunk = mb_substr($chunk, 0, $endPos);
        }
    }

    return trim($chunk);
}

function sf_eval_extract_players_from_block(string $block, int $participated): array {
    $block = trim($block);
    if ($block === '') return [];

    $lines = preg_split('/\n/u', $block) ?: [];
    $players = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Format: Name (s37de) (Stufe 283)  oder Name (Stufe 283)
        if (!preg_match('/^(.*?)\s*\(Stufe\s*(\d+)\)\s*$/ui', $line, $m)) {
            continue;
        }

        $namePart = trim($m[1]);
        $level = (int)$m[2];

        $serverTag = null;
        // optionaler Server-Tag in Klammern am Ende des Namensparts
        if (preg_match('/^(.*)\s*\((s[0-9]+[a-z0-9]+)\)\s*$/ui', $namePart, $mm)) {
            $namePart = trim($mm[1]);
            $serverTag = strtolower($mm[2]);
        }

        $players[] = [
            'name' => $namePart,
            'level' => $level,
            'server_tag' => $serverTag,
            'participated' => $participated,
        ];
    }

    return $players;
}

/**
 * Importiert einen Kampf (dedupe via raw_hash pro guild_id).
 */
function sf_eval_import(int $guildId, string $date, string $time, string $rawText): array {
    $db = sf_eval_db();
    sf_eval_ensure_schema($db);

    $parsed = sf_eval_parse_mail($rawText);

    $normalized = sf_eval_strip_ingame_markup($rawText);
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    $rawHash = hash('sha256', trim($normalized));

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT OR IGNORE INTO sf_eval_battles
            (guild_id, battle_type, opponent_guild, battle_date, battle_time, raw_text, raw_hash)
            VALUES (:guild_id, :battle_type, :opponent, :battle_date, :battle_time, :raw_text, :raw_hash)
        ");
        $stmt->execute([
            ':guild_id' => $guildId,
            ':battle_type' => $parsed['type'],
            ':opponent' => $parsed['opponent'],
            ':battle_date' => $date,
            ':battle_time' => $time,
            ':raw_text' => $rawText,
            ':raw_hash' => $rawHash,
        ]);

        // battle_id finden (egal ob insert oder already exists)
        $stmt = $db->prepare("SELECT id FROM sf_eval_battles WHERE guild_id = :guild_id AND raw_hash = :raw_hash LIMIT 1");
        $stmt->execute([':guild_id' => $guildId, ':raw_hash' => $rawHash]);
        $battleId = (int)($stmt->fetchColumn() ?: 0);

        if ($battleId <= 0) {
            throw new RuntimeException("Konnte battle_id nicht bestimmen.");
        }

        // wenn schon existiert: nicht nochmal Teilnehmer doppelt schreiben
        $stmt = $db->prepare("SELECT COUNT(*) FROM sf_eval_participants WHERE battle_id = :bid");
        $stmt->execute([':bid' => $battleId]);
        $already = (int)$stmt->fetchColumn();

        if ($already === 0) {
            $ins = $db->prepare("
                INSERT OR IGNORE INTO sf_eval_participants
                (battle_id, player_name, player_name_norm, player_level, player_server_tag, participated)
                VALUES (:battle_id, :player_name, :player_name_norm, :player_level, :player_server_tag, :participated)
            ");

            foreach ($parsed['players'] as $p) {
                $ins->execute([
                    ':battle_id' => $battleId,
                    ':player_name' => $p['name'],
                    ':player_name_norm' => sf_eval_normalize_player_name($p['name']),
                    ':player_level' => $p['level'],
                    ':player_server_tag' => $p['server_tag'],
                    ':participated' => $p['participated'],
                ]);
            }
        }

        $db->commit();

        return [
            'battle_id' => $battleId,
            'type' => $parsed['type'],
            'opponent' => $parsed['opponent'],
            'inserted' => ($already === 0),
            'players' => count($parsed['players']),
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function sf_eval_guilds(): array {
    $db = sf_eval_db();
    $stmt = $db->query("SELECT id, name, server FROM guilds ORDER BY name COLLATE NOCASE");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function sf_eval_stats(int $guildId, string $type): array {
    $db = sf_eval_db();
    sf_eval_ensure_schema($db);

    $stmt = $db->prepare("
        SELECT
            MIN(p.player_name) AS player_name,
            COUNT(*) AS fights,
            SUM(p.participated) AS participated,
            SUM(1 - p.participated) AS missed,
            ROUND(100.0 * SUM(p.participated) / COUNT(*), 1) AS pct_participated
        FROM sf_eval_participants p
        JOIN sf_eval_battles b ON b.id = p.battle_id
        WHERE b.guild_id = :gid AND b.battle_type = :type
        GROUP BY p.player_name_norm
        ORDER BY pct_participated DESC, fights DESC, player_name COLLATE NOCASE
    ");
    $stmt->execute([':gid' => $guildId, ':type' => $type]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt2 = $db->prepare("
        SELECT COUNT(*) FROM sf_eval_battles
        WHERE guild_id = :gid AND battle_type = :type
    ");
    $stmt2->execute([':gid' => $guildId, ':type' => $type]);
    $battles = (int)$stmt2->fetchColumn();

    return ['battles' => $battles, 'rows' => $rows];
}
