<?php
	
	declare(strict_types=1);
	
	/**
		* SQLite DB connection + minimal schema/migration helper.
		*
		* Ziel: Bei frischem Start werden alle Tabellen (guilds, members, users) automatisch angelegt.
		* Bei bestehenden DBs werden fehlende Spalten ergänzt.
	*/
	
	function db(): PDO
	{
		static $pdo = null;
		if ($pdo instanceof PDO) {
			return $pdo;
		}
		
		$dbPath = __DIR__ . "/../storage/sfguilds.sqlite";
		
		// Stelle sicher, dass das storage-Verzeichnis existiert
		$storageDir = dirname($dbPath);
		if (!is_dir($storageDir)) {
			mkdir($storageDir, 0775, true);
		}
		
		$pdo = new PDO("sqlite:" . $dbPath);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		
		// SQLite sinnvoll konfigurieren
		$pdo->exec("PRAGMA foreign_keys = ON");
		$pdo->exec("PRAGMA journal_mode = WAL");
		
		ensureSchema($pdo);
		
		return $pdo;
	}
	
	function ensureSchema(PDO $pdo): void
	{
		// guilds
		$pdo->exec(
        "CREATE TABLE IF NOT EXISTS guilds (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		name TEXT NOT NULL,
		server TEXT NOT NULL,
		notes TEXT DEFAULT '',
		created_at TEXT NOT NULL DEFAULT (datetime('now')),
		tag TEXT,
		last_import_at TEXT,
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )",
		);
		
		// members
		$pdo->exec(
        "CREATE TABLE IF NOT EXISTS members (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		guild_id INTEGER NOT NULL,
		name TEXT NOT NULL,
		rank TEXT,
		level INTEGER,
		last_online TEXT,
		joined_at TEXT,
		gold INTEGER,
		mentor INTEGER,
		knight_hall INTEGER,
		guild_pet INTEGER,
		days_offline INTEGER,
		notes TEXT,
		fired_at TEXT,
		left_at TEXT,
		created_at TEXT NOT NULL DEFAULT (datetime('now')),
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )",
		);
		
		// users (Admin-Login)
		$pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		username TEXT NOT NULL UNIQUE,
		password_hash TEXT NOT NULL,
		created_at TEXT NOT NULL DEFAULT (datetime('now')),
		updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )",
		);
		
		// Fehlende Spalten nachziehen (für bestehende DBs / alte Installationen)
		ensureColumns($pdo, "guilds", [
        "tag" => "TEXT",
        "notes" => "TEXT DEFAULT ''",
        "last_import_at" => "TEXT",
        "updated_at" => "TEXT NOT NULL DEFAULT (datetime('now'))",
        "crest_file" => "TEXT",
		]);
		
		ensureColumns($pdo, "members", [
        "rank" => "TEXT",
        "notes" => "TEXT",
        "fired_at" => "TEXT",
        "left_at" => "TEXT",
        "updated_at" => "TEXT NOT NULL DEFAULT (datetime('now'))",
        "level" => "INTEGER",
        "last_online" => "TEXT",
        "joined_at" => "TEXT",
        "gold" => "INTEGER",
        "mentor" => "INTEGER",
        "knight_hall" => "INTEGER",
        "guild_pet" => "INTEGER",
        "days_offline" => "INTEGER",
		]);
		
		ensureColumns($pdo, "users", [
        "password_hash" => "TEXT",
        "updated_at" => "TEXT NOT NULL DEFAULT (datetime('now'))",
		]);
		
		$pdo->exec(
        "CREATE INDEX IF NOT EXISTS idx_members_guild_rank ON members(guild_id, rank)",
		);
		$pdo->exec(
        "CREATE INDEX IF NOT EXISTS idx_members_guild_name ON members(guild_id, name)",
		);
	}
	
	function ensureColumns(PDO $pdo, string $table, array $columns): void
	{
		foreach ($columns as $col => $typeSql) {
			if (!tableHasColumn($pdo, $table, $col)) {
				$pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$typeSql}");
			}
		}
	}
	
	function tableHasColumn(PDO $pdo, string $table, string $col): bool
	{
		$stmt = $pdo->query("PRAGMA table_info({$table})");
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $r) {
			if (($r["name"] ?? "") === $col) {
				return true;
			}
		}
		return false;
	}
