<?php
	declare(strict_types=1);
	
	require __DIR__ . "/../app/helpers.php";
	require __DIR__ . "/../app/db.php";
	
	function usage(): void
	{
		fwrite(STDERR, "Usage:\n");
		fwrite(
        STDERR,
        "  php cli/import_sftools.php --file /path/to/guild.csv [--guild-id 1]\n",
		);
		fwrite(STDERR, "  php cli/import_sftools.php --list-guilds\n");
		exit(2);
	}
	
	function argValue(array $argv, string $name): ?string
	{
		$n = count($argv);
		for ($i = 0; $i < $n; $i++) {
			if ($argv[$i] === $name && isset($argv[$i + 1])) {
				return (string) $argv[$i + 1];
			}
		}
		return null;
	}
	
	function hasFlag(array $argv, string $flag): bool
	{
		return in_array($flag, $argv, true);
	}
	
	function detectCsvDelimiter(string $line): string
	{
		$commas = substr_count($line, ",");
		$semis = substr_count($line, ";");
		return $semis > $commas ? ";" : ",";
	}
	
	function toUtf8(string $s): string
	{
		$s = str_replace("\xEF\xBB\xBF", "", $s);
		if ($s === "") {
			return $s;
		}
		
		if (
        function_exists("mb_check_encoding") &&
        mb_check_encoding($s, "UTF-8")
		) {
			return $s;
		}
		
		$enc = function_exists("mb_detect_encoding")
        ? mb_detect_encoding(
		$s,
		["UTF-8", "Windows-1252", "ISO-8859-1", "ISO-8859-2"],
		true,
        )
        : null;
		
		if (!$enc) {
			$enc = "Windows-1252";
		}
		
		$out = @iconv($enc, "UTF-8//IGNORE", $s);
		return $out === false ? $s : $out;
	}
	
	function cleanName(string $s): string
	{
		$s = toUtf8($s);
		$s = preg_replace("/\p{C}+/u", "", $s) ?? $s;
		$s = preg_replace('/^\p{Z}+|\p{Z}+$/u', "", $s) ?? trim($s);
		$s = preg_replace("/\s+/u", " ", $s) ?? $s;
		return $s;
	}
	
	function normalizeHeader(string $s): string
	{
		$s = str_replace("\xEF\xBB\xBF", "", $s);
		$s = trim(mb_strtolower($s, "UTF-8"));
		$s = str_replace([" ", "\t", "\r", "\n"], "", $s);
		$s = str_replace([".", ":", "(", ")"], "", $s);
		$s = str_replace(["ä", "ö", "ü", "ß"], ["ae", "oe", "ue", "ss"], $s);
		return $s;
	}
	
	function csvGet(array $row, array $map, string $key, $default = "")
	{
		if (!isset($map[$key])) {
			return $default;
		}
		$idx = $map[$key];
		return $row[$idx] ?? $default;
	}
	
	function slugify(string $s): string
	{
		$s = toUtf8($s);
		$s = trim($s);
		
		// deutsch sauber
		$s = str_replace(
        ["Ä", "Ö", "Ü", "ä", "ö", "ü", "ß"],
        ["Ae", "Oe", "Ue", "ae", "oe", "ue", "ss"],
        $s,
		);
		
		if (function_exists("iconv")) {
			$x = @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $s);
			if (is_string($x) && $x !== "") {
				$s = $x;
			}
		}
		
		$s = strtolower($s);
		$s = preg_replace("/[^a-z0-9]+/", "_", $s) ?? $s;
		$s = trim($s, "_");
		return $s;
	}
	
	function guildIdFromFile(PDO $pdo, string $filePath): int
	{
		$name = pathinfo($filePath, PATHINFO_FILENAME);
		$name = explode("__", $name, 2)[0];
		// Datum am Ende entfernen: _20251227 oder -20251227
		$name = preg_replace('/[_-]\d{8}$/', "", $name);
		$key = slugify($name);
		
		$rows = $pdo->query("SELECT id, name, tag FROM guilds")->fetchAll();
		foreach ($rows as $g) {
			$nameSlug = slugify((string) ($g["name"] ?? ""));
			if ($nameSlug === $key) {
				return (int) $g["id"];
			}
			
			$tag = (string) ($g["tag"] ?? "");
			if ($tag !== "" && slugify($tag) === $key) {
				return (int) $g["id"];
			}
		}
		
		return 0;
	}
	
	function importMembersCsv(PDO $pdo, int $guildId, string $csvPath): array
	{
		if (!is_file($csvPath) || !is_readable($csvPath)) {
			throw new RuntimeException("CSV nicht lesbar: {$csvPath}");
		}
		
		$fh = fopen($csvPath, "rb");
		if (!$fh) {
			throw new RuntimeException("Konnte CSV nicht öffnen: {$csvPath}");
		}
		
		$firstLine = fgets($fh);
		if ($firstLine === false) {
			fclose($fh);
			throw new RuntimeException("CSV ist leer: {$csvPath}");
		}
		$delimiter = detectCsvDelimiter($firstLine);
		rewind($fh);
		
		$header = fgetcsv($fh, 0, $delimiter);
		if (!$header || count($header) < 2) {
			fclose($fh);
			throw new RuntimeException(
            "CSV-Header konnte nicht gelesen werden: {$csvPath}",
			);
		}
		
		$map = [];
		foreach ($header as $i => $col) {
			$col = toUtf8((string) $col);
			$k = normalizeHeader($col);
			
			if ($k === "" || str_contains($k, "mitglieder")) {
				continue;
			}
			
			if ($k === "name") {
				$map["name"] = $i;
				} elseif ($k === "rang" || $k === "rank") {
				$map["rank"] = $i;
				} elseif ($k === "level") {
				$map["level"] = $i;
				} elseif ($k === "zulonline") {
				$map["last_online"] = $i;
				} elseif ($k === "gildenbeitritt" || $k === "joinedat") {
				$map["joined_at"] = $i;
				} elseif ($k === "goldschatz" || $k === "gold") {
				$map["gold"] = $i;
				} elseif ($k === "lehrmeister" || $k === "mentor") {
				$map["mentor"] = $i;
			} elseif (
            $k === "ritterhalle" ||
            $k === "knighthall" ||
            $k === "knight_hall"
			) {
				$map["knight_hall"] = $i;
			} elseif (
            $k === "gildenpet" ||
            $k === "guild_pet" ||
            $k === "guildpet"
			) {
				$map["guild_pet"] = $i;
			} elseif (
            $k === "tageoffline" ||
            $k === "days_offline" ||
            $k === "daysoffline"
			) {
				$map["days_offline"] = $i;
				} elseif ($k === "entlassen" || $k === "fired_at") {
				$map["fired_at"] = $i;
				} elseif ($k === "verlassen" || $k === "left_at") {
				$map["left_at"] = $i;
				} elseif ($k === "sonstigenotizen" || $k === "notes") {
				$map["notes"] = $i;
			}
		}
		
		if (!isset($map["name"])) {
			fclose($fh);
			throw new RuntimeException('CSV enthält keine Spalte "Name".');
		}
		
		$sel = $pdo->prepare(
        "SELECT id, fired_at, left_at, notes, rank FROM members WHERE guild_id = ? AND name = ? LIMIT 1",
		);
		
		$upd = $pdo->prepare("
        UPDATE members
		SET level        = :level,
		rank         = :rank,
		last_online  = :last_online,
		joined_at    = :joined_at,
		gold         = :gold,
		mentor       = :mentor,
		knight_hall  = :knight_hall,
		guild_pet    = :guild_pet,
		days_offline = :days_offline,
		fired_at     = :fired_at,
		left_at      = :left_at,
		notes        = :notes,
		updated_at   = :updated_at
		WHERE id = :id
		");
		
		$ins = $pdo->prepare("
        INSERT INTO members (
		guild_id, name, level, last_online, joined_at, gold, mentor, knight_hall, guild_pet, days_offline,
		rank, fired_at, left_at, notes, updated_at
        ) VALUES (
		:guild_id, :name, :level, :last_online, :joined_at, :gold, :mentor, :knight_hall, :guild_pet, :days_offline,
		:rank, :fired_at, :left_at, :notes, :updated_at
        )
		");
		
		$inserted = 0;
		$updated = 0;
		$skipped = 0;
		
		$seen = []; // lower(name) => true
		
		$pdo->beginTransaction();
		try {
			while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
				$name = cleanName((string) csvGet($row, $map, "name", ""));
				if ($name === "") {
					$skipped++;
					continue;
				}
				
				$nameKey = mb_strtolower($name, "UTF-8");
				if (isset($seen[$nameKey])) {
					$skipped++;
					continue;
				}
				$seen[$nameKey] = true;
				
				$rankRaw = trim(toUtf8((string) csvGet($row, $map, "rank", "")));
				$lastOnline = trim(
                toUtf8((string) csvGet($row, $map, "last_online", "")),
				);
				$joinedAt = trim(
                toUtf8((string) csvGet($row, $map, "joined_at", "")),
				);
				$notesRaw = trim(toUtf8((string) csvGet($row, $map, "notes", "")));
				$firedRaw = trim(
                toUtf8((string) csvGet($row, $map, "fired_at", "")),
				);
				$leftRaw = trim(toUtf8((string) csvGet($row, $map, "left_at", "")));
				
				$levelStr = trim(toUtf8((string) csvGet($row, $map, "level", "")));
				$goldStr = trim(toUtf8((string) csvGet($row, $map, "gold", "")));
				$mentorStr = trim(
                toUtf8((string) csvGet($row, $map, "mentor", "")),
				);
				$knightHallStr = trim(
                toUtf8((string) csvGet($row, $map, "knight_hall", "")),
				);
				$guildPetStr = trim(
                toUtf8((string) csvGet($row, $map, "guild_pet", "")),
				);
				
				$level = $levelStr === "" ? null : (int) $levelStr;
				$gold = $goldStr === "" ? null : (int) $goldStr;
				$mentor = $mentorStr === "" ? null : (int) $mentorStr;
				$knightHall = $knightHallStr === "" ? null : (int) $knightHallStr;
				$guildPet = $guildPetStr === "" ? null : (int) $guildPetStr;
				
				$daysOffline = null; // wird live aus last_online berechnet
				
				$fired = $firedRaw !== "" ? normalizeDateDE($firedRaw) : null;
				$left = $leftRaw !== "" ? normalizeDateDE($leftRaw) : null;
				
				// fired gewinnt über left
				if (!empty($fired)) {
					$left = null;
				}
				
				$sel->execute([$guildId, $name]);
				$existing = $sel->fetch();
				
				if ($existing) {
					$newFired =
                    $firedRaw !== "" ? $fired : $existing["fired_at"] ?? null;
					$newLeft =
                    $leftRaw !== "" ? $left : $existing["left_at"] ?? null;
					if ($firedRaw !== "" && !empty($newFired)) {
						$newLeft = null;
					}
					
					$newNotes =
                    $notesRaw !== "" ? $notesRaw : $existing["notes"] ?? null;
					$newRank =
                    $rankRaw !== "" ? $rankRaw : $existing["rank"] ?? null;
					
					$upd->execute([
                    ":level" => $level,
                    ":rank" => $newRank === "" ? null : $newRank,
                    ":last_online" => $lastOnline === "" ? null : $lastOnline,
                    ":joined_at" => $joinedAt === "" ? null : $joinedAt,
                    ":gold" => $gold,
                    ":mentor" => $mentor,
                    ":knight_hall" => $knightHall,
                    ":guild_pet" => $guildPet,
                    ":days_offline" => $daysOffline,
                    ":fired_at" => empty($newFired) ? null : $newFired,
                    ":left_at" => empty($newLeft) ? null : $newLeft,
                    ":notes" => $newNotes === "" ? null : $newNotes,
                    ":updated_at" => gmdate("c"),
                    ":id" => (int) $existing["id"],
					]);
					$updated++;
					} else {
					$ins->execute([
                    ":guild_id" => $guildId,
                    ":name" => $name,
                    ":level" => $level,
                    ":rank" => $rankRaw === "" ? null : $rankRaw,
                    ":last_online" => $lastOnline === "" ? null : $lastOnline,
                    ":joined_at" => $joinedAt === "" ? null : $joinedAt,
                    ":gold" => $gold,
                    ":mentor" => $mentor,
                    ":knight_hall" => $knightHall,
                    ":guild_pet" => $guildPet,
                    ":days_offline" => $daysOffline,
                    ":fired_at" => empty($fired) ? null : $fired,
                    ":left_at" => empty($left) ? null : $left,
                    ":notes" => $notesRaw === "" ? null : $notesRaw,
                    ":updated_at" => gmdate("c"),
					]);
					$inserted++;
				}
			}
			
			$pdo->commit();
			} catch (Throwable $e) {
			$pdo->rollBack();
			fclose($fh);
			throw $e;
		}
		
		fclose($fh);
		
		return [
        "inserted" => $inserted,
        "updated" => $updated,
        "skipped" => $skipped,
		];
	}
	
	// -------------------- main --------------------
	$argv = $_SERVER["argv"] ?? [];
	if (hasFlag($argv, "--list-guilds")) {
		$pdo = db();
		$rows = $pdo
        ->query(
		"SELECT id, server, name, tag FROM guilds ORDER BY server, name",
        )
        ->fetchAll();
		foreach ($rows as $g) {
			$slug = slugify((string) $g["name"]);
			$tag = (string) ($g["tag"] ?? "");
			$tagSlug = $tag !== "" ? slugify($tag) : "";
			echo sprintf(
            "%d\t%s\t%s\t%s\t%s\n",
            (int) $g["id"],
            (string) $g["server"],
            (string) $g["name"],
            $slug,
            $tagSlug,
			);
		}
		exit(0);
	}
	
	$file = argValue($argv, "--file");
	if (!$file) {
		usage();
	}
	
	$pdo = db();
	
	$guildId = (int) (argValue($argv, "--guild-id") ?? 0);
	if ($guildId <= 0) {
		$guildId = guildIdFromFile($pdo, $file);
	}
	if ($guildId <= 0) {
		fwrite(STDERR, "Unbekannte Gilde für Datei: {$file}\n");
		exit(3);
	}
	
	$res = importMembersCsv($pdo, $guildId, $file);
	$pdo->prepare(
    "UPDATE guilds SET last_import_at = :ts, updated_at = datetime('now') WHERE id = :id",
	)->execute([
    ":ts" => gmdate("c"),
    ":id" => $guildId,
	]);
	echo "OK guild_id={$guildId} inserted={$res["inserted"]} updated={$res["updated"]} skipped={$res["skipped"]}\n";
	exit(0);
