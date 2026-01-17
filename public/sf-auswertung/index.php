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
$prefGuildId = (int)($_GET['guild_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $guildId = (int)($_POST['guild_id'] ?? 0);
    $prefGuildId = $guildId;
    $date = trim((string)($_POST['date'] ?? ''));
    $time = trim((string)($_POST['time'] ?? ''));
    $text = (string)($_POST['text'] ?? '');

    try {
        if ($guildId <= 0) throw new RuntimeException('Bitte eine Gilde auswählen.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Datum bitte als YYYY-MM-DD.');
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) throw new RuntimeException('Uhrzeit bitte als HH:MM.');
        if (trim($text) === '') throw new RuntimeException('Textfeld ist leer.');

$res = sf_eval_import($guildId, $date, $time, $text);

// Bei Erfolg oder Duplikat: ab zum Report der gewählten Gilde
$q = http_build_query([
    'guild_id' => $guildId,
    'import'   => $res['inserted'] ? 'ok' : 'dup',
    'type'     => $res['type'],
    'opponent' => $res['opponent'],
    'players'  => (string)$res['players'],
]);

header('Location: ' . url('/sf-auswertung/report.php?' . $q));
exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

ob_start();
?>
<h2>SF Auswertung</h2>

<div style="display:flex; gap:10px; flex-wrap:wrap; margin: 10px 0 18px;">
  <?php $qid = ($prefGuildId > 0) ? ('?guild_id=' . $prefGuildId) : ''; ?>
  <a class="btn" href="<?= e(url('/sf-auswertung/' . $qid)) ?>">Import</a>
  <a class="btn" href="<?= e(url('/sf-auswertung/report.php' . $qid)) ?>">Report</a>
</div>

<?php if ($flash): ?>
  <div class="notice success"><?= e($flash) ?></div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="notice error"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" style="margin-top: 12px; max-width: 900px;">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px; max-width: 720px;">
    <label>
      <div>Meine Gilde</div>
      <select name="guild_id" required>
        <option value="">– bitte wählen –</option>
        <?php foreach ($guilds as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= ($prefGuildId === (int)$g['id']) ? 'selected' : '' ?>>
            <?= e($g['name']) ?> (<?= e($g['server']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
      <label>
        <div>Datum</div>
        <input type="date" name="date" required value="<?= e($_POST['date'] ?? '') ?>">
      </label>
      <label>
        <div>Uhrzeit</div>
        <input type="time" name="time" required value="<?= e($_POST['time'] ?? '') ?>">
      </label>
    </div>
  </div>

  <label style="display:block; margin-top: 12px;">
    <div>Post-Text (reinkopieren)</div>
    <textarea name="text" rows="18" required style="width:100%;"><?= e($_POST['text'] ?? '') ?></textarea>
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
