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

$title  = 'SF Auswertung – Report';
$guilds = sf_eval_guilds();

$guildId = (int)($_GET['guild_id'] ?? 0);
$guild   = null;

$stats = [
    'attacks'     => 0,
    'defenses'    => 0,
    'last_import' => null,
];

$attack  = null;
$defense = null;

if ($guildId > 0) {
    // Guild-Infos
    $st = db()->prepare("SELECT id, name, server, crest_file FROM guilds WHERE id = ?");
    $st->execute([$guildId]);
    $guild = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Ungültige ID -> wie "nicht gewählt" behandeln
    if (!$guild) {
        $guildId = 0;
    } else {
        $title = $guild['server'] . ' – ' . $guild['name'] . ' (Report)';

        // Stats (Angriffe/Verteidigungen/letzter Import)
        $st = db()->prepare("
            SELECT
                SUM(CASE WHEN battle_type = 'attack'  THEN 1 ELSE 0 END) AS attacks,
                SUM(CASE WHEN battle_type = 'defense' THEN 1 ELSE 0 END) AS defenses,
                MAX(battle_date) AS last_import
            FROM sf_eval_battles
            WHERE guild_id = ?
        ");
        $st->execute([$guildId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $stats['attacks']     = (int)($row['attacks'] ?? 0);
        $stats['defenses']    = (int)($row['defenses'] ?? 0);
        $stats['last_import'] = $row['last_import'] ?? null;

        $attack  = sf_eval_stats($guildId, 'attack');
        $defense = sf_eval_stats($guildId, 'defense');
    }
}

// schöner Zeitstempel (optional)
$lastImportNice = null;
if (!empty($stats['last_import'])) {
    try {
        $dt = new DateTime((string)$stats['last_import']);
        $lastImportNice = $dt->format('d.m.Y');
    } catch (Throwable $e) {
        $lastImportNice = (string)$stats['last_import'];
    }
}

ob_start();
?>

<?php
$importFlag     = (string)($_GET['import'] ?? '');
$importType     = (string)($_GET['type'] ?? '');
$importOpponent = trim((string)($_GET['opponent'] ?? ''));
$importPlayers  = trim((string)($_GET['players'] ?? ''));

$importTypeLabel = ($importType === 'attack') ? 'Angriff' : (($importType === 'defense') ? 'Verteidigung' : 'Kampf');
$details = $importTypeLabel;

if ($importOpponent !== '') {
    $details .= ' gegen „' . $importOpponent . '“';
}
if ($importPlayers !== '' && ctype_digit($importPlayers)) {
    $details .= ' (' . $importPlayers . ' Einträge)';
}
?>

<?php if ($importFlag === 'ok'): ?>
  <div class="notice success narrow">
    <div><strong>Import erfolgreich.</strong></div>
    <div><?= e($details) ?></div>
  </div>
<?php elseif ($importFlag === 'dup'): ?>
  <div class="notice warn narrow">
    <div><strong>Duplikat erkannt.</strong></div>
    <div><?= e($importTypeLabel) ?> gegen „<?= e($importOpponent) ?>“ war bereits importiert.</div>
  </div>
<?php endif; ?>

<?php
$qid        = ($guildId > 0) ? ('?guild_id=' . $guildId) : '';
$importHref = url('/sf-auswertung/' . $qid);
$reportHref = url('/sf-auswertung/report.php' . $qid);
?>

<div class="report-head narrow">
  <h2 style="margin: 0 0 .5rem 0;">
    <?php if ($guild): ?>
      <?= e($guild['server']) ?> – <?= e($guild['name']) ?> <span class="muted">(Report)</span>
    <?php else: ?>
      SF Auswertung <span class="muted">(Report)</span>
    <?php endif; ?>
  </h2>

  <?php if ($guild && $lastImportNice): ?>
    <p class="muted" style="margin: 0 0 .75rem 0;">
      Letzte Aktualisierung: <strong><?= e($lastImportNice) ?></strong>
    </p>
  <?php endif; ?>

  <?php if ($guild && !empty($guild['crest_file'])): ?>
    <p style="margin: .5rem 0 .75rem 0;">
      <img
        src="<?= e(url('/uploads/crests/' . $guild['crest_file'])) ?>"
        alt="Wappen"
        style="height:300px; width:300px; object-fit:cover; border-radius:12px;"
      >
    </p>
  <?php endif; ?>

  <?php if ($guild): ?>
    <div style="opacity:.9; margin-top: 10px;">
      <strong>Angriffe:</strong> <?= (int)$stats['attacks'] ?> |
      <strong>Verteidigungen:</strong> <?= (int)$stats['defenses'] ?>
    </div>
  <?php endif; ?>

  <p style="margin-top: 12px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="<?= e($importHref) ?>">Import</a>
    <a class="btn active" href="<?= e($reportHref) ?>">Report</a>
  </p>
</div>

<form method="get" style="margin-bottom: 16px;">
  <label>
    <div>Wechseln zu …</div>
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

  <h2>Angriffe (<?= (int)$stats['attacks'] ?> Kämpfe)</h2>
  <?= sf_eval_render_table($attack['rows'] ?? []) ?>

  <h2 style="margin-top:22px;">Verteidigungen (<?= (int)$stats['defenses'] ?> Kämpfe)</h2>
  <?= sf_eval_render_table($defense['rows'] ?? []) ?>

<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../../app/views/layout.php';

function sf_eval_render_table(array $rows): string
{
    if (!$rows) {
        return '<p style="opacity:.85;">Noch keine Daten.</p>';
    }

    $html = '<div class="table-wrap"><table class="table">';
    $html .= '<thead><tr>'
        .  '<th>Spieler</th>'
        .  '<th style="text-align:right;">Kämpfe</th>'
        .  '<th style="text-align:right;">Angemeldet</th>'
        .  '<th style="text-align:right;">Nicht angemeldet</th>'
        .  '<th style="text-align:right;">%</th>'
        .  '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $name   = htmlspecialchars((string)($r['player_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $fights = (int)($r['fights'] ?? 0);
        $part   = (int)($r['participated'] ?? 0);
        $miss   = (int)($r['missed'] ?? 0);
        $pct    = htmlspecialchars((string)($r['pct_participated'] ?? ''), ENT_QUOTES, 'UTF-8');

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
