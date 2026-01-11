<?php
	
	declare(strict_types=1);
	
	error_reporting(E_ALL);
	ini_set("display_errors", "0");
	ini_set("log_errors", "1");
	
	date_default_timezone_set("Europe/Berlin");
	
	/* Session-Härtung: MUSS vor session_start() kommen */
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => true, // HTTPS
        "httponly" => true,
        "samesite" => "Lax",
		]);
		session_start();
	}
	
	require __DIR__ . "/helpers.php";
	require __DIR__ . "/db.php";
	
	// Wenn nginx BasicAuth aktiv ist, setzt nginx $remote_user -> PHP bekommt i.d.R. REMOTE_USER
	if (!empty($_SERVER["REMOTE_USER"]) || !empty($_SERVER["PHP_AUTH_USER"])) {
		$_SESSION["is_admin"] = true;
		$_SESSION["admin_user"] =
        $_SERVER["REMOTE_USER"] ?? $_SERVER["PHP_AUTH_USER"];
	}
	
	// Mini-Error-Handler: bei fatalen Errors bekommst du wenigstens eine saubere 500
	set_exception_handler(function (Throwable $e): void {
		http_response_code(500);
		error_log((string) $e);
		echo "<h1>HTTP 500</h1><p>Interner Fehler.</p>";
	});
	
	// Fängt auch fatale Errors ab (z.B. "Allowed memory size exhausted")
	register_shutdown_function(function (): void {
		$err = error_get_last();
		if (!$err) {
			return;
		}
		
		$fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
		if (!in_array($err["type"], $fatalTypes, true)) {
			return;
		}
		
		// Logging
		$msg = sprintf(
        "FATAL: %s in %s:%d",
        $err["message"] ?? "unknown",
        $err["file"] ?? "unknown",
        $err["line"] ?? 0,
		);
		error_log($msg);
		
		// Ausgabe (so klein wie möglich halten, falls Memory-Probleme)
		if (!headers_sent()) {
			http_response_code(500);
			header("Content-Type: text/html; charset=utf-8");
		}
		
		echo "<h1>HTTP 500</h1><p>Interner Fehler.</p>";
	});
