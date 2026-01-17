<?php
	require_once __DIR__ . "/../../app/bootstrap.php";
	
	$pdo = db();
	
	$next = (string) ($_GET["next"] ?? ($_POST["next"] ?? "/"));
	if ($next === "" || $next[0] !== "/" || str_starts_with($next, "//")) {
		$next = "/";
		<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
	}
	
	if (isAdmin()) {
		redirect($next);
		exit();
	}
	
	$error = "";
	
	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		csrf_check();
		$username = trim((string) ($_POST["username"] ?? ""));
		$password = (string) ($_POST["password"] ?? "");
		
		if ($username === "" || $password === "") {
			$error = "Bitte Benutzername und Passwort eingeben.";
			} else {
			$st = $pdo->prepare(
            "SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1",
			);
			$st->execute([":u" => $username]);
			$u = $st->fetch();
			
			if ($u && password_verify($password, (string) $u["password_hash"])) {
				session_regenerate_id(true);
				$_SESSION["admin_user_id"] = (int) $u["id"];
				$_SESSION["admin_username"] = (string) $u["username"];
				redirect($next);
				exit();
				} else {
				$error = "Benutzername oder Passwort ist falsch.";
			}
		}
	}
	
	view("login", [
    "title" => "Admin Login",
    "error" => $error,
    "next" => $next,
	]);
