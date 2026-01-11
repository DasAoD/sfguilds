<?php
	require_once __DIR__ . "/../app/bootstrap.php";
	
	$code = (int) ($_SERVER["REDIRECT_STATUS"] ?? 500);
	if (!in_array($code, [403, 404, 500, 502, 503, 504], true)) {
		$code = 500;
	}
	
	http_response_code($code);
	
	$map = [
    403 => [
	"title" => "Zugriff verweigert",
	"text" => "Du hast keine Berechtigung, diese Seite zu öffnen.",
    ],
    404 => [
	"title" => "Nicht gefunden",
	"text" => "Die angeforderte Seite wurde nicht gefunden.",
    ],
    500 => [
	"title" => "Interner Fehler",
	"text" => "Da ist intern etwas schiefgelaufen.",
    ],
    502 => [
	"title" => "Gateway Fehler",
	"text" => "Der Server hat eine ungültige Antwort erhalten.",
    ],
    503 => [
	"title" => "Dienst nicht verfügbar",
	"text" => "Der Dienst ist gerade nicht verfügbar.",
    ],
    504 => [
	"title" => "Timeout",
	"text" => "Die Anfrage hat zu lange gedauert.",
    ],
	];
	
	$info = $map[$code] ?? $map[500];
	
	view("layout", [
    "title" => $code . " – " . $info["title"],
    "content" => function () use ($code, $info) {
	?>
	<h1><?= e($info["title"]) ?></h1>
	<p class="flash err"><?= e($code . " – " . $info["text"]) ?></p>
	<p><a href="/">Zur Startseite</a></p>
    <?php
	},
	]);
