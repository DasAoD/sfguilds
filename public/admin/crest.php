<?php
	require_once __DIR__ . "/../../app/bootstrap.php";
	
	requireAdmin();

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
	http_response_code(405);
	exit("Method Not Allowed");
}
csrf_check();
	
	$pdo = db();
	$action = (string) ($_POST["action"] ?? "upload");
	
	$guildId = (int) ($_POST["guild_id"] ?? 0);
	$returnTo = (string) ($_POST["return_to"] ?? "/admin/");
	
	if ($returnTo === "" || $returnTo[0] !== "/") {
		$returnTo = "/admin/";
	}
	
	if ($guildId <= 0) {
		flash("err", "Ungültige Gilde.");
		redirect($returnTo);
	}
	
	$st = $pdo->prepare("SELECT id, crest_file FROM guilds WHERE id = :id");
	$st->execute([":id" => $guildId]);
	$g = $st->fetch();
	
	if (!$g) {
		flash("err", "Gilde nicht gefunden.");
		redirect($returnTo);
	}
	
	if ($action === "delete") {
		$destDir = __DIR__ . "/../uploads/crests";
		$old = (string) ($g["crest_file"] ?? "");
		
		if ($old !== "") {
			$oldPath = $destDir . "/" . basename($old);
			if (is_file($oldPath)) {
				@unlink($oldPath);
			}
		}
		
		$upd = $pdo->prepare(
        "UPDATE guilds SET crest_file = NULL, updated_at = datetime('now') WHERE id = :id",
		);
		$upd->execute([":id" => $guildId]);
		
		flash("ok", "Wappen gelöscht.");
		redirect($returnTo);
	}
	
	if (
    !isset($_FILES["crest"]) ||
    !is_uploaded_file($_FILES["crest"]["tmp_name"])
	) {
		flash("err", "Keine Datei hochgeladen.");
		redirect($returnTo);
	}
	
	$err = (int) ($_FILES["crest"]["error"] ?? UPLOAD_ERR_NO_FILE);
	if ($err !== UPLOAD_ERR_OK) {
		flash("err", "Upload-Fehler: " . $err);
		redirect($returnTo);
	}
	
	$tmp = $_FILES["crest"]["tmp_name"];
	
	// max 20 MB
	if (filesize($tmp) > 20 * 1024 * 1024) {
		flash("err", "Datei zu groß (max. 20 MB).");
		redirect($returnTo);
	}
	
	// MIME prüfen
	$finfo = new finfo(FILEINFO_MIME_TYPE);
	$mime = $finfo->file($tmp) ?: "";
	
	$ext = match ($mime) {
		"image/png" => "png",
		"image/jpeg" => "jpg",
		"image/webp" => "webp",
		default => null,
	};
	
	if ($ext === null) {
		flash("err", "Nur PNG/JPG/WebP erlaubt.");
		redirect($returnTo);
	}
	
	$destDir = __DIR__ . "/../uploads/crests";
	if (!is_dir($destDir)) {
		mkdir($destDir, 0755, true);
	}
	
	// stabiler Dateiname je Gilde (überschreibt altes Wappen)
	// immer WebP speichern
	$filename = "guild_" . $guildId . ".webp";
	$dest = $destDir . "/" . $filename;
	
	// alte Versionen (png/jpg/webp) derselben Gilde entfernen
	foreach (glob($destDir . "/guild_" . $guildId . ".*") ?: [] as $oldFile) {
		if (is_file($oldFile) && basename($oldFile) !== $filename) {
			@unlink($oldFile);
		}
	}
	
	// Konvertieren + Resize (bevorzugt Imagick, sonst GD)
	$max = 1024;
	$quality = 85;
	
	if (class_exists("Imagick")) {
		$img = new Imagick($tmp);
		
		$w = $img->getImageWidth();
		$h = $img->getImageHeight();
		
		// nur verkleinern, nicht vergrößern
		if ($w > $max || $h > $max) {
			$img->resizeImage($max, $max, Imagick::FILTER_LANCZOS, 1, true);
		}
		
		$img->setImageFormat("webp");
		$img->setImageCompressionQuality($quality);
		$img->stripImage();
		
		// Alpha sicher aktiv
		if (method_exists($img, "setImageAlphaChannel")) {
			$img->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
		}
		
		$img->writeImage($dest);
		$img->clear();
		$img->destroy();
		} else {
		// GD-Fallback
		$data = file_get_contents($tmp);
		$src = $data !== false ? imagecreatefromstring($data) : false;
		if (!$src) {
			flash("err", "Konnte Bild nicht lesen (kein Imagick/GD-Problem).");
			redirect($returnTo);
		}
		
		$w = imagesx($src);
		$h = imagesy($src);
		
		$dst = $src;
		
		if ($w > $max || $h > $max) {
			$scale = min($max / $w, $max / $h);
			$nw = (int) max(1, floor($w * $scale));
			$nh = (int) max(1, floor($h * $scale));
			
			$dst = imagecreatetruecolor($nw, $nh);
			imagealphablending($dst, false);
			imagesavealpha($dst, true);
			
			imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
			imagedestroy($src);
		}
		
		if (!function_exists("imagewebp")) {
			flash(
            "err",
            "GD hat kein WebP-Support. Installiere php8.3-gd mit WebP oder nutze Imagick.",
			);
			redirect($returnTo);
		}
		
		imagealphablending($dst, false);
		imagesavealpha($dst, true);
		
		imagewebp($dst, $dest, $quality);
		imagedestroy($dst);
	}
	
	chmod($dest, 0644);
	
	// DB updaten
	$upd = $pdo->prepare(
    "UPDATE guilds SET crest_file = :f, updated_at = datetime('now') WHERE id = :id",
	);
	$upd->execute([":f" => $filename, ":id" => $guildId]);
	
	flash("ok", "Wappen gespeichert (WebP, optimiert).");
	redirect($returnTo);
