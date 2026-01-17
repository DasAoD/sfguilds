<?php
	
	function e(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
	}
	
	function url(string $path): string
	{
		$base = rtrim((string) ($_ENV["APP_BASE"] ?? ""), "/");
		if ($base === "") {
			return $path;
		}
		return $base . $path;
	}
	
	function redirect(string $to): void
	{
		header("Location: " . $to);
		exit();
	}
	
	function view(string $name, array $vars = []): void
	{
		extract($vars);
		$viewFile = __DIR__ . "/views/" . $name . ".php";
		if (!is_file($viewFile)) {
			http_response_code(500);
			echo "View not found: " . e($name);
			exit();
		}
		
		$layout = __DIR__ . "/views/layout.php";
		if (is_file($layout)) {
			include $layout;
			return;
		}
		
		include $viewFile;
	}
	
	function flash_set(string $key, string $value): void
	{
		$_SESSION["flash"][$key] = $value;
	}
	
	function flash_get(string $key): ?string
	{
		if (!isset($_SESSION["flash"][$key])) {
			return null;
		}
		$v = (string) $_SESSION["flash"][$key];
		unset($_SESSION["flash"][$key]);
		return $v;
	}
	
	function csrf_token(): string
	{
		if (empty($_SESSION["csrf"])) {
			$_SESSION["csrf"] = bin2hex(random_bytes(16));
		}
		return (string) $_SESSION["csrf"];
	}
	
	function csrf_check(): void
	{
		$token = (string) ($_POST["csrf"] ?? "");
		if ($token === "" || $token !== (string) ($_SESSION["csrf"] ?? "")) {
			http_response_code(400);
			echo "CSRF check failed";
			exit();
		}
	}
	
	function normalize_number($v): ?int
	{
		if ($v === null || $v === "") {
			return null;
		}
		if (is_string($v)) {
			$v = trim($v);
			if ($v === "") {
				return null;
			}
			$v = str_replace([".", " "], "", $v);
			$v = str_replace(",", ".", $v);
		}
		if (!is_numeric($v)) {
			return null;
		}
		return (int) round((float) $v);
	}
	
	function normalize_date(?string $v): ?string
	{
		if ($v === null) {
			return null;
		}
		$v = trim($v);
		if ($v === "") {
			return null;
		}
		return $v;
	}
	
function row_class(array $m): string
{
	$classes = ["row"];

	$fired = trim((string) ($m["fired_at"] ?? ""));
	$left  = trim((string) ($m["left_at"] ?? ""));

	if ($fired !== "") {
		$classes[] = "row-fired";
	} elseif ($left !== "") {
		$classes[] = "row-left";
	}

	$days = null;
	if (isset($m["days_offline"]) && $m["days_offline"] !== "" && $m["days_offline"] !== null) {
		$days = (int) $m["days_offline"];
	} else {
		$days = memberDaysOffline($m); // negative Werte bleiben absichtlich so
	}

	if ($days !== null && $days !== 0 && abs($days) >= 14) {
		$classes[] = "row-bold";
	}

	if (!empty($m["mentor"])) {
		$classes[] = "row-bold";
	}

	return implode(" ", $classes);
}

	function isAdmin(): bool
	{
		// App-Login (users Tabelle) ODER vorgeschaltetes BasicAuth (bootstrap setzt is_admin/admin_username)
		return !empty($_SESSION["admin_user_id"]) ||
        !empty($_SESSION["is_admin"]) ||
        !empty($_SESSION["admin_username"]) ||
        !empty($_SESSION["admin_user"]);
	}
	
	function adminUsername(): ?string
	{
		if (
        isset($_SESSION["admin_username"]) &&
        $_SESSION["admin_username"] !== ""
		) {
			return (string) $_SESSION["admin_username"];
		}
		if (isset($_SESSION["admin_user"]) && $_SESSION["admin_user"] !== "") {
			return (string) $_SESSION["admin_user"];
		}
		return null;
	}
	
	function requireAdmin(): void
	{
		if (isAdmin()) {
			return;
		}
		
		$next = $_SERVER["REQUEST_URI"] ?? "/admin/";
		if (!is_string($next) || $next === "" || $next[0] !== "/") {
			$next = "/admin/";
		}
		
		redirect("/admin/login.php?next=" . rawurlencode($next));
	}
	
	// ------------------------------------------------------------
	// Backwards-compatible helpers for older pages (members.php etc.)
	// ------------------------------------------------------------
	
	if (!function_exists("h")) {
		function h($s): string
		{
			return e((string) $s);
		}
	}
	
	if (!function_exists("flash")) {
		function flash(string $key, ?string $value = null): ?string
		{
			if ($value === null) {
				return flash_get($key);
			}
			flash_set($key, $value);
			return null;
		}
	}
	
	function memberDaysOffline(array $m): ?int
	{
		$fired = trim((string) ($m["fired_at"] ?? ""));
		$left  = trim((string) ($m["left_at"] ?? ""));
		if ($fired !== "" || $left !== "") {
			return null; // Ex-Mitglied -> Tage offline leer lassen
		}

		// Immer aus last_online rechnen (Anzeige soll konsistent sein)
		$lo = trim((string) ($m["last_online"] ?? ""));
		if ($lo === "") {
			return null;
		}
		
		$dt = null;
		
		foreach (
        [
		"d.m.Y",
		"d.m.Y H:i",
		"d.m.Y H:i:s",
		"Y-m-d",
		"Y-m-d H:i",
		"Y-m-d H:i:s",
        ]
        as $fmt
		) {
			$tmp = DateTime::createFromFormat($fmt, $lo);
			if ($tmp instanceof DateTime) {
				$dt = $tmp;
				break;
			}
		}
		
		if (!($dt instanceof DateTime)) {
			return null;
		}
		
		// Nur Datum vergleichen (Uhrzeit ignorieren)
		$today = new DateTime("today");
		$dt->setTime(0, 0, 0);
		
		$diff = $today->diff($dt);
		
		// last_online in der Vergangenheit => negative Tage offline
		if ($diff->invert === 1) {
			return -((int) $diff->days);
		}
		
		// last_online heute oder in der Zukunft => 0
		return 0;
	}
	
	if (!function_exists("memberRowClass")) {
		function memberRowClass(array $m): string
		{
			$classes = [];
			
			$fired = trim((string) ($m["fired_at"] ?? ""));
			$left = trim((string) ($m["left_at"] ?? ""));
			$notes = trim((string) ($m["notes"] ?? ""));
			
			if ($fired !== "") {
				$classes[] = "row-fired";
				} elseif ($left !== "") {
				$classes[] = "row-left";
				} elseif ($notes !== "") {
				$classes[] = "row-note";
			}
			
			$days = memberDaysOffline($m);
			if ($days !== null && abs($days) >= 14) {
				$classes[] = "row-bold";
			}
			
			return implode(" ", $classes);
		}
	}
	
	if (!function_exists("normalizeDateDE")) {
		function normalizeDateDE(?string $v): ?string
		{
			if ($v === null) {
				return null;
			}
			$v = trim($v);
			if ($v === "") {
				return null;
			}
			
			// Excel/CSV hat manchmal "25.12.2025, 18:30"
			$v = str_replace(",", "", $v);
			$v = preg_replace("/\s+/", " ", $v);
			
			// Wenn es schon ISO ist, lassen wir es so.
			if (preg_match("/^\d{4}-\d{2}-\d{2}/", $v)) {
				return $v;
			}
			
			$formats = [
            "d.m.Y H:i:s",
            "d.m.Y H:i",
            "d.m.Y",
            "d.m.y H:i:s",
            "d.m.y H:i",
            "d.m.y",
			];
			
			foreach ($formats as $fmt) {
				$dt = DateTime::createFromFormat($fmt, $v);
				if ($dt instanceof DateTime) {
					// Wenn Uhrzeit im Format war -> ISO mit Sekunden, sonst nur Datum
					if (str_contains($fmt, "H")) {
						return $dt->format("Y-m-d H:i:s");
					}
					return $dt->format("Y-m-d");
				}
			}
			
			// Fallback: unverändert zurück (besser als Import-Abbruch)
			return $v;
		}
	}
	
	if (!function_exists("formatDateDE")) {
		function formatDateDE($value): string
		{
			$s = trim((string) $value);
			if ($s === "") {
				return "";
			}
			
			// Bereits deutsches Format, optional mit Uhrzeit
			if (
            preg_match(
			"/^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}:\d{2}(?::\d{2})?))?/",
			$s,
			$m,
            )
			) {
				$out = $m[1] . "." . $m[2] . "." . $m[3];
				if (!empty($m[4])) {
					$out .= " " . $m[4];
				}
				return $out;
			}
			
			// ISO-Format, optional mit Uhrzeit: YYYY-MM-DD[ HH:MM[:SS]] oder YYYY-MM-DDTHH:MM[:SS]
			if (
            preg_match(
			"/^(\d{4})-(\d{2})-(\d{2})(?:[ T]+(\d{2}:\d{2}(?::\d{2})?))?/",
			$s,
			$m,
            )
			) {
				$out = $m[3] . "." . $m[2] . "." . $m[1];
				if (!empty($m[4])) {
					$out .= " " . $m[4];
				}
				return $out;
			}
			
			// Fallback: unverändert
			return $s;
		}
	}
