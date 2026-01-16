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

$perGuildLimit = 200;  // Einträge pro Gilde anzeigen
$scanLimit     = 5000; // wie viele Battles insgesamt scannen

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

// --- Gilden laden
$guilds = $pdo->query("SELECT id, name FROM guilds ORDER BY name")->fetchAll();

$rowsByGuild = [];
$counts = [];
foreach ($guilds as $g) {
	$gid = (int)$g['id'];
	$rowsByGuild[$gid] = [];
	$counts[$gid] = 0;
}

// --- Spalten prüfen (damit die Seite nicht crasht, falls Schema mal abweicht)
$info = $pdo->query("PRAGMA table_info(sf_eval_battles)")->fetchAll();
$cols = array_map(static fn($r) => (string)$r['name'], $info);

$need = ['guild_id', 'battle_type', 'battle_date', 'battle_time'];
$hasAll = true;
foreach ($need as $c) {
	if (!in_array($c, $cols, true)) { $hasAll = false; break; }
}

$mapType = static function(string $t): string {
	$t = strtolower(trim($t));
	if ($t === 'attack') return 'Angriff';
	if ($t === 'defense') return 'Verteidigung';
	return 'Kampf';
};

$fmtWhen = static function(string $date, string $time): string {
	// DB: 2026-01-16 + 17:45 -> Anzeige: 16.01.2026 17:45
	$dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
	if ($dt instanceof DateTime) return $dt->format('d.m.Y H:i');
	// fallback
	return trim($date . ' ' . $time);
};

if ($hasAll) {
	$sql = "
		SELECT guild_id, battle_type, battle_date, battle_time
		FROM sf_eval_battles
		ORDER BY battle_date DESC, battle_time DESC, id DESC
		LIMIT :lim
	";
	$stmt = $pdo->prepare($sql);
	$stmt->bindValue(':lim', $scanLimit, PDO::PARAM_INT);
	$stmt->execute();

	$done = 0;
	$totalGuilds = count($guilds);

	while ($row = $stmt->fetch()) {
		$gid = (int)$row['guild_id'];
		if (!isset($rowsByGuild[$gid]) || $counts[$gid] >= $perGuildLimit) continue;

		$when = $fmtWhen((string)$row['battle_date'], (string)$row['battle_time']);
		$kind = $mapType((string)$row['battle_type']);

		$rowsByGuild[$gid][] = ['when' => $when, 'kind' => $kind];
		$counts[$gid]++;

		if ($counts[$gid] === $perGuildLimit) $done++;
		if ($done >= $totalGuilds) break;
	}
} else {
	// Schema passt nicht -> Seite zeigt wenigstens leer statt kaputt
	// (hier könnten wir später noch einen Fallback einbauen)
}

// --- Rendern über Layout (Layout erwartet i.d.R. $view)
$view = __DIR__ . '/../../../app/views/sf_eval_fights.php';
require __DIR__ . '/../../../app/views/layout.php';
