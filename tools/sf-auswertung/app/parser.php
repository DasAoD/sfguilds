<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Parsed mail structure:
 *  - category: attack|defense
 *  - opponent_guild: string
 *  - participated: list of [name, world, level]
 *  - not_participated: list of [name, world, level]
 */
function parse_mail(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException("Leerer Text.");
    }

    $plain = normalize_spaces(strip_sf_markup($raw));
    $category = detect_category($plain);
    $opponent = extract_opponent_guild($plain, $category);

    // Marker robust (egal ob Komma/Spaces fehlen)
    $patNot = '/Mitglieder,\s*die\s*nicht\s*teilgenommen\s*haben:/ui';
    $patYes = '/Mitglieder,\s*die\s*teilgenommen\s*haben:/ui';

    $posNot = find_marker($raw, $patNot);
    $posYes = find_marker($raw, $patYes);

    $segNot = '';
    $segYes = '';

    if ($posNot !== null && $posYes !== null) {
        // Normalfall: beide vorhanden
        if ($posYes['offset'] < $posNot['offset']) {
            throw new RuntimeException("Marker-Reihenfolge unerwartet (teilgenommen vor nicht teilgenommen).");
        }

        $startNot = $posNot['offset'] + $posNot['length'];
        $endNot   = $posYes['offset'];
        $segNot   = substr($raw, $startNot, $endNot - $startNot);

        $startYes = $posYes['offset'] + $posYes['length'];
        $segYes   = substr($raw, $startYes);
    } elseif ($posYes !== null) {
        // Kein "nicht teilgenommen" (kann passieren, wenn alle dabei waren)
        $startYes = $posYes['offset'] + $posYes['length'];
        $segYes   = substr($raw, $startYes);
        $segNot   = '';
    } elseif ($posNot !== null) {
        // Kein "teilgenommen" (sehr selten, aber wir wollen nicht sterben)
        $startNot = $posNot['offset'] + $posNot['length'];
        $segNot   = substr($raw, $startNot);
        $segYes   = '';
    } else {
        throw new RuntimeException("Konnte keine Teilnehmer-Abschnitte finden.");
    }

    $not = dedup_players(extract_players_from_segment($segNot));
    $yes = dedup_players(extract_players_from_segment($segYes));

    return [
        'category' => $category,
        'opponent_guild' => $opponent,
        'participated' => $yes,
        'not_participated' => $not,
    ];
}

function detect_category(string $plain): string {
    if (preg_match('/\bAngriff\s+auf\b/ui', $plain)) return 'attack';

    // Verteidigungstexte können minimal variieren – wir nehmen bewusst breit:
    if (preg_match('/\bVerteidigung\s+gegen\b/ui', $plain)) return 'defense';
    if (preg_match('/\bVerteidigt\s+gegen\b/ui', $plain)) return 'defense';

    throw new RuntimeException("Kategorie nicht erkannt (kein 'Angriff auf' / 'Verteidigung gegen').");
}

function extract_opponent_guild(string $plain, string $category): string {
    $plain = normalize_spaces($plain);

    if ($category === 'attack') {
        if (preg_match('/Angriff\s+auf\s*(.+?)\s*Mitglieder,\s*die\s*nicht\s*teilgenommen/ui', $plain, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/Angriff\s+auf\s*(.+?)\s*Mitglieder,\s*die\s*teilgenommen/ui', $plain, $m)) {
            return trim($m[1]);
        }
    } else {
        // Wichtig: bei Verteidigung steht oft "Angreifer:" im Header -> nicht Teil des Gildennamens
        $reNot = '/Verteidigung\s+gegen\s*(?:Angreifer:\s*)?(.+?)\s*Mitglieder,\s*die\s*nicht\s*teilgenommen/ui';
        $reYes = '/Verteidigung\s+gegen\s*(?:Angreifer:\s*)?(.+?)\s*Mitglieder,\s*die\s*teilgenommen/ui';

        if (preg_match($reNot, $plain, $m)) return trim($m[1]);
        if (preg_match($reYes, $plain, $m)) return trim($m[1]);

        // Fallback, falls der Text mal ohne "Verteidigung" kommt
        $reNot2 = '/Verteidigt\s+gegen\s*(?:Angreifer:\s*)?(.+?)\s*Mitglieder,\s*die\s*nicht\s*teilgenommen/ui';
        $reYes2 = '/Verteidigt\s+gegen\s*(?:Angreifer:\s*)?(.+?)\s*Mitglieder,\s*die\s*teilgenommen/ui';

        if (preg_match($reNot2, $plain, $m)) return trim($m[1]);
        if (preg_match($reYes2, $plain, $m)) return trim($m[1]);
    }

    throw new RuntimeException("Gegnergilde konnte nicht aus dem Header gelesen werden.");
}

function extract_players_from_segment(string $segment): array {
    $players = [];
    if (trim($segment) === '') return $players;

    // Stabil: jeder Eintrag hat <sprite ...> Name (Stufe N)
    $re = '/<sprite="class_icons"\s+name="[^"]+">\s*([^<]+?)\s*\(Stufe\s*(\d+)\)/ui';
    if (preg_match_all($re, $segment, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $rawName = normalize_spaces($m[1]);
            $level = (int)$m[2];
            [$name, $world] = split_world_suffix($rawName);

            $players[] = [
                'name' => $name,
                'world' => $world,
                'level' => $level,
            ];
        }
    }

    return $players;
}

function split_world_suffix(string $rawName): array {
    // Beispiel: "VIDEL (s31de)" -> ("VIDEL","s31de")
    if (preg_match('/^(.*)\s+\((s\d{1,3}[a-z]{2,})\)$/i', $rawName, $m)) {
        return [trim($m[1]), strtolower($m[2])];
    }
    return [$rawName, ''];
}

function dedup_players(array $players): array {
    $seen = [];
    $out = [];

    foreach ($players as $p) {
        $key = mb_strtolower($p['name'] . '|' . $p['world'], 'UTF-8');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $p;
    }
    return $out;
}

function strip_sf_markup(string $text): string {
    // Entfernt <color>, <voffset>, <sprite> ... generisch
    $text = preg_replace('/<[^>]+>/u', ' ', $text);
    return $text ?? '';
}

function normalize_spaces(string $text): string {
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text ?? '');
}

/**
 * Find regex marker in $raw and return byte offset/length.
 * Returns null if not found.
 */
function find_marker(string $raw, string $pattern): ?array {
    if (preg_match($pattern, $raw, $m, PREG_OFFSET_CAPTURE)) {
        $matchText = $m[0][0];
        $offset = $m[0][1];
        return ['offset' => $offset, 'length' => strlen($matchText)];
    }
    return null;
}

/**
 * Create a stable hash key for deduplication.
 */
function build_battle_hash(string $occurredAt, string $category, string $opponent, array $yes, array $no): string {
    $rows = [];

    foreach ($yes as $p) {
        $rows[] = ['in', $p['name'], $p['world'], (string)$p['level']];
    }
    foreach ($no as $p) {
        $rows[] = ['out', $p['name'], $p['world'], (string)$p['level']];
    }

    usort($rows, function($a, $b) {
        return strcmp(mb_strtolower(implode('|', $a), 'UTF-8'), mb_strtolower(implode('|', $b), 'UTF-8'));
    });

    $payload = $occurredAt . '|' . $category . '|' . $opponent . '|' . json_encode($rows, JSON_UNESCAPED_UNICODE);
    return hash('sha1', $payload);
}
