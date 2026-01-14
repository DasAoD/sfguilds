<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/sf_auswertung.php';

if (!isAdmin()) {
    $next = $_SERVER['REQUEST_URI'] ?? '/sf-auswertung/';
    header('Location: ' . url('/admin/login.php?next=' . rawurlencode($next)));
    exit;
}

$title = 'SF Auswertung – Import';
$guilds = sf_eval_guilds();

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $guildId = (int)($_POST['guild_id'] ?? 0);
    $date = trim((string)($_POST['date'] ?? ''));
    $time = trim((string)($_POST['time'] ?? ''));
    $text = (string)($_POST['text'] ?? '');

    try {
        if ($guildId <= 0) throw new RuntimeException('Bitte eine Gilde auswählen.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Datum bitte als YYYY-MM-DD.');
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) throw new RuntimeException('Uhrzeit bitte als HH:MM.');
        if (trim($text) === '') throw new RuntimeException('Textfeld ist leer.');

        $res = sf_eval_import($guildId, $date, $time, $text);

        $flash = $res['inserted']
            ? "Import OK: " . ($res['type'] === 'attack' ? 'Angriff' : 'Verteidigung') . " gegen „{$res['opponent']}“ ({$res['players']} Einträge)."
            : "Schon vorhanden (Duplikat erkannt): " . ($res['type'] === 'attack' ? 'Angriff' : 'Verteidigung') . " gegen „{$res['opponent']}“.";
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

ob_start();
?>
<h1>SF Auswertung</h1>

<div style="display:flex; gap:10px; flex-wrap:wrap; margin: 10px 0 18px;">
  <a class="btn" href="<?= e(url('/sf-auswertung/')) ?>">Import</a>
  <a class="btn" href="<?= e(url('/sf-auswertung/report.php')) ?>">Report</a>
</div>

<?php if ($flash): ?>
  <div class="notice success"><?= e($flash) ?></div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="notice error"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" style="margin-top: 12px;">
  <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px; max-width: 720px;">
    <label>
      <div>Meine Gilde</div>
      <select name="guild_id" required>
        <option value="">– bitte wählen –</option>
        <?php foreach ($guilds as $g): ?>
          <option value="<?= (int)$g['id'] ?>">
            <?= e($g['name']) ?> (<?= e($g['server']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
      <label>
        <div>Datum</div>
        <input type="date" name="date" required>
      </label>
      <label>
        <div>Uhrzeit</div>
        <input type="time" name="time" required>
      </label>
    </div>
  </div>

  <label style="display:block; margin-top: 12px;">
    <div>Post-Text (reinkopieren)</div>
    <div style="max-width: 900px;">
    <textarea name="text" rows="18" required style="width:100%;"><?= e($_POST['text'] ?? '') ?></textarea>
    </div>
  </label>

  <div style="margin-top: 12px;">
    <button class="btn" type="submit">Importieren</button>
  </div>
</form>

<p style="opacity:.85; margin-top: 14px;">
  Hinweis: Typ (Angriff/Verteidigung) und Gegner werden automatisch aus dem Header erkannt.
</p>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../app/views/layout.php';
