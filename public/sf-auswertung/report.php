<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/sf_auswertung.php';

if (!isAdmin()) {
    $next = $_SERVER['REQUEST_URI'] ?? '/sf-auswertung/report.php';
    header('Location: ' . url('/admin/login.php?next=' . rawurlencode($next)));
    exit;
}

function sf_stripos_u(string $haystack, string $needle)
{
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8');
    }
    return stripos($haystack, $needle);
}

function sf_name_key(string $name): string
{
    $s = trim($name);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    return strtolower($s);
}

/**
 * Entfernt EINEN Server-Suffix wie " (s31de)" am Ende des Namens.
 * (nur wenn es wirklich wie ein Servercode aussieht)
 */
function sf_strip_server_suffix(string $name): string
{
    $s = trim($name);

    // z.B. "VIDEL (s31de)" -> base "VIDEL"
    if (preg_match('/\s*\(([^()]*)\)\s*$/u', $s, $m)) {
        $inside = trim((string)$m[1]);

        // Server-Tag Muster: s + 1-3 Ziffern + 2 Buchstaben (de, us, ...)
        if (preg_match('/^s\d{1,3}[a-z]{2}$/i', $inside)) {
            $s = preg_replace('/\s*\([^()]*\)\s*$/u', '', $s);
            return trim((string)$s);
        }
    }

    return $s;
}

function sf_rank_group($rank): int
{
    if ($rank === null || $rank === '') {
        return 2; // default: Mitglied
    }

    // Numeric ranks (best effort)
    if (is_int($rank) || (is_string($rank) && ctype_digit($rank))) {
        $n = (int)$rank;

        // häufige Konventionen: 1=Leader, 2=Officer, 3=Member
        if ($n === 0 || $n === 1) return 0; // Leader / Anführer
        if ($n === 2) return 1;            // Officer
        return 2;                          // Member
    }

    $s = (string)$rank;
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }

    // Leader / Anführer
    if (strpos($s, 'anführ') !== false || strpos($s, 'leiter') !== false || strpos($s, 'leader') !== false || strpos($s, 'master') !== false) {
        return 0;
    }

    // Officer / Offizier
    if (strpos($s, 'offiz') !== false || strpos($s, 'officer') !== false) {
        return 1;
    }

    return 2;
}

/**
 * Holt Level+Rank aus members (Roster).
 * Rückgabe:
 * [
 *   'exact' => [ key(name) => ['level'=>int,'rank'=>mixed,'display_name'=>string] ],
 *   'base'  => [ key(base) => ['level'=>int,'rank'=>mixed,'display_name'=>string] ],
 * ]
 */
function sf_fetch_roster_meta(PDO $pdo, int $guildId): array
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'sqlite') {
        return ['exact' => [], 'base' => []];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT name, level, rank, updated_at
            FROM members
            WHERE guild_id = ?
            ORDER BY updated_at DESC
        ");
        $stmt->execute([$guildId]);
    } catch (Throwable $e) {
        return ['exact' => [], 'base' => []];
    }

    $exact = [];
    $base  = [];

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fullName = trim((string)($r['name'] ?? ''));
        if ($fullName === '') continue;

        $lvl = (int)($r['level'] ?? 0);
        $rk  = $r['rank'] ?? null;

        $kExact = sf_name_key($fullName);
        if (!isset($exact[$kExact])) {
            $exact[$kExact] = [
                'level' => $lvl,
                'rank'  => $rk,
                'display_name' => $fullName,
            ];
        }

        $baseName = sf_strip_server_suffix($fullName);
        $kBase = sf_name_key($baseName);
        if (!isset($base[$kBase])) {
            $base[$kBase] = [
                'level' => $lvl,
                'rank'  => $rk,
                'display_name' => $fullName, // wichtig: Anzeige soll inkl. (s31de) sein
            ];
        }
    }

    return ['exact' => $exact, 'base' => $base];
}

function sf_merge_stats_rows(array $rows, string $prefix, array &$map): void
{
    foreach ($rows as $r) {
        $name = trim((string)($r['player_name'] ?? ''));
        if ($name === '') {
            continue;
        }

        if (!isset($map[$name])) {
            $map[$name] = [
                'name'        => $name,
                'level'       => 0,
                'rank_raw'    => null,

                'a_done'      => 0,
                'a_total'     => 0,
                'a_miss'      => 0,
                'v_done'      => 0,
                'v_total'     => 0,
                'v_miss'      => 0,
                'done_total'  => 0,
                'total'       => 0,
                'missing'     => 0,
                'pct'         => 0,
                'cls'         => 'good',
            ];
        }

        $done  = (int)($r['participated'] ?? 0);
        $miss  = (int)($r['missed'] ?? 0);
        $total = (int)($r['fights'] ?? ($done + $miss));

        $map[$name][$prefix . '_done']  = max(0, $done);
        $map[$name][$prefix . '_miss']  = max(0, $miss);
        $map[$name][$prefix . '_total'] = max(0, $total);

        // optional: level/rank aus Stats-Row übernehmen, falls vorhanden
        $lvl = (int)($r['level'] ?? ($r['player_level'] ?? 0));
        if ($lvl > (int)$map[$name]['level']) {
            $map[$name]['level'] = $lvl;
        }

        $rk = $r['rank'] ?? ($r['player_rank'] ?? ($r['guild_rank'] ?? ($r['role'] ?? null)));
        if ($rk !== null && $rk !== '') {
            $map[$name]['rank_raw'] = $rk;
        }
    }
}

$title  = 'SF Auswertung – Report';
$guilds = sf_eval_guilds();

$guildId      = (int)($_GET['guild_id'] ?? 0);
$onlyMissing  = !empty($_GET['missing']);
$q            = trim((string)($_GET['q'] ?? ''));
$export       = (string)($_GET['export'] ?? '');

$guild = null;

$stats = [
    'attacks'     => 0,
    'defenses'    => 0,
    'last_import' => null,
];

$attack  = null;
$defense = null;

if ($guildId > 0) {
    // Guild-Infos
    $st = db()->prepare("SELECT id, name, server, crest_file FROM guilds WHERE id = ?");
    $st->execute([$guildId]);
    $guild = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Ungültige ID -> wie "nicht gewählt" behandeln
    if (!$guild) {
        $guildId = 0;
    } else {
        $title = $guild['server'] . ' – ' . $guild['name'] . ' (Report)';

        // Stats (Angriffe/Verteidigungen/letzter Import)
        $st = db()->prepare("
            SELECT
                SUM(CASE WHEN battle_type = 'attack'  THEN 1 ELSE 0 END) AS attacks,
                SUM(CASE WHEN battle_type = 'defense' THEN 1 ELSE 0 END) AS defenses,
                MAX(battle_date) AS last_import
            FROM sf_eval_battles
            WHERE guild_id = ?
        ");
        $st->execute([$guildId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $stats['attacks']     = (int)($row['attacks'] ?? 0);
        $stats['defenses']    = (int)($row['defenses'] ?? 0);
        $stats['last_import'] = $row['last_import'] ?? null;

        $attack  = sf_eval_stats($guildId, 'attack');
        $defense = sf_eval_stats($guildId, 'defense');
    }
}

// schöner Zeitstempel (optional)
$lastImportNice = null;
if (!empty($stats['last_import'])) {
    try {
        $dt = new DateTime((string)$stats['last_import']);
        $lastImportNice = $dt->format('d.m.Y');
    } catch (Throwable $e) {
        $lastImportNice = (string)$stats['last_import'];
    }
}

// Spieler zusammenführen (Angriff + Verteidigung)
$playersMap = [];
sf_merge_stats_rows((array)($attack['rows'] ?? []), 'a', $playersMap);
sf_merge_stats_rows((array)($defense['rows'] ?? []), 'v', $playersMap);

// Roster-Meta (members)
$roster = ($guildId > 0) ? sf_fetch_roster_meta(db(), $guildId) : ['exact' => [], 'base' => []];
$rosterExact = $roster['exact'];
$rosterBase  = $roster['base'];

// Safety: Roster nur nutzen, wenn wir mind. 2 Treffer gegen die Stats-Namen haben (über exact oder base)
if ($guildId > 0 && ($rosterExact || $rosterBase)) {
    $playerKeys = [];
    foreach (array_keys($playersMap) as $pn) {
        $playerKeys[sf_name_key($pn)] = true;
        $playerKeys[sf_name_key(sf_strip_server_suffix($pn))] = true;
    }

    $hits = 0;
    foreach ($rosterExact as $k => $_) {
        if (isset($playerKeys[$k])) { $hits++; if ($hits >= 2) break; }
    }
    if ($hits < 2) {
        foreach ($rosterBase as $k => $_) {
            if (isset($playerKeys[$k])) { $hits++; if ($hits >= 2) break; }
        }
    }

    if ($hits < 2) {
        $rosterExact = [];
        $rosterBase  = [];
    }
}

$attacksTotal  = (int)$stats['attacks'];
$defensesTotal = (int)$stats['defenses'];

// Falls fights in Rows verlässlicher ist (z. B. stats=0), versuchen wir daraus zu nehmen
if ($attacksTotal <= 0) {
    foreach ($playersMap as $p) {
        if (!empty($p['a_total'])) {
            $attacksTotal = (int)$p['a_total'];
            break;
        }
    }
}
if ($defensesTotal <= 0) {
    foreach ($playersMap as $p) {
        if (!empty($p['v_total'])) {
            $defensesTotal = (int)$p['v_total'];
            break;
        }
    }
}

$totalFights = max(0, $attacksTotal + $defensesTotal);

// Finalisieren: Totals, Quote, Klassen
$playersAll = [];
$sumDoneTotal = 0;
$missingPlayersCount = 0;

foreach ($playersMap as $name => $p) {
    $aDone  = (int)($p['a_done'] ?? 0);
    $vDone  = (int)($p['v_done'] ?? 0);

    $aTotal = $attacksTotal > 0 ? $attacksTotal : (int)($p['a_total'] ?? 0);
    $vTotal = $defensesTotal > 0 ? $defensesTotal : (int)($p['v_total'] ?? 0);

    // Falls missed nicht geliefert wurde, ableiten
    $aMiss = (int)($p['a_miss'] ?? 0);
    $vMiss = (int)($p['v_miss'] ?? 0);

    if ($aMiss <= 0 && $aTotal > 0) {
        $aMiss = max(0, $aTotal - $aDone);
    }
    if ($vMiss <= 0 && $vTotal > 0) {
        $vMiss = max(0, $vTotal - $vDone);
    }

    $total = max(0, $aTotal + $vTotal);
    $doneTotal = max(0, $aDone + $vDone);

    $missing = max(0, $total - $doneTotal);

    $pct = 0;
    if ($total > 0) {
        $pct = (int)round(($doneTotal / $total) * 100);
    }

    $level = (int)($p['level'] ?? 0);
    $rankRaw = $p['rank_raw'] ?? null;

    // Anzeige-Name: standardmäßig Stats-Name, kann aber aus members kommen (mit (sXXde))
    $displayName = (string)$name;

    // Match gegen members:
    $kExact = sf_name_key((string)$name);
    $kBase  = sf_name_key(sf_strip_server_suffix((string)$name));

    $m = null;
    if (isset($rosterExact[$kExact])) {
        $m = $rosterExact[$kExact];
    } elseif (isset($rosterBase[$kExact])) {
        $m = $rosterBase[$kExact];
    } elseif (isset($rosterExact[$kBase])) {
        $m = $rosterExact[$kBase];
    } elseif (isset($rosterBase[$kBase])) {
        $m = $rosterBase[$kBase];
    }

    if ($m) {
        if (!empty($m['level'])) {
            $level = max($level, (int)$m['level']);
        }
        if (($m['rank'] ?? null) !== null && (string)($m['rank'] ?? '') !== '') {
            $rankRaw = $m['rank'];
        }
        if (!empty($m['display_name'])) {
            $displayName = (string)$m['display_name'];
        }
    }

    $rankGroup = sf_rank_group($rankRaw);

    $cls = 'good';
    if ($pct < 60) {
        $cls = 'bad';
    } elseif ($pct < 100) {
        $cls = 'warn';
    }

    $row = [
        'name'         => (string)$name,        // interne ID (Stats)
        'display_name' => (string)$displayName, // Anzeige (ggf. inkl. (s31de))
        'level'        => $level,
        'rank_group'   => $rankGroup,

        'a_done'     => $aDone,
        'a_total'    => $aTotal,
        'a_miss'     => $aMiss,
        'v_done'     => $vDone,
        'v_total'    => $vTotal,
        'v_miss'     => $vMiss,

        'done_total' => $doneTotal,
        'total'      => $total,
        'missing'    => $missing,
        'pct'        => $pct,
        'cls'        => $cls,
    ];

    $playersAll[] = $row;

    $sumDoneTotal += $doneTotal;
    if ($missing > 0) {
        $missingPlayersCount++;
    }
}

// KPI: Quote über alle Spieler
$quote = 0;
$countPlayersAll = count($playersAll);
if ($countPlayersAll > 0 && $totalFights > 0) {
    $quote = (int)round(($sumDoneTotal / ($countPlayersAll * $totalFights)) * 100);
}

// Verteilung
$dist100 = 0;
$dist60  = 0;
$distBad = 0;

foreach ($playersAll as $p) {
    $pPct = (int)$p['pct'];
    if ($pPct >= 100) {
        $dist100++;
    } elseif ($pPct >= 60) {
        $dist60++;
    } else {
        $distBad++;
    }
}

// Top fehlende (global, unabhängig von Filter)
$topMissing = $playersAll;
usort($topMissing, function (array $a, array $b): int {
    if ($a['missing'] !== $b['missing']) {
        return $b['missing'] <=> $a['missing'];
    }
    return strcasecmp((string)$a['display_name'], (string)$b['display_name']);
});
$topMissing = array_slice($topMissing, 0, 5);

// Filter (nur in der Tabelle/Export)
$players = $playersAll;

if ($onlyMissing) {
    $players = array_values(array_filter($players, fn(array $p): bool => (int)$p['missing'] > 0));
}

if ($q !== '') {
    $players = array_values(array_filter($players, function (array $p) use ($q): bool {
        $dn = (string)($p['display_name'] ?? $p['name'] ?? '');
        return sf_stripos_u($dn, $q) !== false;
    }));
}

// Sort: zuerst nach Gildenrang, dann nach Level
usort($players, function (array $a, array $b): int {
    // 1) Ranggruppe: Leader (0) -> Officer (1) -> Member (2)
    $rgA = (int)($a['rank_group'] ?? 2);
    $rgB = (int)($b['rank_group'] ?? 2);
    if ($rgA !== $rgB) {
        return $rgA <=> $rgB;
    }

    // 2) Level absteigend
    $lvA = (int)($a['level'] ?? 0);
    $lvB = (int)($b['level'] ?? 0);
    if ($lvA !== $lvB) {
        return $lvB <=> $lvA;
    }

    // 3) Optional: fehlende Kämpfe (damit Problemfälle höher stehen)
    if ((int)$a['missing'] !== (int)$b['missing']) {
        return (int)$b['missing'] <=> (int)$a['missing'];
    }

    $na = (string)($a['display_name'] ?? $a['name'] ?? '');
    $nb = (string)($b['display_name'] ?? $b['name'] ?? '');
    return strcasecmp($na, $nb);
});

// CSV Export (aktueller Filter)
if ($export === 'csv' && $guildId > 0 && $guild) {
    $safeGuild = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)$guild['name']);
    $safeSrv   = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)$guild['server']);
    $fnDate    = date('Y-m-d');
    $filename  = "sf-report-{$safeSrv}-{$safeGuild}-{$fnDate}.csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM (Excel-freundlich)
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Spieler', 'Angriffe', 'Verteidigungen', 'Gesamt', 'Quote', 'Fehlend']);

    foreach ($players as $p) {
        $a = (int)$p['a_done'] . '/' . (int)$p['a_total'];
        $v = (int)$p['v_done'] . '/' . (int)$p['v_total'];
        $g = (int)$p['done_total'] . '/' . (int)$p['total'];
        $pct = (int)$p['pct'] . '%';
        $miss = (int)$p['missing'];

        $dn = (string)($p['display_name'] ?? $p['name'] ?? '');
        fputcsv($out, [$dn, $a, $v, $g, $pct, (string)$miss]);
    }

    fclose($out);
    exit;
}

ob_start();
?>

<?php
$importFlag     = (string)($_GET['import'] ?? '');
$importType     = (string)($_GET['type'] ?? '');
$importOpponent = trim((string)($_GET['opponent'] ?? ''));
$importPlayers  = trim((string)($_GET['players'] ?? ''));

$importTypeLabel = ($importType === 'attack') ? 'Angriff' : (($importType === 'defense') ? 'Verteidigung' : 'Kampf');
$details = $importTypeLabel;

if ($importOpponent !== '') {
    $details .= ' gegen „' . $importOpponent . '“';
}
if ($importPlayers !== '' && ctype_digit($importPlayers)) {
    $details .= ' (' . $importPlayers . ' Einträge)';
}
?>

<?php if ($importFlag === 'ok'): ?>
  <div class="notice success narrow">
    <div><strong>Import erfolgreich.</strong></div>
    <div><?= e($details) ?></div>
  </div>
<?php elseif ($importFlag === 'dup'): ?>
  <div class="notice warn narrow">
    <div><strong>Duplikat erkannt.</strong></div>
    <div><?= e($importTypeLabel) ?> gegen „<?= e($importOpponent) ?>“ war bereits importiert.</div>
  </div>
<?php endif; ?>

<?php
$qid        = ($guildId > 0) ? ('?guild_id=' . $guildId) : '';
$importHref = url('/sf-auswertung/' . $qid);
$reportHref = url('/sf-auswertung/report.php' . $qid);

$formParams = [
    'guild_id' => $guildId > 0 ? $guildId : 0,
];
if ($onlyMissing) {
    $formParams['missing'] = 1;
}
if ($q !== '') {
    $formParams['q'] = $q;
}

$exportParams = $formParams;
$exportParams['export'] = 'csv';
$exportHref = url('/sf-auswertung/report.php?' . http_build_query($exportParams));
?>

<div class="sf-report">

  <div class="sf-topbar">
    <div style="display:flex; gap:14px; align-items:center;">
      <?php if ($guild && !empty($guild['crest_file'])): ?>
        <img
          src="<?= e(url('/uploads/crests/' . $guild['crest_file'])) ?>"
          alt="Wappen"
          style="height:72px; width:72px; object-fit:cover; border-radius:14px; border:1px solid rgba(255,255,255,.10);"
        >
      <?php endif; ?>

      <div class="sf-titleblock">
        <h2 style="margin:0;">
          <?php if ($guild): ?>
            SF-Auswertung · <?= e($guild['server']) ?> – <?= e($guild['name']) ?>
          <?php else: ?>
            SF-Auswertung · Report
          <?php endif; ?>
        </h2>

        <div class="sf-subtitle">
          <?php if ($guild && $lastImportNice): ?>
            Letzte Aktualisierung: <strong><?= e($lastImportNice) ?></strong>
          <?php elseif ($guild): ?>
            Letzte Aktualisierung: <strong>–</strong>
          <?php else: ?>
            Bitte oben eine Gilde auswählen.
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <a class="btn" href="<?= e($importHref) ?>">Import</a>
      <a class="btn active" href="<?= e($reportHref) ?>">Report</a>

      <form class="sf-filters" method="get">
        <select name="guild_id" class="sf-input" onchange="this.form.submit()">
          <option value="0">– Gilde wählen –</option>
          <?php foreach ($guilds as $g): ?>
            <option value="<?= (int)$g['id'] ?>" <?= $guildId === (int)$g['id'] ? 'selected' : '' ?>>
              <?= e($g['name']) ?> (<?= e($g['server']) ?>)
            </option>
          <?php endforeach; ?>
        </select>

        <label class="sf-pill" title="Nur Spieler mit fehlenden Kämpfen anzeigen">
          <input type="checkbox" name="missing" value="1" <?= $onlyMissing ? 'checked' : '' ?> onchange="this.form.submit()">
          Nur Fehlende
        </label>

        <input class="sf-input" type="search" name="q" value="<?= e($q) ?>" placeholder="Suche…" autocomplete="off">
        <noscript><button class="btn" type="submit">Anwenden</button></noscript>
      </form>
    </div>
  </div>

  <?php if ($guildId <= 0): ?>

    <div class="sf-card">
      <p style="margin:0; opacity:.85;">Bitte oben eine Gilde auswählen.</p>
    </div>

  <?php else: ?>

    <?php if ($totalFights <= 0): ?>
      <div class="sf-card">
        <p style="margin:0; opacity:.85;">Noch keine Daten vorhanden. Importiere zuerst eine oder mehrere Kämpfe.</p>
      </div>
    <?php else: ?>

      <div class="sf-grid-kpi">
        <div class="sf-card">
          <div class="sf-kpi-label">Angriffe</div>
          <div class="sf-kpi-value"><?= (int)$attacksTotal ?></div>
          <div class="sf-kpi-hint">Kämpfe je Spieler</div>
        </div>

        <div class="sf-card">
          <div class="sf-kpi-label">Verteidigungen</div>
          <div class="sf-kpi-value"><?= (int)$defensesTotal ?></div>
          <div class="sf-kpi-hint">Kämpfe je Spieler</div>
        </div>

        <div class="sf-card">
          <div class="sf-kpi-label">Quote</div>
          <div class="sf-kpi-value"><?= (int)$quote ?>%</div>
          <div class="sf-kpi-hint good">Ø Teilnahme gesamt</div>
        </div>

        <div class="sf-card">
          <div class="sf-kpi-label">Fehlende</div>
          <div class="sf-kpi-value"><?= (int)$missingPlayersCount ?></div>
          <div class="sf-kpi-hint bad">Nicht teilgenommen</div>
        </div>
      </div>

      <div class="sf-split">

        <div class="sf-card">
          <div class="sf-table-head">
            <div>
              <h3>Spieler (kompakt)</h3>
              <div class="sf-table-note">
                Sortierung: Rang (Anführer/Offiziere/Mitglieder), dann Level · Klick auf Zeile = Details · Anzeige: <strong><?= (int)count($players) ?></strong> Spieler
              </div>
            </div>
          </div>

          <table class="sf-table">
            <thead>
              <tr>
                <th style="width:38%">Spieler</th>
                <th style="width:10%">A</th>
                <th style="width:10%">V</th>
                <th style="width:12%">Gesamt</th>
                <th style="width:30%">Quote</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$players): ?>
                <tr>
                  <td colspan="5" style="opacity:.8;">Keine Treffer (Filter zu streng?).</td>
                </tr>
              <?php else: ?>
                <?php foreach ($players as $p): ?>
                  <?php
                    $displayName = (string)($p['display_name'] ?? $p['name'] ?? '');
                    $aDone  = (int)$p['a_done'];
                    $aTotal = (int)$p['a_total'];
                    $vDone  = (int)$p['v_done'];
                    $vTotal = (int)$p['v_total'];

                    $done   = (int)$p['done_total'];
                    $total  = (int)$p['total'];

                    $miss   = (int)$p['missing'];
                    $pct    = (int)$p['pct'];
                    $cls    = (string)$p['cls'];

                    $aCls = ($aTotal > 0 && $aDone >= $aTotal) ? 'good' : (($aDone <= 0) ? 'bad' : 'warn');
                    $vCls = ($vTotal > 0 && $vDone >= $vTotal) ? 'good' : (($vDone <= 0) ? 'bad' : 'warn');
                  ?>

                  <tr
                    class="sf-row"
                    data-player="<?= e($displayName) ?>"
                    data-a-done="<?= $aDone ?>"
                    data-a-total="<?= $aTotal ?>"
                    data-v-done="<?= $vDone ?>"
                    data-v-total="<?= $vTotal ?>"
                    data-done="<?= $done ?>"
                    data-total="<?= $total ?>"
                    data-miss="<?= $miss ?>"
                    data-pct="<?= $pct ?>"
                  >
                    <td>
                      <?= e($displayName) ?>
                      <?php if (!empty($p['level'])): ?>
                        <span class="sf-mini">(<?= (int)$p['level'] ?>)</span>
                      <?php endif; ?>
                      <?php if ($miss > 0): ?>
                        <span style="margin-left:10px" class="sf-miss-badge">-<?= $miss ?></span>
                      <?php endif; ?>
                    </td>

                    <td class="sf-num <?= e($aCls) ?>"><?= $aDone ?>/<?= $aTotal ?></td>
                    <td class="sf-num <?= e($vCls) ?>"><?= $vDone ?>/<?= $vTotal ?></td>
                    <td class="sf-num <?= e($cls) ?>"><?= $done ?>/<?= $total ?></td>

                    <td>
                      <div class="sf-progress <?= e($cls) ?>"><span style="width: <?= $pct ?>%"></span></div>
                      <div class="sf-mini" style="margin-top:6px; text-align:right;"><?= $pct ?>%</div>
                    </td>
                  </tr>

                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="sf-aside">

          <div class="sf-card">
            <h3>Auffälligkeiten</h3>
          </div>

          <div class="sf-card">
            <div style="display:flex; align-items:center; justify-content:space-between;">
              <div style="font-weight:700;">Top-Fehlende</div>
              <div class="sf-mini"><?= (int)$totalFights ?> möglich</div>
            </div>

            <div class="sf-list">
              <?php foreach ($topMissing as $row): ?>
                <div class="sf-item">
                  <div><?= e((string)$row['display_name']) ?></div>
                  <div class="sf-right"><?= (int)$row['done_total'] ?>/<?= (int)$row['total'] ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="sf-card">
            <div style="font-weight:700;">Verteilung</div>
            <div class="sf-list">
              <div class="sf-item">
                <div>100% (<?= (int)$totalFights ?>/<?= (int)$totalFights ?>)</div>
                <div class="sf-right" style="color:var(--sf-good)"><?= (int)$dist100 ?> Spieler</div>
              </div>
              <div class="sf-item">
                <div>60–99%</div>
                <div class="sf-right" style="color:var(--sf-warn)"><?= (int)$dist60 ?> Spieler</div>
              </div>
              <div class="sf-item">
                <div>&lt; 60%</div>
                <div class="sf-right"><?= (int)$distBad ?> Spieler</div>
              </div>
            </div>
          </div>

          <div class="sf-card">
              <div style="font-weight:700;">Aktion</div>
              <div class="sf-list">
                  <div class="sf-item">
                      <div>Export CSV</div>
                      <div class="sf-right" style="color:#7aa7ff;">
                          <a class="btn" href="<?= e($exportHref) ?>" style="padding:6px 10px; border-radius:999px;">Download</a>
                      </div>
                  </div>
              </div>
          </div>
            
        </div>
          
      </div>

      <!-- Details Modal -->
      <dialog id="playerDialog" style="border:1px solid rgba(255,255,255,.12); border-radius:16px; background:#0c0c0e; color:#fff; padding:0; width:min(520px, 92vw);">
        <div style="padding:16px 16px 10px; border-bottom:1px solid rgba(255,255,255,.08); display:flex; align-items:center; justify-content:space-between; gap:12px;">
          <div>
            <div id="pdName" style="font-size:18px; font-weight:800;">Spieler</div>
            <div id="pdMeta" style="opacity:.75; font-size:13px; margin-top:3px;">—</div>
          </div>
          <button id="pdClose" class="btn" type="button" style="padding:6px 12px;">Schließen</button>
        </div>

        <div style="padding:14px 16px 16px;">
          <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
            <div class="sf-card" style="padding:12px;">
              <div class="sf-kpi-label">Angriffe</div>
              <div id="pdA" class="sf-kpi-value" style="font-size:26px;">0/0</div>
            </div>
            <div class="sf-card" style="padding:12px;">
              <div class="sf-kpi-label">Verteidigungen</div>
              <div id="pdV" class="sf-kpi-value" style="font-size:26px;">0/0</div>
            </div>
            <div class="sf-card" style="padding:12px;">
              <div class="sf-kpi-label">Gesamt</div>
              <div id="pdG" class="sf-kpi-value" style="font-size:26px;">0/0</div>
            </div>
            <div class="sf-card" style="padding:12px;">
              <div class="sf-kpi-label">Fehlend</div>
              <div id="pdM" class="sf-kpi-value" style="font-size:26px;">0</div>
            </div>
          </div>

          <div style="margin-top:12px;">
            <div class="sf-kpi-label" style="margin-bottom:8px;">Quote</div>
            <div class="sf-progress" id="pdBar"><span style="width:0%"></span></div>
            <div id="pdPct" class="sf-mini" style="margin-top:6px; text-align:right;">0%</div>
          </div>
        </div>
      </dialog>

      <script>
        (function () {
          const dlg = document.getElementById('playerDialog');
          const btnClose = document.getElementById('pdClose');

          function setBar(pct) {
            const bar = document.getElementById('pdBar');
            const span = bar.querySelector('span');
            span.style.width = pct + '%';

            bar.classList.remove('good', 'warn', 'bad');
            if (pct < 60) bar.classList.add('bad');
            else if (pct < 100) bar.classList.add('warn');
            else bar.classList.add('good');
          }

          function openDetails(tr) {
            const name = tr.dataset.player || '';
            const aDone = tr.dataset.aDone || '0';
            const aTotal = tr.dataset.aTotal || '0';
            const vDone = tr.dataset.vDone || '0';
            const vTotal = tr.dataset.vTotal || '0';
            const done = tr.dataset.done || '0';
            const total = tr.dataset.total || '0';
            const miss = tr.dataset.miss || '0';
            const pct = parseInt(tr.dataset.pct || '0', 10);

            document.getElementById('pdName').textContent = name;
            document.getElementById('pdMeta').textContent = 'Klick in der Tabelle = schnelle Details';

            document.getElementById('pdA').textContent = aDone + '/' + aTotal;
            document.getElementById('pdV').textContent = vDone + '/' + vTotal;
            document.getElementById('pdG').textContent = done + '/' + total;
            document.getElementById('pdM').textContent = miss;

            document.getElementById('pdPct').textContent = pct + '%';
            setBar(isNaN(pct) ? 0 : pct);

            if (typeof dlg.showModal === 'function') {
              dlg.showModal();
            }
          }

          document.querySelectorAll('.sf-report .sf-row').forEach(tr => {
            tr.addEventListener('click', () => openDetails(tr));
          });

          btnClose.addEventListener('click', () => dlg.close());
          dlg.addEventListener('click', (e) => {
            const rect = dlg.getBoundingClientRect();
            const inDialog = (
              e.clientX >= rect.left && e.clientX <= rect.right &&
              e.clientY >= rect.top && e.clientY <= rect.bottom
            );
            if (!inDialog) dlg.close();
          });
        })();
      </script>

    <?php endif; ?>

  <?php endif; ?>

</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../../app/views/layout.php';
