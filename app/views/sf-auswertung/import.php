<?php
// /var/www/sfguilds/app/views/sf-auswertung/import.php

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$flash = $_SESSION['sf_auswertung_flash'] ?? null;
unset($_SESSION['sf_auswertung_flash']);

$defaultDate = date('Y-m-d');
$defaultTime = date('H:i');

?>
<div class="container">
    <h1>SF Auswertung</h1>
    <p>Hier kannst du Kampfberichte (Angriff / Verteidigung) importieren und danach die Teilnahme auswerten.</p>

    <?php if ($flash): ?>
        <div class="notice <?= $flash['ok'] ? 'notice-ok' : 'notice-error' ?>">
            <?= h($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Import</h2>
        <form method="post" action="/sf-auswertung/import.php">
            <div class="form-row">
                <label>Datum (YYYY-MM-DD)</label>
                <input type="text" name="date" value="<?= h($_POST['date'] ?? $defaultDate) ?>" placeholder="2026-01-13">
            </div>

            <div class="form-row">
                <label>Uhrzeit (HH:MM)</label>
                <input type="text" name="time" value="<?= h($_POST['time'] ?? $defaultTime) ?>" placeholder="15:57">
            </div>

            <div class="form-row">
                <label>Kampfbericht-Text</label>
                <textarea name="text" rows="16" placeholder="Hier den kompletten Text aus der Post einfügen..."><?= h($_POST['text'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <button type="submit">Importieren</button>
                <a class="btn" href="/sf-auswertung/report.php">Zur Auswertung</a>
            </div>
        </form>
    </div>
</div>
