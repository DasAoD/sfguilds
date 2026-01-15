<?php
function a_active(string $href, string $label, string $class = 'btn', bool $prefix = false): string
{
    $reqPath  = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    $hrefPath = (string)(parse_url($href, PHP_URL_PATH) ?? $href);

    $norm = static function (string $p): string {
        $p = '/' . ltrim($p, '/');
        if ($p !== '/') $p = rtrim($p, '/');
        return $p;
    };

    $req    = $norm($reqPath);
    $target = $norm($hrefPath);

    $active = $prefix && $target !== '/'
        ? ($req === $target || str_starts_with($req . '/', $target . '/'))
        : ($req === $target);

    $cls  = trim($class . ($active ? ' active' : ''));
    $aria = $active ? ' aria-current="page"' : '';

    return '<a class="' . e($cls) . '" href="' . e($href) . '"' . $aria . '>' . e($label) . '</a>';
}

$title = $title ?? "S&F Guilds";
?>
<!doctype html>
<html lang="de">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?= e($title) ?></title>
		<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body>
		<header class="wrap header">
			<div class="header-left">
				<h1><a href="/" class="brand">S&F Guilds</a></h1>
				<nav class="nav">
					<?= a_active('/', 'Home', 'btn') ?>
					<?php if (isAdmin()): ?>
						<?= a_active('/sf-auswertung/', 'SF Auswertung', 'btn', true) ?>
						<?= a_active('/admin/', 'Admin', 'btn', true) ?>
						<a class="btn" href="<?= e(url("/admin/logout.php")) ?>">Logout</a>
						<?php else: ?>
						<a class="btn" href="<?= e(
						url("/admin/login.php?next=" . rawurlencode($_SERVER["REQUEST_URI"] ?? "/"))) ?>">Login</a>
					<?php endif; ?>
				</nav>
			</div>
			
			<a class="header-right" href="/" aria-label="Home">
				<img src="/assets/sf-logo.png" alt="Shakes & Fidget" class="sf-logo">
			</a>
			
			<?php $__flash_rendered = false; ?>
			
			<?php
				// verhindert doppelte Meldungen in Views (z.B. admin.php)
				$GLOBALS["__flash_rendered"] = false;
				
				// 1) Wenn der Controller schon msg_ok/msg_err übergibt: nutzen (verbraucht die Flash-Message nicht nochmal)
				// 2) Sonst direkt aus der Session-Flash holen (Keys sind ok/err)
				$ok = $msg_ok ?? null;
				$err = $msg_err ?? null;
				
				if ($ok === null || $ok === "") {
					$ok = flash_get("ok");
				}
				if ($err === null || $err === "") {
					$err = flash_get("err");
				}
				
				if (!empty($ok) || !empty($err)) {
					$GLOBALS["__flash_rendered"] = true;
				}
			?>
			
			<?php if (!empty($ok)): ?>
			<p class="flash flash-ok"><?= e($ok) ?></p>
			<?php endif; ?>
			
			<?php if (!empty($err)): ?>
			<p class="flash flash-err"><?= e($err) ?></p>
			<?php endif; ?>
			
			
			<?php if ($__flash_rendered) {
				$GLOBALS["__flash_rendered"] = true;
			} ?>
			
		</header>
		
		<?php $isGuildsView = basename($viewFile) === "guilds.php"; ?>
		<main class="wrap<?= $isGuildsView ? "" : " card" ?>">
			<?php // 1) Wenn $content gesetzt ist, kann das ein String oder eine Closure sein.
				
				if (isset($content)) {
					if (is_callable($content)) {
						$content();
						} else {
						echo $content;
					}
					// 2) Sonst: klassisch eine View-Datei einbinden.
					} elseif (isset($viewFile)) {
					require $viewFile;
				} ?>
		</main>
		
		<footer class="wrap footer">
			<small><?= e(gmdate("d.m.Y - H:i")) ?> UTC</small>
		</footer>
	</body>
</html>
