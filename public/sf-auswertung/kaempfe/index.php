<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';

if (!function_exists('isAdmin') || !isAdmin()) {
	$next = $_SERVER['REQUEST_URI'] ?? '/sf-auswertung/kaempfe/';
	header('Location: ' . url('/admin/login.php?next=' . rawurlencode($next)));
	exit;
}

$title = 'Kämpfe';

// Admin-Flag (Seite darf sichtbar bleiben, aber POST-Aktionen sind admin-only)
$isAdminUser = function_exists('isAdmin') ? (bool)isAdmin() : false;

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

// --- Session + CSRF (für Delete/Move)
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}
if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrfToken = (string)$_SESSION['csrf_token'];

// --- Helpers
$mapType = static function(string $t): string {
	$t = strtolower(trim($t));
	if ($t === 'attack') return 'Angriff';
	if ($t === 'defense') return 'Verteidigung';
	return 'Kampf';
};

$monthNamesDe = [
	1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
	7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
];

// Limits
$perGuildLimit = 200;   // Details-Limit pro Tag
$scanLimit     = 40000; // (aktuell nicht genutzt, bleibt als Doku)

// --- POST Actions (delete/move) - admin-only
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	if (!$isAdminUser) {
		http_response_code(403);
		echo "Forbidden";
		exit;
	}

	$token = (string)($_POST['csrf_token'] ?? '');
	if (!hash_equals($csrfToken, $token)) {
		http_response_code(400);
		echo "Bad Request (CSRF)";
		exit;
	}

	$action = (string)($_POST['action'] ?? '');

	// Redirect zurück zur aktuellen Ansicht
	$m   = (string)($_POST['m'] ?? '');
	$g   = (int)($_POST['g'] ?? 0);
	$gid = (int)($_POST['gid'] ?? 0);
	$d   = (string)($_POST['d'] ?? '');

	$qs = [];
	if (preg_match('/^\d{4}-\d{2}$/', $m)) $qs[] = 'm=' . rawurlencode($m);
	if ($g > 0) $qs[] = 'g=' . $g;
	if ($gid > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
		$qs[] = 'gid=' . $gid;
		$qs[] = 'd=' . rawurlencode($d);
	}

	$redirect = '/sf-auswertung/kaempfe/' . ($qs ? ('?' . implode('&', $qs)) : '');
	if ($gid > 0) $redirect .= '#g' . $gid;

	try {
		if ($action === 'delete_battle') {
			$battleId = (int)($_POST['battle_id'] ?? 0);
			if ($battleId > 0) {
				$pdo->beginTransaction();

				$st = $pdo->prepare("DELETE FROM sf_eval_participants WHERE battle_id = :id");
				$st->execute([':id' => $battleId]);

				$st = $pdo->prepare("DELETE FROM sf_eval_battles WHERE id = :id");
				$st->execute([':id' => $battleId]);

				$pdo->commit();
			}
		} elseif ($action === 'move_battle') {
			$battleId   = (int)($_POST['battle_id'] ?? 0);
			$newGuildId = (int)($_POST['new_guild_id'] ?? 0);

			if ($battleId > 0 && $newGuildId > 0) {
				$st = $pdo->prepare("UPDATE sf_eval_battles SET guild_id = :g WHERE id = :id");
				$st->execute([':g' => $newGuildId, ':id' => $battleId]);
			}
		}
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		http_response_code(500);
		echo "Server Error";
		exit;
	}

	header('Location: ' . $redirect);
	exit;
}

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

$monthStart   = new DateTimeImmutable($monthKey . '-01');
$monthEnd     = $monthStart->modify('last day of this month');
$daysInMonth  = (int)$monthStart->format('t');
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

// Einzelansicht: ?g=2
$filterGuildId = isset($_GET['g']) ? (int)$_GET['g'] : 0;

// --- Gilden laden (für Anzeige + Dropdown)
$allGuilds = $pdo->query("SELECT id, name FROM guilds ORDER BY name")->fetchAll();
$guilds = $allGuilds;

if ($filterGuildId > 0) {
	$guilds = array_values(array_filter($guilds, static function($x) use ($filterGuildId) {
		return (int)$x['id'] === $filterGuildId;
	}));
}

// --- Monats-Aggregation laden
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
		SELECT id, battle_time, battle_type, opponent_guild
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
			'id'   => (int)$row['id'],
			'time' => (string)$row['battle_time'],
			'kind' => $mapType((string)$row['battle_type']),
			'opp'  => (string)$row['opponent_guild'],
		];
	}
}

// --- Rendern über Layout ($content)
$viewFile = __DIR__ . '/../../../app/views/sf_eval_fights.php';

ob_start();
require $viewFile;
$content = ob_get_clean();

require __DIR__ . '/../../../app/views/layout.php';
