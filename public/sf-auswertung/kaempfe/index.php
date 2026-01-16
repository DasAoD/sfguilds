<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';

$title = 'Kämpfe';

// optional: Admin-Schutz, falls vorhanden
if (function_exists('isAdmin') && !isAdmin()) {
	http_response_code(403);
	echo "Forbidden";
	exit;
}

$perGuildLimit = 200;   // Einträge pro Gilde anzeigen
$scanLimit     = 5000;  // wie viele Battles insgesamt scannen

// --- PDO finden oder fallback auf SQLite-Datei
$pdo = null;

foreach (['pdo', 'db', 'conn', 'sqlite'] as $var) {
	if (isset($$var) && $$var instanceof PDO) { $pdo = $$var; break; }
}
if (!$pdo && function_exists('db')) {
	$tmp = db();
	if ($tmp instanceof PDO) $pdo = $tmp;
}
if (!$pdo) {
	$dbPath = realpath(__DIR__ . '/../../../storage/sfguilds.sqlite') ?: (__DIR__ . '/../../../storage/sfguilds.sqlite');
	$pdo = new PDO('sqlite:' . $dbPath, null, null, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	]);
}

// --- Helpers
$pickColumn = static function(array $cols, array $candidates): ?string {
	$set = array_fill_keys($cols, true);
	foreach ($candidates as $c) {
		if (isset($set[$c])) return $c;
	}
	return null;
};

$fmtWhen = static function($v): string {
	if ($v === null) return '';
	// numeric epoch?
	if (is_int($v) || (is_string($v) && ctype_digit($v))) {
		$n = (int)$v;
		// ms epoch?
		if ($n > 2000000000000) $n = (int) floor($n / 1000);
		if ($n > 1000000000) return date('d.m.Y H:i', $n);
	}
	$ts = strtotime((string)$v);
	if ($ts !== false) return date('d.m.Y H:i', $ts);
	return (string)$v;
};

$kindFromType = static function(string $colName, $typeVal): string {
	$v = is_string($typeVal) ? strtolower(trim($typeVal)) : $typeVal;

	if (is_string($v)) {
		if (in_array($v, ['attack','att','a','angriff','offense','off'], true)) return 'Angriff';
		if (in_array($v, ['defense','def','d','verteidigung','defence'], true)) return 'Verteidigung';
	}

	$isNum = is_int($v) || is_float($v) || (is_string($v) && is_numeric($v));
	if ($isNum) {
		$n = (int)$v;
		$cn = strtolower($colName);
		if (str_contains($cn, 'attack'))  return $n === 1 ? 'Angriff' : 'Verteidigung';
		if (str_contains($cn, 'def'))     return $n === 1 ? 'Verteidigung' : 'Angriff';
		return $n === 1 ? 'Angriff' : 'Verteidigung';
	}

	return 'Kampf';
};

// --- Gilden laden
$guilds = $pdo->query("SELECT id, name FROM guilds ORDER BY name")->fetchAll();

$rowsByGuild = [];
$counts = [];
foreach ($guilds as $g) {
	$gid = (int)$g['id'];
	$rowsByGuild[$gid] = [];
	$counts[$gid] = 0;
}

// --- Spalten erkennen
$info = $pdo->query("PRAGMA table_info(sf_eval_battles)")->fetchAll();
$cols = array_map(static fn($r) => (string)$r['name'], $info);

$tsCol = $pickColumn($cols, [
	'battle_at','battle_time','occurred_at','started_at','ended_at',
	'created_at','timestamp','ts','time','datetime','date'
]) ?: 'rowid';

$attCol = $pickColumn($cols, ['attacker_guild_id','attacker_id','guild_attacker_id','atk_guild_id','atk_id']);
$defCol = $pickColumn($cols, ['defender_guild_id','defender_id','guild_defender_id','def_guild_id','def_id']);

$guildCol = $pickColumn($cols, ['guild_id','guild']);
$typeCol  = $pickColumn($cols, ['kind','type','direction','is_attack','is_defense','is_defend','attack','defense']);

// --- Daten holen & pro Gilde einsortieren
if ($attCol && $defCol) {
	$sql = "SELECT $tsCol AS ts, $attCol AS a, $defCol AS d
	        FROM sf_eval_battles
	        ORDER BY $tsCol DESC
	        LIMIT :lim";
	$stmt = $pdo->prepare($sql);
	$stmt->bindValue(':lim', $scanLimit, PDO::PARAM_INT);
	$stmt->execute();

	$done = 0;
	$totalGuilds = count($guilds);

	while ($row = $stmt->fetch()) {
		$when = $fmtWhen($row['ts'] ?? null);
		$a = (int)($row['a'] ?? 0);
		$d = (int)($row['d'] ?? 0);

		if (isset($rowsByGuild[$a]) && $counts[$a] < $perGuildLimit) {
			$rowsByGuild[$a][] = ['when' => $when, 'kind' => 'Angriff'];
			$counts[$a]++;
			if ($counts[$a] === $perGuildLimit) $done++;
		}
		if (isset($rowsByGuild[$d]) && $counts[$d] < $perGuildLimit) {
			$rowsByGuild[$d][] = ['when' => $when, 'kind' => 'Verteidigung'];
			$counts[$d]++;
			if ($counts[$d] === $perGuildLimit) $done++;
		}

		if ($done >= $totalGuilds) break;
	}
} elseif ($guildCol) {
	$selectType = $typeCol ? ", $typeCol AS t" : "";
	$sql = "SELECT $tsCol AS ts, $guildCol AS g $selectType
	        FROM sf_eval_battles
	        ORDER BY $tsCol DESC
	        LIMIT :lim";
	$stmt = $pdo->prepare($sql);
	$stmt->bindValue(':lim', $scanLimit, PDO::PARAM_INT);
	$stmt->execute();

	$done = 0;
	$totalGuilds = count($guilds);

	while ($row = $stmt->fetch()) {
		$gid = (int)($row['g'] ?? 0);
		if (!isset($rowsByGuild[$gid]) || $counts[$gid] >= $perGuildLimit) continue;

		$when = $fmtWhen($row['ts'] ?? null);
		$kind = $typeCol ? $kindFromType($typeCol, $row['t'] ?? null) : 'Kampf';

		$rowsByGuild[$gid][] = ['when' => $when, 'kind' => $kind];
		$counts[$gid]++;
		if ($counts[$gid] === $perGuildLimit) $done++;

		if ($done >= $totalGuilds) break;
	}
} else {
	// wenn weder attacker/defender noch guild_id existiert -> Seite läuft, aber leer
}

// --- Rendern über vorhandenes Layout
$viewFile = __DIR__ . '/../../../app/views/sf_eval_fights.php';

ob_start();
require $viewFile;
$content = ob_get_clean();

require __DIR__ . '/../../../app/views/layout.php';
