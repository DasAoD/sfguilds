<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = $path;
$pdo = db();

// Setup-Flag (nur wenn du es bewusst setzt)
$setupFlag = __DIR__ . '/../storage/allow_setup';

$usersCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$needsSetup = ($usersCount === 0);

if ($needsSetup && is_file($setupFlag)) {
    if ($route === '/' || $route === '' || $route === '/index.php') {
        redirect('/admin/');
        exit;
    }
}

// "/" und "/index.php" sollen die Gildenliste zeigen – ohne Redirect (URL bleibt "/")
if ($route === '/' || $route === '' || $route === '/index.php') {
    $route = '/guilds';
}

// Trailing slash normalisieren (aber "/" behalten)
if ($route !== '/' && str_ends_with($route, '/')) {
    $route = rtrim($route, '/');
}

// Routing
if ($route === '/guilds') {
    $pdo = db();
$stmt = $pdo->query(
    "SELECT g.*,
            (SELECT COUNT(*)
               FROM members m
              WHERE m.guild_id = g.id
                AND (m.left_at  IS NULL OR TRIM(m.left_at)  = '')
				AND (m.fired_at IS NULL OR TRIM(m.fired_at) = '')
            ) AS members_active
       FROM guilds g
      ORDER BY g.server COLLATE NOCASE, g.name COLLATE NOCASE"
);
    $guilds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    view('guilds', ['guilds' => $guilds]);
    exit;
}

if (preg_match('#^/guild/(\d+)$#', $route, $m)) {
    $id = (int)$m[1];
    $pdo = db();

$stmt = $pdo->prepare(
    "SELECT g.*,
            (SELECT COUNT(*)
               FROM members m
              WHERE m.guild_id = g.id
                AND (m.left_at  IS NULL OR TRIM(m.left_at)  = '')
                AND (m.fired_at IS NULL OR TRIM(m.fired_at) = '')
            ) AS members_active
       FROM guilds g
      WHERE g.id = :id"
);
$stmt->execute([':id' => $id]);
$guild = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$guild) {
        http_response_code(404);
        echo 'Guild not found';
        exit;
    }

$stmt = $pdo->prepare(
    "SELECT *
       FROM members
      WHERE guild_id = :id
      ORDER BY
        CASE
          WHEN TRIM(rank) = 'Anführer' THEN 0
          WHEN TRIM(rank) = 'Offizier' THEN 1
          ELSE 2
        END ASC,
        CASE WHEN last_online IS NULL OR last_online = '' THEN 1 ELSE 0 END,
        CASE
          WHEN last_online LIKE '____-__-__%' THEN substr(last_online,1,10)
          WHEN last_online LIKE '__.__.____%' THEN substr(last_online,7,4) || '-' || substr(last_online,4,2) || '-' || substr(last_online,1,2)
          ELSE last_online
        END DESC,
        level DESC,
        name COLLATE NOCASE"
);

    $stmt->execute([':id' => $id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = count($members);
    $fired = 0;
    $left  = 0;
    foreach ($members as $r) {
        if (!empty($r['fired_at'])) $fired++;
        if (!empty($r['left_at']))  $left++;
    }
    $active = $total - $fired - $left;

view('guild', [
    'guild' => $guild,
    'members' => $members,
    'isAdmin' => isAdmin(),
    'counts' => ['active' => $active, 'total' => $total, 'fired' => $fired, 'left' => $left],
]);
    exit;
}

// alte Query-URL weiter unterstützen: /guild?id=123
if ($route === '/guild') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        header('Location: /guild/' . $id, true, 302);
        exit;
    }
}

http_response_code(404);
echo '404';
