<?php
	require_once __DIR__ . "/../../app/bootstrap.php";
	
	unset($_SESSION["admin_user_id"], $_SESSION["admin_username"]);
	session_regenerate_id(true);
	
	redirect("/");
