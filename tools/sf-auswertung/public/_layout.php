<?php
declare(strict_types=1);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function sf_base_path(): string {
    // z.B. /sf-auswertung
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/');
}

function render_page(string $title, string $active, string $contentHtml): void {
    $base = sf_base_path();

    // TODO: Hier den CSS-Pfad aus sfguilds eintragen (aus Schritt 1)
    // Beispiel: $themeCss = '/assets/app.css';
    $themeCss = '/assets/app.css';

    $fullTitle = 'SFTools – ' . $title;
    ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=h($fullTitle)?></title>

  <!-- sfguilds CSS (gleicher Look) -->
  <link rel="stylesheet" href="<?=h($themeCss)?>">

  <!-- kleine Ergänzungen für dieses Tool -->
  <link rel="stylesheet" href="<?=h($base)?>/sf-auswertung.css">
</head>
<body>

  <header class="sf-topbar">
    <div class="sf-container">
      <div class="sf-topbar-row">
        <div class="sf-brand">
          <div class="sf-brand-title">SFTools</div>
          <div class="sf-brand-sub">Gildenangriffe / Gildenverteidigung – Auswertung</div>
        </div>

        <nav class="sf-nav">
          <a class="sf-navlink <?= $active==='import'?'is-active':'' ?>" href="<?=h($base)?>/import.php">Import</a>
          <a class="sf-navlink <?= $active==='report'?'is-active':'' ?>" href="<?=h($base)?>/report.php">Auswertung</a>
        </nav>
      </div>
    </div>
  </header>

  <main class="sf-container sf-main">
    <?= $contentHtml ?>
  </main>

</body>
</html>
<?php
}
