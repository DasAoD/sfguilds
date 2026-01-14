<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/sf_auswertung.php';

if (!isAdmin()) {
    $next = $_SERVER['REQUEST_URI'] ?? '/sf-auswertung/report.php';
    header('Location: ' . url('/admin/login.php?next=' . rawurlencode($next)));
    exit;
}

$title = 'SF Auswertung – Report';
$guilds = sf_eval_guilds();

$guildId = (int)($_GET['guild_id'] ?? 0);
$attack = null;
$defense = null;

if ($guildId > 0) {
    $attack = sf_eval_stats($guildId, 'attack');
    $defense = sf_eval_stats($guildId, 'defense');
}

ob_start();
?>
<h1>SF Auswertung – Report</h1>

<?php
$importFlag = (string)($_GET['import'] ?? '');
$importType = (string)($_GET['type'] ?? '');
$importOpponent = (string)($_GET['opponent'] ?? '');
$importPlayers = (string)($_GET['players'] ?? '');

$importTypeLabel = ($importType === 'attack') ? 'Angriff' : (($importType === 'defense') ? 'Verteidigung' : '');
?>

<?php if ($importFlag === 'ok'): ?>
  <div class="notice success">
    Import OK: <?= e($importTypeLabel) ?> gegen „<?= e($importOpponent) ?>“
    <?php if ($importPlayers !== ''): ?>(<?= e($importPlayers) ?> Einträge)<?php endif; ?>
  </div>
<?php elseif ($importFlag === 'dup'): ?>
  <div class="notice warn">
    Duplikat erkannt: <?= e($importTypeLabel) ?> gegen „<?= e($importOpponent) ?>“ war bereits importiert.
  </div>
<?php endif; ?>

<?php if (($_GET['import'] ?? '') === 'ok'): ?>
  <div class="notice success">Import erfolgreich.</div>
<?php endif; ?>

<div style="display:flex; gap:10px; flex-wrap:wrap; margin: 10px 0 18px;">
  <a class="btn" href="<?= e(url('/sf-auswertung/')) ?>">Import</a>
  <a class="btn" href="<?= e(url('/sf-auswertung/report.php')) ?>">Report</a>
</div>

<form method="get" style="margin-bottom: 16px;">
  <label>
    <div>Gilde auswählen</div>
    <select name="guild_id" onchange="this.form.submit()">
      <option value="0">– bitte wählen –</option>
      <?php foreach ($guilds as $g): ?>
        <option value="<?= (int)$g['id'] ?>" <?= $guildId === (int)$g['id'] ? 'selected' : '' ?>>
          <?= e($g['name']) ?> (<?= e($g['server']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <noscript><button class="btn" type="submit" style="margin-top:8px;">Anzeigen</button></noscript>
</form>

<?php if ($guildId <= 0): ?>
  <p>Bitte oben eine Gilde auswählen.</p>
<?php else: ?>

  <h2>Angriffe (<?= (int)($attack['battles'] ?? 0) ?> Kämpfe)</h2>
  <?= sf_eval_render_table($attack['rows'] ?? []) ?>

  <h2 style="margin-top:22px;">Verteidigungen (<?= (int)($defense['battles'] ?? 0) ?> Kämpfe)</h2>
  <?= sf_eval_render_table($defense['rows'] ?? []) ?>

<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../../app/views/layout.php';

function sf_eval_render_table(array $rows): string {
    if (!$rows) return '<p style="opacity:.85;">Noch keine Daten.</p>';

    $html = '<div style="overflow:auto;"><table class="table">';
    $html .= '<thead><tr>'
          .  '<th>Spieler</th>'
          .  '<th style="text-align:right;">Kämpfe</th>'
          .  '<th style="text-align:right;">Angemeldet</th>'
          .  '<th style="text-align:right;">Nicht angemeldet</th>'
          .  '<th style="text-align:right;">%</th>'
          .  '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $name = htmlspecialchars((string)$r['player_name'], ENT_QUOTES, 'UTF-8');
        $fights = (int)$r['fights'];
        $part = (int)$r['participated'];
        $miss = (int)$r['missed'];
        $pct = htmlspecialchars((string)$r['pct_participated'], ENT_QUOTES, 'UTF-8');

        $html .= '<tr>'
              .  '<td>' . $name . '</td>'
              .  '<td style="text-align:right;">' . $fights . '</td>'
              .  '<td style="text-align:right;">' . $part . '</td>'
              .  '<td style="text-align:right;">' . $miss . '</td>'
              .  '<td style="text-align:right;">' . $pct . '</td>'
              .  '</tr>';
    }

    $html .= '</tbody></table></div>';
    return $html;
}
