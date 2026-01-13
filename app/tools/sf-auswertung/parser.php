<?php
// /var/www/sfguilds/app/tools/sf-auswertung/parser.php

declare(strict_types=1);

function sf_auswertung_parse_report(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['ok' => false, 'error' => 'Leerer Text.'];
    }

    // Normalize: split voffset blocks into lines
    $t = str_replace(["\r\n", "\r"], "\n", $raw);
    $t = str_replace("</voffset>", "\n", $t);

    // Extract header line (keep tags for now, strip later)
    $lines = array_values(array_filter(array_map('trim', explode("\n", $t)), fn($x) => $x !== ''));

    // Determine type + opponent
    $joined = implode("\n", $lines);

    $battleType = null;
    $opponent = null;

    // Strip tags for header detection
    $plain = preg_replace('/<[^>]+>/', '', $joined);
    $plain = trim(preg_replace('/[ \t]+/', ' ', $plain));

    if (preg_match('/^Angriff auf\s+(.+?)\s*(?:Mitglieder,|\n|$)/u', $plain, $m)) {
        $battleType = 'attack';
        $opponent = trim($m[1]);
    } elseif (preg_match('/^Verteidigung gegen Angreifer:\s*(.+?)\s*(?:Mitglieder,|\n|$)/u', $plain, $m)) {
        $battleType = 'defense';
        $opponent = trim($m[1]);
    } else {
        return ['ok' => false, 'error' => 'Konnte nicht erkennen, ob Angriff oder Verteidigung (Header nicht gefunden).'];
    }

    if ($opponent === '') {
        return ['ok' => false, 'error' => 'Gildenname (Gegner) konnte nicht erkannt werden.'];
    }

    // Now parse player sections from tag-stripped content linewise
    $plainLines = array_map(
        fn($l) => trim(preg_replace('/[ \t]+/', ' ', preg_replace('/<[^>]+>/', '', $l))),
        explode("\n", $t)
    );

    $mode = null; // 'absent'|'present'
    $players = [];

    foreach ($plainLines as $l) {
        $l = trim($l);
        if ($l === '') {
            continue;
        }

        if (str_starts_with($l, 'Mitglieder, die nicht teilgenommen haben')) {
            $mode = 'absent';
            continue;
        }
        if (str_starts_with($l, 'Mitglieder, die teilgenommen haben')) {
            $mode = 'present';
            continue;
        }

        if ($mode === null) {
            continue;
        }

        // Player lines contain "(Stufe N)"
        if (!str_contains($l, '(Stufe')) {
            continue;
        }

        $p = sf_auswertung_parse_player_line($l);
        if ($p === null) {
            continue;
        }

        $p['participated'] = ($mode === 'present');
        $players[] = $p;
    }

    if (!$players) {
        return ['ok' => false, 'error' => 'Keine Spielerzeilen gefunden. (Wurde der Text komplett kopiert?)'];
    }

    // Deduplicate by name (falls Copy/Paste mal doppelt)
    $uniq = [];
    foreach ($players as $p) {
        $uniq[$p['name']] = $p;
    }
    $players = array_values($uniq);

    return [
        'ok' => true,
        'battle_type' => $battleType,
        'opponent_guild' => $opponent,
        'players' => $players,
    ];
}

function sf_auswertung_parse_player_line(string $line): ?array
{
    // Example: "VIDEL (s31de) (Stufe 648)"
    // Example: "Alexxz (Stufe 526)"

    if (!preg_match('/\(Stufe\s+(\d+)\)/u', $line, $m)) {
        return null;
    }
    $level = (int)$m[1];

    // Remove "(Stufe N)"
    $base = trim(preg_replace('/\s*\(Stufe\s+\d+\)\s*/u', '', $line));

    $server = null;

    // Optional trailing "(sXXde)" etc
    if (preg_match('/^(.*)\s+\((s[0-9]+[a-z0-9]+)\)\s*$/iu', $base, $mm)) {
        $base = trim($mm[1]);
        $server = trim($mm[2]);
    }

    $name = trim($base);

    if ($name === '') {
        return null;
    }

    return [
        'name' => $name,
        'level' => $level,
        'server_tag' => $server,
    ];
}
