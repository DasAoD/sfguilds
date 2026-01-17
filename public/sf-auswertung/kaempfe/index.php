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

$perGuildLimit = 200;  // Details-Limit pro Tag (falls mal sehr viele Kämpfe am selben Tag)
$scanLimit     = 40000; // Monats-Aggregation: reicht locker, ist aber eh GROUP BY

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
$mapType = static function(string $t): string {
	$t = strtolower(trim($t));
	if ($t === 'attack') return 'Angriff';
	if ($t === 'defense') return 'Verteidigung';
	return 'Kampf';
};

$fmtDateDe = static function(string $ymd): string {
	$dt = DateTime::createFromFormat('Y-m-d', $ymd);
	return $dt ? $dt->format('d.m.Y') : $ymd;
};

$monthNamesDe = [
	1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
	7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
];

// --- Monat bestimmen: ?m=YYYY-MM, default = Monat des neuesten Kampfes (falls vorhanden)
$monthKey = isset($_GET['m']) && is_string($_GET['m']) ? trim($_GET['m']) : '';
if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
	$latest = $pdo->query("SELECT battle_date FROM sf_eval_battles ORDER BY battle_date DESC, battle_time DESC, id DESC LIMIT 1")->fetchColumn();
	if (is_string($latest) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $latest)) {
		$monthKey = substr($latest, 0, 7);
	} else {
		$monthKey = date('Y-m');
	}
}

$monthStart = new DateTimeImmutable($monthKey . '-01');
$monthEnd   = $monthStart->modify('last day of this month');
$daysInMonth = (int)$monthStart->format('t');
$firstWeekday = (int)$monthStart->format('N'); // 1=Mo ... 7=So

$prevMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');

$monthTitle = ($monthNamesDe[(int)$monthStart->format('n')] ?? $monthStart->format('m')) . ' ' . $monthStart->format('Y');

// --- Selected day (Details)
$selectedGuildId = isset($_GET['gid']) ? (int)$_GET['gid'] : 0;
$selectedDate = isset($_GET['d']) && is_string($_GET['d']) ? trim($_GET['d']) : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || substr($selectedDate, 0, 7) !== $monthKey) {
	$selectedDate = '';
	$selectedGuildId = 0;
}

// --- Gilden laden
$guilds = $pdo->query("SELECT id, name FROM guilds ORDER BY name")->fetchAll();

// --- Monats-Aggregation laden
// Ergebnis: countsByGuild[gid][day] = ['a'=>x,'d'=>y,'t'=>x+y]
$countsByGuild = [];
$monthTotalsByGuild = [];

foreach ($guilds as $g) {
	$gid = (int)$g['id'];
	$countsByGuild[$gid] = [];
	$monthTotalsByGuild[$gid] = ['a' => 0, 'd' => 0, 't' => 0];
}

$sqlAgg = "
	SELECT guild_id,
	       battle_date,
	       SUM(CASE WHEN battle_type='attack'  THEN 1 ELSE 0 END) AS attacks,
	       SUM(CASE WHEN battle_type='defense' THEN 1 ELSE 0 END) AS defenses
	FROM sf_eval_battles
	WHERE battle_date BETWEEN :start AND :end
	GROUP BY guild_id, battle_date
";
$stmtAgg = $pdo->prepare($sqlAgg);
$stmtAgg->execute([
	':start' => $monthStart->format('Y-m-d'),
	':end'   => $monthEnd->format('Y-m-d'),
]);

while ($r = $stmtAgg->fetch()) {
	$gid = (int)$r['guild_id'];
	if (!isset($countsByGuild[$gid])) continue;

	$date = (string)$r['battle_date']; // YYYY-MM-DD
	$day  = (int)substr($date, 8, 2);

	$a = (int)$r['attacks'];
	$d = (int)$r['defenses'];
	$t = $a + $d;

	$countsByGuild[$gid][$day] = ['a' => $a, 'd' => $d, 't' => $t];

	$monthTotalsByGuild[$gid]['a'] += $a;
	$monthTotalsByGuild[$gid]['d'] += $d;
	$monthTotalsByGuild[$gid]['t'] += $t;
}

// --- Tagesdetails laden (nur wenn ausgewählt)
$detailsRows = [];
if ($selectedGuildId > 0 && $selectedDate !== '') {
	$sqlDetail = "
		SELECT battle_time, battle_type, opponent_guild
		FROM sf_eval_battles
		WHERE guild_id = :gid AND battle_date = :d
		ORDER BY battle_time DESC, id DESC
		LIMIT :lim
	";
	$stmtDetail = $pdo->prepare($sqlDetail);
	$stmtDetail->bindValue(':gid', $selectedGuildId, PDO::PARAM_INT);
	$stmtDetail->bindValue(':d', $selectedDate, PDO::PARAM_STR);
	$stmtDetail->bindValue(':lim', $perGuildLimit, PDO::PARAM_INT);
	$stmtDetail->execute();

	while ($row = $stmtDetail->fetch()) {
		$detailsRows[] = [
			'time' => (string)$row['battle_time'],
			'kind' => $mapType((string)$row['battle_type']),
			'opp'  => (string)$row['opponent_guild'],
		];
	}
}

// --- Rendern über Layout ($content, damit es zu deinem layout.php passt)
$viewFile = __DIR__ . '/../../../app/views/sf_eval_fights.php';

ob_start();
require $viewFile;
$content = ob_get_clean();

require __DIR__ . '/../../../app/views/layout.php';
