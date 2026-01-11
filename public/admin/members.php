<?php
	require_once __DIR__ . "/../../app/bootstrap.php";
	requireAdmin();
	
	$pdo = db();
	
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	
	/* -------------------------
		Output helpers
	-------------------------- */
	function h($s): string
	{
		return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
	}
	
	function detectCsvDelimiter(string $line): string
	{
		$commas = substr_count($line, ",");
		$semis = substr_count($line, ";");
		return $semis > $commas ? ";" : ",";
	}
	
	function normalizeHeader(string $s): string
	{
		// BOM + trim
		$s = str_replace("\xEF\xBB\xBF", "", $s);
		$s = trim(mb_strtolower($s, "UTF-8"));
		
		// Alles "kompakt" machen
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
	
	/* -------------------------
		UTF-8 safety
	-------------------------- */
	function toUtf8(string $s): string
	{
		// BOM weg
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
		
		// Steuerzeichen / Zero-Width raus
		$s = preg_replace("/\p{C}+/u", "", $s) ?? $s;
		
		// Unicode-Trim
		$s = preg_replace('/^\p{Z}+|\p{Z}+$/u', "", $s) ?? trim($s);
		
		// Innen mehrfaches Whitespace
		$s = preg_replace("/\s+/u", " ", $s) ?? $s;
		
		return $s;
	}
	
	/* -------------------------
		Request params
	-------------------------- */
	$guildId = (int) ($_GET["guild_id"] ?? ($_POST["guild_id"] ?? 0));
	$action = (string) ($_POST["action"] ?? "");
	
	/* -------------------------
		Guild list
	-------------------------- */
	$guilds = $pdo
    ->query("SELECT id, server, name, tag FROM guilds ORDER BY server, name")
    ->fetchAll();
	if ($guildId <= 0 && !empty($guilds)) {
		$guildId = (int) $guilds[0]["id"];
	}
	
	/* -------------------------
		Clear missing list
	-------------------------- */
	if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "clear_missing") {
		unset(
        $_SESSION["_missing_members"][$guildId],
        $_SESSION["_missing_members_ts"][$guildId],
		);
		flash("ok", "Liste ausgeblendet.");
		redirect("/admin/members.php?guild_id=" . $guildId);
	}
	
	/* -------------------------
		CSV Import
	-------------------------- */
	if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "import_csv") {
		if ($guildId <= 0) {
			flash("err", "Bitte zuerst eine Gilde auswählen.");
			redirect("/admin/members.php");
		}
		
		if (
        !isset($_FILES["csv_file"]) ||
        !is_uploaded_file($_FILES["csv_file"]["tmp_name"])
		) {
			flash("err", "Keine CSV-Datei hochgeladen.");
			redirect("/admin/members.php?guild_id=" . $guildId);
		}
		
		$tmp = $_FILES["csv_file"]["tmp_name"];
		$fh = fopen($tmp, "rb");
		if (!$fh) {
			flash("err", "Konnte CSV-Datei nicht lesen.");
			redirect("/admin/members.php?guild_id=" . $guildId);
		}
		
		$firstLine = fgets($fh);
		if ($firstLine === false) {
			fclose($fh);
			flash("err", "CSV ist leer.");
			redirect("/admin/members.php?guild_id=" . $guildId);
		}
		$delimiter = detectCsvDelimiter($firstLine);
		rewind($fh);
		
		$header = fgetcsv($fh, 0, $delimiter);
		if (!$header || count($header) < 2) {
			fclose($fh);
			flash("err", "CSV-Header konnte nicht gelesen werden.");
			redirect("/admin/members.php?guild_id=" . $guildId);
		}
		
		// Header mapping
		$map = [];
		foreach ($header as $i => $col) {
			$col = toUtf8((string) $col);
			$k = normalizeHeader($col);
			
			// falls aus Excel mal "xx Mitglieder" irgendwo rumliegt -> ignorieren
			if ($k === "" || str_contains($k, "mitglieder")) {
				continue;
			}
			
			if ($k === "name") {
				$map["name"] = $i;
				} elseif ($k === "rang" || $k === "rank") {
				$map["rank"] = $i;
				} elseif ($k === "level") {
				$map["level"] = $i;
				} elseif ($k === "zulonline" || $k === "zulonline") {
				$map["last_online"] = $i;
			}
			// (nur zur Sicherheit)
			elseif ($k === "zulonline" || $k === "zulonline") {
				$map["last_online"] = $i;
				} elseif ($k === "zulonline" || $k === "zulonline") {
				$map["last_online"] = $i;
				} elseif ($k === "zulonline" || $k === "zulonline") {
				$map["last_online"] = $i;
			}
			
			// realer Header: "zul. Online" -> normalizeHeader => "zulonline"
			elseif ($k === "zulonline") {
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
			flash("err", 'CSV enthält keine Spalte "Name".');
			redirect("/admin/members.php?guild_id=" . $guildId);
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
		$errors = [];
		
		$seen = []; // lower(name) => true
		
		$pdo->beginTransaction();
		try {
			while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
				// Name immer clean/utf8
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
				
				// Textfelder UTF-8 machen
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
				
				// Numerics
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
				$daysOfflineStr = trim(
                toUtf8((string) csvGet($row, $map, "days_offline", "")),
				);
				
				$level = $levelStr === "" ? null : (int) $levelStr;
				$gold = $goldStr === "" ? null : (int) $goldStr;
				$mentor = $mentorStr === "" ? null : (int) $mentorStr;
				$knightHall = $knightHallStr === "" ? null : (int) $knightHallStr;
				$guildPet = $guildPetStr === "" ? null : (int) $guildPetStr;
				$daysOffline = null; // Tage offline werden live aus last_online berechnet
				
				// Datum normalisieren (wenn gesetzt)
				$fired = null;
				$left = null;
				
				if ($firedRaw !== "") {
					$fired = normalizeDateDE($firedRaw);
					if ($fired === null) {
						$errors[] = "Ungültiges Datum Entlassen bei {$name}: {$firedRaw}";
						continue;
					}
				}
				if ($leftRaw !== "") {
					$left = normalizeDateDE($leftRaw);
					if ($left === null) {
						$errors[] = "Ungültiges Datum Verlassen bei {$name}: {$leftRaw}";
						continue;
					}
				}
				
				// Semantik: beides gleichzeitig -> fired gewinnt
				if ($fired !== null && $fired !== "") {
					$left = null;
					} elseif ($left !== null && $left !== "") {
					$fired = null;
				}
				
				$sel->execute([$guildId, $name]);
				$existing = $sel->fetch();
				
				if ($existing) {
					// Manuelle Felder nur überschreiben, wenn CSV Werte enthält
					$newFired =
                    $firedRaw !== "" ? $fired : $existing["fired_at"] ?? null;
					$newLeft =
                    $leftRaw !== "" ? $left : $existing["left_at"] ?? null;
					
					if ($firedRaw !== "" && $newFired) {
						$newLeft = null;
					}
					if ($leftRaw !== "" && $newLeft) {
						$newFired = null;
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
                    ":fired_at" => $newFired === "" ? null : $newFired,
                    ":left_at" => $newLeft === "" ? null : $newLeft,
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
                    ":fired_at" => $fired === "" ? null : $fired,
                    ":left_at" => $left === "" ? null : $left,
                    ":notes" => $notesRaw === "" ? null : $notesRaw,
                    ":updated_at" => gmdate("c"),
					]);
					$inserted++;
				}
			}
			
			$pdo->prepare(
            "UPDATE guilds SET last_import_at = :ts, updated_at = datetime('now') WHERE id = :id",
			)->execute([
            ":ts" => gmdate("c"),
            ":id" => $guildId,
			]);
			
			$pdo->commit();
			} catch (Throwable $e) {
			$pdo->rollBack();
			fclose($fh);
			flash("err", "Import abgebrochen: " . $e->getMessage());
			redirect("/admin/members.php?guild_id=" . $guildId);
		}
		
		fclose($fh);
		
		// Fehlende Mitglieder (nur aktive) im aktuellen Export
		$missing = [];
		$stmt = $pdo->prepare("
        SELECT id, name
		FROM members
		WHERE guild_id = ?
		AND (fired_at IS NULL OR fired_at = '')
		AND (left_at  IS NULL OR left_at  = '')
		ORDER BY name COLLATE NOCASE
		");
		$stmt->execute([$guildId]);
		$activeRows = $stmt->fetchAll();
		
		foreach ($activeRows as $r) {
			$k = mb_strtolower((string) $r["name"], "UTF-8");
			if (!isset($seen[$k])) {
				$missing[] = [
                "id" => (int) $r["id"],
                "name" => (string) $r["name"],
				];
			}
		}
		
		$_SESSION["_missing_members"][$guildId] = $missing;
		$_SESSION["_missing_members_ts"][$guildId] = gmdate("c");
		
		$msg = "Import fertig: {$inserted} neu, {$updated} aktualisiert, {$skipped} übersprungen.";
		if (!empty($errors)) {
			$msg .= " Fehler: " . count($errors) . " (Details im Log).";
			foreach ($errors as $err) {
				error_log("CSV-Import: " . $err);
			}
		}
		
		flash("ok", $msg);
		redirect("/admin/members.php?guild_id=" . $guildId);
	}
	
	/* -------------------------
		Save manual fields
	-------------------------- */
	if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "save_member") {
		$guildIdPost = (int) ($_POST["guild_id"] ?? 0);
		$memberId = (int) ($_POST["member_id"] ?? 0);
		if ($guildIdPost > 0) {
			$guildId = $guildIdPost;
		}
		
		$fired = normalizeDateDE(toUtf8((string) ($_POST["fired_at"] ?? "")));
		$left = normalizeDateDE(toUtf8((string) ($_POST["left_at"] ?? "")));
		$notes = trim(toUtf8((string) ($_POST["notes"] ?? "")));
		
		if (trim((string) ($_POST["fired_at"] ?? "")) !== "" && $fired === null) {
			flash("err", 'Ungültiges Datum bei "Entlassen" (Format: TT.MM.JJJJ).');
			redirect("/admin/members.php?guild_id=" . $guildId . "#m" . $memberId);
		}
		if (trim((string) ($_POST["left_at"] ?? "")) !== "" && $left === null) {
			flash("err", 'Ungültiges Datum bei "Verlassen" (Format: TT.MM.JJJJ).');
			redirect("/admin/members.php?guild_id=" . $guildId . "#m" . $memberId);
		}
		
		if ($fired !== null && $fired !== "") {
			$left = null;
			} elseif ($left !== null && $left !== "") {
			$fired = null;
		}
		
		$notesDb = $notes === "" ? null : $notes;
		
		if ($guildId > 0 && $memberId > 0) {
			$stmt = $pdo->prepare("
            UPDATE members
			SET fired_at   = :fired_at,
			left_at    = :left_at,
			notes      = :notes,
			updated_at = :updated_at
			WHERE id = :id AND guild_id = :guild_id
			");
			$stmt->execute([
            ":fired_at" => $fired,
            ":left_at" => $left,
            ":notes" => $notesDb,
            ":updated_at" => gmdate("c"),
            ":id" => $memberId,
            ":guild_id" => $guildId,
			]);
			
			flash("ok", "Gespeichert.");
			redirect("/admin/members.php?guild_id=" . $guildId . "#m" . $memberId);
		}
		
		flash("err", "Konnte nicht speichern (ungültige IDs).");
		redirect("/admin/members.php?guild_id=" . $guildId);
	}
	
	/* -------------------------
		Load guild + members
	-------------------------- */
	$guild = null;
	if ($guildId > 0) {
		$stmt = $pdo->prepare("SELECT * FROM guilds WHERE id = ?");
		$stmt->execute([$guildId]);
		$guild = $stmt->fetch() ?: null;
	}
	
	$members = [];
	if ($guildId > 0) {
		$stmt = $pdo->prepare("
		SELECT *
		FROM members
		WHERE guild_id = ?
		ORDER BY
		CASE
		WHEN rank = 'Anführer' THEN 0
		WHEN rank = 'Offizier' THEN 1
		ELSE 2
		END ASC,
		CASE WHEN last_online IS NULL OR last_online = '' THEN 1 ELSE 0 END,
		CASE
		WHEN last_online LIKE '____-__-__%' THEN substr(last_online,1,10)
		WHEN last_online LIKE '__.__.____%' THEN substr(last_online,7,4) || '-' || substr(last_online,4,2) || '-' || substr(last_online,1,2)
		ELSE last_online
		END DESC,
		CASE
		WHEN days_offline IS NULL OR days_offline = '' THEN -999999
		ELSE CAST(days_offline AS INTEGER)
		END DESC,
		level DESC,
		name COLLATE NOCASE
		");
		$stmt->execute([$guildId]);
		$members = $stmt->fetchAll();
	}
	
	// Counts like Excel
	$total = count($members);
	$active = 0;
	$firedCount = 0;
	$leftCount = 0;
	foreach ($members as $m) {
		$f = trim((string) ($m["fired_at"] ?? ""));
		$l = trim((string) ($m["left_at"] ?? ""));
		if ($f !== "") {
			$firedCount++;
		}
		if ($l !== "") {
			$leftCount++;
		}
		if ($f === "" && $l === "") {
			$active++;
		}
	}
	
	// Missing from last export
	$missing = $_SESSION["_missing_members"][$guildId] ?? [];
	$missingTs = $_SESSION["_missing_members_ts"][$guildId] ?? null;
	
	/* -------------------------
		Render
	-------------------------- */
	view("layout", [
    "title" => "Admin – Mitglieder",
    "content" => function () use (
	$guilds,
	$guildId,
	$guild,
	$members,
	$total,
	$active,
	$firedCount,
	$leftCount,
	$missing,
	$missingTs,
    ) {
	?>
	<h1>Admin – Mitglieder</h1>
	
	<?php if ($msg = flash("ok")): ?>
	<div class="flash flash-ok"><?= h($msg) ?></div>
	<?php endif; ?>
	<?php if ($msg = flash("err")): ?>
	<div class="flash flash-err"><?= h($msg) ?></div>
	<?php endif; ?>
	
	<form method="get" action="/admin/members.php" style="margin: 1rem 0;">
		<label>Gilde:
			<select name="guild_id">
				<?php foreach ($guilds as $g): ?>
				<option value="<?= (int) $g["id"] ?>" <?= (int) $g[
					"id"
					] === (int) $guildId
					? "selected"
					: "" ?>>
					<?= h($g["server"]) ?> – <?=
						h($g["name"])
						!empty($g["tag"]) ? " [" . h($g["tag"]) . "]" : ""
					?>
				</option>
				<?php endforeach; ?>
			</select>
		</label>
		<button type="submit">Anzeigen</button>
	</form>
	
	<?php if (!$guild): ?>
	<p>Gilde nicht gefunden.</p>
	<?php return; ?>
	<?php endif; ?>
	
	<h2><?= h($guild["server"]) ?> – <?= h($guild["name"]) ?></h2>
	<?php
		$lastImport = (string) ($guild["last_import_at"] ?? "");
		$lastImportText = "—";
		if ($lastImport !== "") {
			try {
				$dt = new DateTime($lastImport);
				$dt->setTimezone(new DateTimeZone("Europe/Berlin"));
				$lastImportText = $dt->format("d.m.Y");
				} catch (Throwable $e) {
				$lastImportText = $lastImport;
			}
		}
	?>
	<p class="muted" style="margin: 0 0 .75rem 0;">
		Letzte Aktualisierung: <strong><?= e($lastImportText) ?></strong>
	</p>
	<div class="box" style="margin: .75rem 0;">
		<div class="row" style="align-items:center; gap:12px; flex-wrap:wrap;">
			<div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
				<?php if (!empty($guild["crest_file"])): ?>
				<img
				src="/uploads/crests/<?= h($guild["crest_file"]) ?>"
				alt="Wappen"
				style="height:300px; width:300px; object-fit:cover; border-radius:12px;"
				>
				<?php else: ?>
				<span class="muted">Kein Wappen</span>
				<?php endif; ?>
			</div>
			
			<form method="post"
			action="/admin/crest.php"
			enctype="multipart/form-data"
			style="display:flex; align-items:center; gap:8px; margin:0; flex-wrap:wrap;">
				<input type="hidden" name="action" value="upload">
				<input type="hidden" name="guild_id" value="<?= (int) $guildId ?>">
				<input type="hidden" name="return_to" value="<?= h(
					"/admin/members.php?guild_id=" . (int) $guildId,
				) ?>">
				<input type="file" name="crest" accept="image/png,image/jpeg,image/webp" required>
				<button type="submit">Upload</button>
			</form>
		</div>
		<div class="row" style="align-items:center; gap:12px; flex-wrap:wrap;">
			<?php if (!empty($guild["crest_file"])): ?>
			<form method="post" action="/admin/crest.php" style="margin:0;">
				<input type="hidden" name="action" value="delete">
				<input type="hidden" name="guild_id" value="<?= (int) $guildId ?>">
				<input type="hidden" name="return_to" value="<?= h(
					"/admin/members.php?guild_id=" . (int) $guildId,
				) ?>">
				<button type="submit" onclick="return confirm('Wappen wirklich löschen?');">Löschen</button>
			</form>
			<?php endif; ?>
		</div>
	</div>
	
	<div class="box" style="margin: .75rem 0;">
		<strong>Aktiv:</strong> <?= (int) $active ?>
		&nbsp;|&nbsp; <strong>Gesamt:</strong> <?= (int) $total ?>
		&nbsp;|&nbsp; <strong>Entlassen:</strong> <?= (int) $firedCount ?>
		&nbsp;|&nbsp; <strong>Verlassen:</strong> <?= (int) $leftCount ?>
	</div>
	
	<div class="box" style="margin: 1rem 0;">
		<h3 style="margin-top:0;">CSV importieren</h3>
		<p class="muted" style="margin-top:0;">
			Import macht Insert/Update anhand von <strong>Name</strong>. Es wird <strong>nichts gelöscht</strong>.
			Entlassen/Verlassen/Notizen werden nur überschrieben, wenn die CSV dort Werte enthält.
		</p>
		<form method="post" action="/admin/members.php?guild_id=<?= (int) $guildId ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="import_csv">
			<input type="hidden" name="guild_id" value="<?= (int) $guildId ?>">
			<input type="file" name="csv_file" accept=".csv,text/csv">
			<button type="submit">Import starten</button>
		</form>
	</div>
	
	<?php if (!empty($missing)): ?>
	<div class="box" style="margin: 1rem 0;">
		<h3 style="margin-top:0;">Fehlende Mitglieder im aktuellen Export (<?= count(
			$missing,
		) ?>)</h3>
		<?php if ($missingTs): ?>
		<p class="muted" style="margin-top:0;">Stand: <?= h(
			$missingTs,
		) ?> UTC</p>
		<?php endif; ?>
		<p class="muted">
			Das sind <strong>aktive</strong> Mitglieder in der DB, die im zuletzt importierten Export nicht vorkamen.
		</p>
		<ul class="list">
			<?php foreach ($missing as $x): ?>
			<li><a href="#m<?= (int) $x["id"] ?>"><?= h(
				$x["name"],
			) ?></a></li>
			<?php endforeach; ?>
		</ul>
		
		<form method="post" action="/admin/members.php?guild_id=<?= (int) $guildId ?>" style="margin-top: .75rem;">
			<input type="hidden" name="action" value="clear_missing">
			<input type="hidden" name="guild_id" value="<?= (int) $guildId ?>">
			<button type="submit">Liste ausblenden</button>
		</form>
	</div>
	<?php endif; ?>
	
	<div class="table-wrap">
		<table id="members" class="table members-table">
            <thead>
				<tr>
					<th class="rownum"></th>
					<th>Name</th>
					<th>Level</th>
					<th>Zul. Online</th>
					<th>Gildenbeitritt</th>
					<th>Goldschatz</th>
					<th>Lehrmeister</th>
					<th>Ritterhalle</th>
					<th>Gildenpet</th>
					<th>Tage offline</th>
					<th>Entlassen</th>
					<th>Verlassen</th>
					<th>Sonstige Notizen</th>
					<th></th>
				</tr>
			</thead>
            <tbody>
				<?php foreach ($members as $i => $m): ?>
                <?php
					$mid = (int) $m["id"];
					$formId = "f$mid";
					$daysOffline = memberDaysOffline($m);
				?>
                <tr id="m<?= $mid ?>" class="<?= h(memberRowClass($m)) ?>">
					<td class="rownum"><?= (int) ($i + 1) ?></td>
					<td><?= h($m["name"] ?? "") ?></td>
					<td><?= h((string) ($m["level"] ?? "")) ?></td>
					<td><?= h((string) ($m["last_online"] ?? "")) ?></td>
					<td><?= h((string) ($m["joined_at"] ?? "")) ?></td>
					<td><?= h((string) ($m["gold"] ?? "")) ?></td>
					<td><?= h((string) ($m["mentor"] ?? "")) ?></td>
					<td><?= h((string) ($m["knight_hall"] ?? "")) ?></td>
					<td><?= h((string) ($m["guild_pet"] ?? "")) ?></td>
					<td><?= h(
						$daysOffline === null ? "" : (string) $daysOffline,
					) ?></td>
					<td><input form="<?= $formId ?>" name="fired_at" value="<?= h(
						formatDateDE($m["fired_at"] ?? ""),
					) ?>" placeholder="TT.MM.JJJJ"></td>
					<td><input form="<?= $formId ?>" name="left_at" value="<?= h(
						formatDateDE($m["left_at"] ?? ""),
					) ?>" placeholder="TT.MM.JJJJ"></td>
					<td><textarea form="<?= $formId ?>" name="notes" rows="2" style="width:100%;"><?= h(
						$m["notes"] ?? "",
					) ?></textarea></td>
					<td style="white-space:nowrap;">
						<form id="<?= $formId ?>" method="post" action="/admin/members.php?guild_id=<?= (int) $guildId ?>#m<?= $mid ?>">
							<input type="hidden" name="action" value="save_member">
							<input type="hidden" name="guild_id" value="<?= (int) $guildId ?>">
							<input type="hidden" name="member_id" value="<?= $mid ?>">
							<button type="submit">Speichern</button>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
    <?php
	},
	]);
	
	// ------------------------------------------------------------
	// Backwards-compatible aliases (older pages may still call these)
	// ------------------------------------------------------------
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
	
	if (!function_exists("normalizeNumber")) {
		function normalizeNumber($v): ?int
		{
			return normalize_number($v);
		}
	}
	
	if (!function_exists("normalizeDate")) {
		function normalizeDate(?string $v): ?string
		{
			return normalize_date($v);
		}
	}
	
	if (!function_exists("rowClass")) {
		function rowClass(array $m): string
		{
			return row_class($m);
		}
	}
	
	if (!function_exists("csrfToken")) {
		function csrfToken(): string
		{
			return csrf_token();
		}
	}
	
	if (!function_exists("csrfCheck")) {
		function csrfCheck(): void
		{
			csrf_check();
		}
	}
